<?php

namespace App\Services\Ticketing;

use App\Enums\AdmissionType;
use App\Enums\HoldStatus;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ApiException;
use App\Mail\EventEmailCodeMail;
use App\Models\Event;
use App\Models\QrTicket;
use App\Models\Registration;
use App\Models\TicketHold;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Notifications\Alerts;
use App\Services\Notifications\MailGuard;
use App\Services\Payments\Payments;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ticket checkout from the public registration page, for guests (no NaSeRy
 * account) and signed-in buyers alike.
 *
 * Guests confirm every attendee's e-mail with a 6-digit code first: tickets
 * are e-mailed there, so they must reach a real inbox. Codes and the tokens
 * they unlock live in the cache (short-lived, nothing to migrate). Signed-in
 * buyers skip the codes.
 *
 * The price always comes from the ticket type. Nobody registering chooses
 * "free" or "invited": complimentary guests are added by the organizer.
 *
 * A guest has no session, so the checkout returns an `access` key (an HMAC of
 * the registration id) that alone opens that registration's status and tickets.
 */
class GuestCheckout
{
    public const CODE_MINUTES = 10;

    public const TOKEN_MINUTES = 60;

    public const RESEND_SECONDS = 60;

    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly CapacityGuard $capacity,
        private readonly TicketIssuer $issuer,
        private readonly SelfRegistration $registration,
        private readonly Payments $payments,
        private readonly Alerts $alerts,
        private readonly MailGuard $guard,
        private readonly QrTokens $qr,
    ) {}

    /**
     * @return array{expires_in: int, resend_in: int}
     */
    public function sendCode(string $eventId, string $email): array
    {
        $event = $this->publicEvent($eventId);
        $email = $this->normalize($email);
        $key = $this->codeKey($eventId, $email);
        $current = Cache::get($key);

        if ($current !== null && now()->timestamp - $current['sent_at'] < self::RESEND_SECONDS) {
            throw new ApiException(ErrorCode::ResendCooldown, 'Please wait a minute before asking for another code.', 429,
                ['resend_in' => self::RESEND_SECONDS - (now()->timestamp - $current['sent_at'])]);
        }

        if (! $this->guard->allows($email)) {
            throw ValidationException::withMessages(['email' => 'We can\'t send e-mail to this address. Please use another one.']);
        }

        $code = (string) random_int(100000, 999999);
        Cache::put($key, ['hash' => hash('sha256', $code), 'attempts' => 0, 'sent_at' => now()->timestamp], now()->addMinutes(self::CODE_MINUTES));

        try {
            Mail::to($email)->send(new EventEmailCodeMail($code, $event->name, self::CODE_MINUTES));
        } catch (Throwable $e) {
            Cache::forget($key);
            Log::warning('guest_checkout.code_mail_failed', ['event' => $eventId, 'error' => $e->getMessage()]);

            throw new ApiException(ErrorCode::ServiceUnavailable, 'We couldn\'t send the code right now. Please try again.', 503);
        }

        return ['expires_in' => self::CODE_MINUTES * 60, 'resend_in' => self::RESEND_SECONDS];
    }

    /**
     * @return array{verified: true, email: string, verification_token: string}
     */
    public function verifyCode(string $eventId, string $email, #[SensitiveParameter] string $code): array
    {
        $this->publicEvent($eventId);
        $email = $this->normalize($email);
        $key = $this->codeKey($eventId, $email);
        $current = Cache::get($key);

        if ($current === null) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Ask for a new one.']);
        }

        if (! hash_equals($current['hash'], hash('sha256', trim($code)))) {
            $current['attempts']++;

            if ($current['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);

                throw ValidationException::withMessages(['code' => 'Too many wrong codes. Ask for a new one.']);
            }

            Cache::put($key, $current, now()->addMinutes(self::CODE_MINUTES));

            throw ValidationException::withMessages(['code' => 'That code isn\'t right. Check the e-mail and try again.']);
        }

        Cache::forget($key);
        $token = Str::random(48);
        Cache::put($this->tokenKey($token), ['event_id' => $eventId, 'email' => $email], now()->addMinutes(self::TOKEN_MINUTES));

        return ['verified' => true, 'email' => $email, 'verification_token' => $token];
    }

    /**
     * @param  list<array{full_name: string, email: ?string, ticket_type_id: string, verification_token?: ?string}>  $attendees
     * @param  array{name?: ?string, email?: ?string, phone?: ?string}  $buyer
     * @return array<string, mixed>
     */
    public function register(?User $user, string $eventId, array $attendees, array $buyer): array
    {
        $this->publicEvent($eventId);

        // Guests: every attendee's e-mail must be one they confirmed for this event.
        $tokens = [];
        if ($user === null) {
            foreach ($attendees as $i => $a) {
                $email = $this->normalize((string) ($a['email'] ?? ''));
                $claim = filled($a['verification_token'] ?? null) ? Cache::get($this->tokenKey($a['verification_token'])) : null;

                if ($email === '' || $claim === null || $claim['event_id'] !== $eventId || $claim['email'] !== $email) {
                    throw ValidationException::withMessages(["attendees.{$i}.email" => 'Confirm this e-mail address with the code we send it.']);
                }
                $tokens[] = $a['verification_token'];
            }
        }

        $result = $this->db->system('public.guest_register', function () use ($user, $eventId, $attendees, $buyer) {
            $byTier = collect($attendees)->groupBy('ticket_type_id');
            $publicTiers = DB::table('public_ticket_types')->where('event_id', $eventId)->whereIn('id', $byTier->keys())->get()->keyBy('id');
            $now = now();

            foreach ($byTier->keys() as $tierId) {
                $public = $publicTiers[$tierId] ?? throw new ApiException(ErrorCode::NotFound, 'This ticket is not available.', 404);

                if (($public->sales_start_at && CarbonImmutable::parse($public->sales_start_at)->isAfter($now))
                    || ($public->sales_end_at && CarbonImmutable::parse($public->sales_end_at)->isBefore($now))) {
                    throw new ApiException(ErrorCode::InvalidStateTransition, "Sales for {$public->name} are not open.", 409);
                }
            }

            $event = Event::query()->findOrFail($eventId);
            $tiers = TicketType::query()->whereIn('id', $byTier->keys())->get()->keyBy('id');
            $first = $attendees[0];

            $registration = Registration::query()->forceCreate([
                'id' => (string) Str::uuid7(),
                'event_id' => $event->id,
                'purchaser_user_id' => $user?->id,
                'purchaser_name' => $user ? trim($user->first_name.' '.$user->last_name) : (filled($buyer['name'] ?? null) ? trim($buyer['name']) : $first['full_name']),
                'purchaser_email' => $user?->email ?? $this->normalize((string) ($buyer['email'] ?? $first['email'])),
                'purchaser_contact' => $user?->phone ?? ($buyer['phone'] ?? null),
                'registration_source' => RegistrationSource::Online,
                'status' => RegistrationStatus::Pending,
            ]);

            $free = [];
            $paid = false;
            foreach ($byTier as $tierId => $people) {
                $tier = $tiers[$tierId];
                $people = $people->map(fn ($a) => ['full_name' => $a['full_name'], 'email' => $a['email'] ?? null])->values()->all();
                $this->capacity->reserve($event, $tier, count($people));

                if ($tier->isFree()) {
                    $free = [...$free, ...$this->issuer->issue($registration, $tier, $people, AdmissionType::Free)->pluck('id')->all()];

                    continue;
                }

                $paid = true;
                $this->issuer->issuePending($registration, $tier, $people);
                TicketHold::query()->forceCreate([
                    'id' => (string) Str::uuid7(),
                    'registration_id' => $registration->id,
                    'ticket_type_id' => $tier->id,
                    'event_id' => $event->id,
                    'quantity' => count($people),
                    'expires_at' => $now->copy()->addMinutes($this->registration->holdMinutes()),
                    'status' => HoldStatus::Active,
                ]);
            }

            if (! $paid) {
                $registration->forceFill(['status' => RegistrationStatus::Confirmed])->save();
            }

            return compact('registration', 'event', 'free', 'paid');
        });

        foreach ($tokens as $token) {
            Cache::forget($this->tokenKey($token)); // one registration per confirmation
        }

        $registration = $result['registration'];
        $this->audit->record('registration.created', $registration,
            new: ['event_id' => $eventId, 'quantity' => count($attendees), 'paid' => $result['paid'], 'guest' => $user === null],
            actorUserId: $user?->id, organizationId: $result['event']->organization_id);

        if ($result['free'] !== []) {
            $this->alerts->ticketsIssued($result['free'], $user?->id, $result['event']->name);
        }
        $this->alerts->capacity($result['event']);

        $access = $this->access($registration->id);
        $response = [
            'registration_id' => $registration->id,
            'access' => $access,
            'status' => $result['paid'] ? 'awaiting_payment' : 'confirmed',
            'checkout' => null,
        ];

        if ($result['paid']) {
            $success = rtrim((string) config('nasery.auth.frontend_url'), '/')."/event-registration/payment-success?registration={$registration->id}&access={$access}&payment=";
            $response['checkout'] = $this->payments->checkout($registration->id, fn (string $paymentId) => $success.$paymentId);
        }

        return $response;
    }

    /**
     * Status and tickets of one registration, for whoever holds its access key.
     *
     * @return array<string, mixed>
     */
    public function show(string $registrationId, string $access, ?string $paymentId): array
    {
        $this->authorize($registrationId, $access);

        if ($paymentId !== null) {
            $this->payments->refreshPending($registrationId, $paymentId);
        }

        return $this->db->system('public.guest_show', function () use ($registrationId) {
            $registration = Registration::query()->findOrFail($registrationId);
            $event = DB::table('events')->where('id', $registration->event_id)->first(['id', 'name', 'timezone', 'venue_name', 'venue_address']);
            $payment = DB::table('payments')->where('registration_id', $registrationId)->orderByDesc('created_at')->first(['id', 'status', 'amount', 'failure_reason']);
            $hold = DB::table('ticket_holds')->where('registration_id', $registrationId)->where('status', HoldStatus::Active->value)->max('expires_at');
            $tickets = DB::table('tickets as t')->join('attendees as a', 'a.id', '=', 't.attendee_id')->join('ticket_types as tt', 'tt.id', '=', 't.ticket_type_id')
                ->where('t.registration_id', $registrationId)->whereNull('t.deleted_at')->orderBy('t.id')
                ->get(['t.id', 't.status', 't.purchased_price', 'a.full_name', 'a.email', 'tt.name as tier']);
            $qrs = QrTicket::query()->whereIn('ticket_id', $tickets->pluck('id'))->where('status', 'active')->get()->keyBy('ticket_id');

            return [
                'registration_id' => $registration->id,
                'status' => $registration->status->value,
                'event' => ['id' => $event->id, 'name' => $event->name, 'venue' => $event->venue_name ?: $event->venue_address, 'timezone' => $event->timezone],
                'buyer' => ['name' => $registration->purchaser_name, 'email' => $registration->purchaser_email],
                'payment' => $payment ? ['id' => $payment->id, 'status' => $payment->status, 'amount' => $payment->amount, 'failure_reason' => $payment->failure_reason] : null,
                'hold_expires_at' => $hold,
                'tickets' => $tickets->map(fn ($t) => [
                    'id' => $t->id,
                    'status' => $t->status,
                    'attendee' => $t->full_name,
                    'email' => $t->email,
                    'tier' => $t->tier,
                    'price' => $t->purchased_price,
                    // The QR only once the ticket is valid (paid or free).
                    'qr_token' => $t->status === TicketStatus::Valid->value && isset($qrs[$t->id]) ? $this->qr->token($qrs[$t->id]) : null,
                ])->values(),
            ];
        });
    }

    /** Checks the key and that the ticket belongs to that registration. */
    public function ticketFor(string $registrationId, string $access, string $ticketId): void
    {
        $this->authorize($registrationId, $access);

        $ok = $this->db->system('public.guest_ticket', fn () => DB::table('tickets')->where('id', $ticketId)
            ->where('registration_id', $registrationId)->where('status', TicketStatus::Valid->value)->exists());

        if (! $ok) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
    }

    public function access(string $registrationId): string
    {
        return hash_hmac('sha256', 'guest-registration:'.$registrationId, (string) config('app.key'));
    }

    private function authorize(string $registrationId, string $access): void
    {
        if (! Str::isUuid($registrationId) || ! hash_equals($this->access($registrationId), $access)) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
    }

    private function publicEvent(string $eventId): object
    {
        return $this->db->system('public.guest_event', fn () => DB::table('public_events')->where('id', $eventId)->first(['id', 'name']))
            ?? throw new ApiException(ErrorCode::NotFound, 'This event isn\'t open to the public.', 404);
    }

    private function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    private function codeKey(string $eventId, string $email): string
    {
        return 'guest-checkout:code:'.$eventId.':'.hash('sha256', $email);
    }

    private function tokenKey(string $token): string
    {
        return 'guest-checkout:token:'.hash('sha256', $token);
    }
}
