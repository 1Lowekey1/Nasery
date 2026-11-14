<?php

namespace App\Services\Inquiries;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\Recipients;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Private text threads between a client and each organizer on their
 * inquiry (0042; paper: clients may talk to organizers before choosing a
 * bid, now inside NaSeRy). One thread per inquiry participant
 * (inquiry_organizations row), so the thread id is that row's id.
 *
 * Who: the inquiry's client, and organizer members who handle inquiries or
 * quotations. NaSeRy admins can't read threads.
 *
 * Open for sending while the inquiry is taking bids and this organizer is
 * still in (not declined / withdrawn / rejected); after a quotation is
 * accepted only the winner's thread stays open (into the event). Closed
 * threads stay readable.
 *
 * Writes run in the system context after these checks (the app role has
 * SELECT only). Messages are not audited: they are private conversation,
 * and the rows themselves are the record.
 */
class InquiryThreads
{
    public const MAX_LENGTH = 2000;

    private const HANDLERS = ['inquiry.manage', 'quotation.manage'];

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly OrganizationAccess $access,
        private readonly Recipients $recipients,
        private readonly Notifier $notifier,
    ) {}

    /**
     * The thread with its messages; marks it read for $user.
     *
     * @return array<string, mixed>
     */
    public function show(User $user, string $threadId): array
    {
        [$thread, $side] = $this->resolve($user, $threadId);

        $messages = $this->db->system('threads.messages', fn () => DB::table('inquiry_messages as m')->join('users as u', 'u.id', '=', 'm.sender_user_id')
            ->where('m.inquiry_organization_id', $thread->id)->orderBy('m.created_at')->limit(500)
            ->get(['m.id', 'm.sender_side', 'm.sender_user_id', 'm.body', 'm.created_at', 'u.first_name', 'u.last_name']));
        $this->markRead($user, $thread->id);

        return $this->presentThread($thread, $side, $user) + [
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'side' => $m->sender_side,
                'mine' => $m->sender_user_id === $user->id,
                'author' => $m->sender_side === 'client'
                    ? $this->clientName($m->first_name, $m->last_name)
                    : "{$thread->organization} · {$m->first_name}",
                'body' => $m->body,
                'at' => CarbonImmutable::parse($m->created_at)->toIso8601String(),
            ])->values(),
        ];
    }

    /** @return array<string, mixed> the new message */
    public function send(User $user, string $threadId, string $body): array
    {
        [$thread, $side] = $this->resolve($user, $threadId);
        $body = trim($body);

        if (! $this->open($thread)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This conversation is closed: the inquiry is no longer open with this organizer.', 409);
        }

        $id = (string) Str::uuid7();
        $now = now();
        $this->db->system('threads.send', fn () => DB::table('inquiry_messages')->insert([
            'id' => $id, 'inquiry_organization_id' => $thread->id, 'sender_user_id' => $user->id,
            'sender_side' => $side, 'body' => $body, 'created_at' => $now->format('Y-m-d H:i:s.uP'),
        ]));
        $this->markRead($user, $thread->id);

        // No flood: someone who still has an unread notice for this conversation isn't told again;
        // once they read it (bell or the thread itself), the next message notifies them.
        $preview = Str::limit($body, 140);
        if ($side === 'client') {
            $recipients = $this->withoutUnreadNotice($this->recipients->forOrganization($thread->organization_id, self::HANDLERS), $thread->id, 'message.organizer');
            $this->notifier->send($recipients, 'message.organizer', 'New message from '.$this->clientName($thread->client_first, $thread->client_last),
                'About '.$this->requestLabel($thread).": \"{$preview}\"", ['thread_id' => $thread->id, 'inquiry_id' => $thread->inquiry_id],
                "/organizer/inquiries/match-inquiries?thread={$thread->id}");
        } else {
            $this->notifier->send($this->withoutUnreadNotice([$thread->client_user_id], $thread->id, 'message.client'), 'message.client', "New message from {$thread->organization}",
                'About '.$this->requestLabel($thread).": \"{$preview}\"", ['thread_id' => $thread->id, 'inquiry_id' => $thread->inquiry_id],
                "/client/messages?thread={$thread->id}");
        }

        return ['id' => $id, 'side' => $side, 'mine' => true, 'body' => $body, 'at' => $now->toIso8601String()];
    }

    /**
     * The client's threads on one inquiry: one per organizer on it.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * All of a client's conversations, for their Messages page (mock defense: messages
     * moved off the request page). Newest activity first; drafts have none.
     *
     * @return list<array<string, mixed>>
     */
    public function forClient(User $client): array
    {
        $rows = $this->db->system('threads.client_all', fn () => $this->threads()->where('i.client_user_id', $client->id)
            ->orderByRaw('last_at desc nulls last')->orderByDesc('i.created_at')->orderBy('o.display_name')->get());

        return $rows->map(fn ($t) => $this->presentThread($t, 'client', $client))->values()->all();
    }

    public function forInquiry(User $client, string $inquiryId): array
    {
        $rows = $this->db->system('threads.client_list', fn () => $this->threads()->where('i.id', $inquiryId)->where('i.client_user_id', $client->id)
            ->orderByRaw('last_at desc nulls last')->orderBy('o.display_name')->get());
        if ($rows->isEmpty() && ! $this->db->system('threads.client_owns', fn () => DB::table('inquiries')->where('id', $inquiryId)->where('client_user_id', $client->id)->exists())) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        return $rows->map(fn ($t) => $this->presentThread($t, 'client', $client))->values()->all();
    }

    /**
     * The organization's threads (optionally one inquiry's), latest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrganization(User $user, Organization $org, ?string $inquiryId): array
    {
        if (! collect(self::HANDLERS)->contains(fn ($p) => $this->access->can($user, $org, $p))) {
            throw new ApiException(ErrorCode::Forbidden, 'Messages are for members who handle inquiries or quotations.', 403);
        }

        return $this->db->system('threads.org_list', fn () => $this->threads()->where('io.organization_id', $org->id)
            ->when($inquiryId, fn ($q) => $q->where('io.inquiry_id', $inquiryId), fn ($q) => $q->whereNotNull('lm.created_at'))
            ->orderByRaw('last_at desc nulls last')->limit(100)->get())
            ->map(fn ($t) => $this->presentThread($t, 'organizer', $user))->values()->all();
    }

    /** @return array{0: object, 1: 'client'|'organizer'} */
    private function resolve(User $user, string $threadId): array
    {
        $thread = $this->db->system('threads.find', fn () => $this->threads()->where('io.id', $threadId)->first());
        if ($thread !== null) {
            if ($thread->client_user_id === $user->id) {
                return [$thread, 'client'];
            }
            if (collect(self::HANDLERS)->contains(fn ($p) => $this->access->can($user, $thread->organization_id, $p))) {
                return [$thread, 'organizer'];
            }
        }

        throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
    }

    private function open(object $t): bool
    {
        return match ($t->inquiry_status) {
            'submitted', 'receiving_bids' => ! in_array($t->participant_status, ['declined', 'withdrawn', 'rejected'], true),
            'accepted', 'converted_to_event' => $t->participant_status === 'accepted',
            default => false,
        };
    }

    private function markRead(User $user, string $threadId): void
    {
        $this->db->system('threads.read', function () use ($user, $threadId) {
            DB::table('inquiry_thread_reads')->upsert(
                [['inquiry_organization_id' => $threadId, 'user_id' => $user->id, 'last_read_at' => now()->format('Y-m-d H:i:s.uP')]],
                ['inquiry_organization_id', 'user_id'], ['last_read_at'],
            );
            // Reading the conversation also clears its notices in the bell.
            DB::table('notifications')->where('recipient_user_id', $user->id)->whereIn('type', ['message.client', 'message.organizer'])
                ->where('data->thread_id', $threadId)->whereNull('read_at')->update(['read_at' => now()]);
        });
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string> those without an unread notice for this thread
     */
    private function withoutUnreadNotice(array $userIds, string $threadId, string $type): array
    {
        $waiting = $this->db->system('threads.pending_notice', fn () => DB::table('notifications')->whereIn('recipient_user_id', $userIds)
            ->where('type', $type)->where('data->thread_id', $threadId)->whereNull('read_at')->pluck('recipient_user_id')->all());

        return array_values(array_diff($userIds, $waiting));
    }

    /** Which request a message is about: its event name, else the kind of event (mock defense). */
    private function requestLabel(object $thread): string
    {
        $name = (new \App\Models\Inquiry)->forceFill(['additional_requirements' => $thread->additional_requirements ?? null])->eventName();

        return $name ? "“{$name}”" : "your {$thread->event_type} request";
    }

    private function threads()
    {
        $last = DB::table('inquiry_messages')->selectRaw('distinct on (inquiry_organization_id) inquiry_organization_id, body, sender_side, created_at')
            ->orderBy('inquiry_organization_id')->orderByDesc('created_at');

        return DB::table('inquiry_organizations as io')
            ->join('inquiries as i', 'i.id', '=', 'io.inquiry_id')
            ->join('organizations as o', 'o.id', '=', 'io.organization_id')
            ->join('users as c', 'c.id', '=', 'i.client_user_id')
            ->leftJoin('event_types as et', 'et.id', '=', 'i.event_type_id')
            ->leftJoinSub($last, 'lm', 'lm.inquiry_organization_id', '=', 'io.id')
            ->select(['io.id', 'io.inquiry_id', 'io.organization_id', 'io.status as participant_status', 'i.status as inquiry_status', 'i.client_user_id',
                'i.requested_date', 'o.display_name as organization', 'o.slug as organization_slug', 'c.first_name as client_first', 'c.last_name as client_last',
                DB::raw("coalesce(et.name, 'event') as event_type"), 'i.additional_requirements', 'lm.body as last_body', 'lm.sender_side as last_side', 'lm.created_at as last_at']);
    }

    /** @return array<string, mixed> */
    private function presentThread(object $t, string $side, User $user): array
    {
        $readAt = $this->db->system('threads.read_at', fn () => DB::table('inquiry_thread_reads')->where('inquiry_organization_id', $t->id)->where('user_id', $user->id)->value('last_read_at'));
        $other = $side === 'client' ? 'organizer' : 'client';
        $unread = $this->db->system('threads.unread', fn () => DB::table('inquiry_messages')->where('inquiry_organization_id', $t->id)->where('sender_side', $other)
            ->when($readAt, fn ($q) => $q->where('created_at', '>', $readAt))->count());

        return [
            'id' => $t->id,
            'inquiry_id' => $t->inquiry_id,
            'event_type' => $t->event_type,
            // The client's own name for the event ("Event: …" line), when they gave one.
            'event_name' => (new \App\Models\Inquiry)->forceFill(['additional_requirements' => $t->additional_requirements ?? null])->eventName(),
            'requested_date' => $t->requested_date,
            'organization' => ['id' => $t->organization_id, 'name' => $t->organization, 'slug' => $t->organization_slug],
            'client' => $this->clientName($t->client_first, $t->client_last),
            'participant_status' => $t->participant_status,
            'can_send' => $this->open($t),
            'last' => $t->last_at ? ['body' => Str::limit($t->last_body, 120), 'side' => $t->last_side, 'at' => CarbonImmutable::parse($t->last_at)->toIso8601String()] : null,
            'unread' => $unread,
            'side' => $side,
        ];
    }

    /** First name + last initial, like reviews (B16): organizers see contact details only after a booking (B5). */
    private function clientName(?string $first, ?string $last): string
    {
        return trim(($first ?? 'Client').' '.mb_substr((string) $last, 0, 1).'.');
    }
}
