<?php

namespace App\Services\Inquiries;

use App\Enums\InquiryOrgStatus;
use App\Enums\InquiryStatus;
use App\Exceptions\ApiException;
use App\Models\Inquiry;
use App\Models\InquiryInvitation;
use App\Models\InquiryService;
use App\Models\Service;
use App\Models\User;
use App\Services\Quotations\QuotationWorkflow;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\Notifications\Alerts;

/**
 * The client side of inquiries (B4). Inquiry rows are written under RLS as
 * the client (inquiries_client_owner). Invitations and edits of requested
 * services have no RLS path for clients, so those run in the system context
 * after this class has checked ownership and state.
 */
class InquiryManager
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly InquiryMatcher $matcher,
        private readonly QuotationWorkflow $quotations,
        private readonly Alerts $alerts,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated fields + optional services[]
     */
    public function createDraft(User $client, array $data): Inquiry
    {
        $this->assertEventType($data['event_type_id'] ?? null);

        $inquiry = new Inquiry(collect($data)->except('services')->all());
        $inquiry->forceFill(['client_user_id' => $client->id])->save();

        $this->replaceServices($inquiry, $data['services'] ?? []);
        $this->audit->record('inquiry.created', $inquiry, new: collect($data)->except('services')->all());

        return $inquiry->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(Inquiry $inquiry, array $data): Inquiry
    {
        $this->assertStatus($inquiry, [InquiryStatus::Draft], 'Only a draft inquiry can be edited.');

        if (array_key_exists('event_type_id', $data)) {
            $this->assertEventType($data['event_type_id']);
        }

        $fields = collect($data)->except('services')->all();
        $old = $inquiry->only(array_keys($fields));
        $inquiry->fill($fields)->save();

        if (array_key_exists('services', $data)) {
            $this->replaceServices($inquiry, $data['services'] ?? []);
        }

        $this->audit->record('inquiry.updated', $inquiry, old: $old, new: $fields);

        return $inquiry->refresh();
    }

    /**
     * Send a draft. Direct: invite the chosen listed organizers. Open posting:
     * store the range and invite every listed organizer that matches.
     *
     * @param  array{mode: string, organization_ids?: list<string>, match_radius_km?: float|null}  $data
     */
    public function submit(Inquiry $inquiry, array $data): Inquiry
    {
        $this->lock($inquiry);
        $this->assertStatus($inquiry, [InquiryStatus::Draft], 'This inquiry has already been sent.');
        $this->assertComplete($inquiry, $data['mode']);

        if ($data['mode'] === 'open') {
            if ($inquiry->latitude === null) {
                throw ValidationException::withMessages(['latitude' => 'Pin the event location on the map to post to organizers nearby.']);
            }

            $inquiry->fill(['match_radius_km' => $data['match_radius_km']])->save();
            $targets = $this->matcher->matches($inquiry)->pluck('organization_id');

            if ($targets->isEmpty()) {
                throw new ApiException(ErrorCode::NoMatchingOrganizers, 'No listed organizers match these services within that distance yet. Widen the range or invite organizers directly.', 422);
            }
        } else {
            $requested = collect($data['organization_ids'] ?? [])->unique()->values();
            $targets = $this->matcher->listed($requested->all());

            if ($targets->count() !== $requested->count()) {
                throw ValidationException::withMessages(['organization_ids' => 'One or more organizers are not available on NaSeRy.']);
            }
        }

        $this->invite($inquiry, $targets);
        $inquiry->forceFill(['status' => InquiryStatus::Submitted])->save();

        $this->audit->record('inquiry.submitted', $inquiry, new: [
            'mode' => $data['mode'],
            'match_radius_km' => $data['match_radius_km'] ?? null,
            'invited' => $targets->count(),
        ]);

        return $inquiry->refresh();
    }

    /**
     * Invite more organizers to an open inquiry (direct additions).
     *
     * @param  list<string>  $organizationIds
     */
    public function inviteMore(Inquiry $inquiry, array $organizationIds): int
    {
        $this->lock($inquiry);
        $this->assertStatus($inquiry, Inquiry::OPEN_STATUSES, 'Organizers can only be added while the inquiry is open.');

        $existing = $this->db->system('inquiry.invitations', fn () => InquiryInvitation::query()->where('inquiry_id', $inquiry->id)->pluck('organization_id'));
        $new = collect($organizationIds)->unique()->diff($existing)->values();

        if ($existing->count() + $new->count() > InquiryMatcher::MAX_OPEN_POSTING_INVITES) {
            throw ValidationException::withMessages(['organization_ids' => 'Too many organizers for one inquiry.']);
        }

        $listed = $this->matcher->listed($new->all());

        if ($listed->count() !== $new->count()) {
            throw ValidationException::withMessages(['organization_ids' => 'One or more organizers are not available on NaSeRy.']);
        }

        $this->invite($inquiry, $listed);
        $this->audit->record('inquiry.invited_more', $inquiry, new: ['organization_ids' => $listed->all()]);

        return $listed->count();
    }

    public function cancel(Inquiry $inquiry, ?string $reason): Inquiry
    {
        $this->lock($inquiry);
        $this->assertStatus($inquiry, [InquiryStatus::Draft, ...Inquiry::OPEN_STATUSES], 'This inquiry can no longer be cancelled.');

        $old = $inquiry->status->value;
        $inquiry->forceFill(['status' => InquiryStatus::Cancelled])->save();
        $this->quotations->cancelActive($inquiry, 'Inquiry cancelled by the client');
        $this->audit->record('inquiry.cancelled', $inquiry, old: ['status' => $old], new: ['status' => 'cancelled'], reason: $reason);

        return $inquiry;
    }

    public function deleteDraft(Inquiry $inquiry): void
    {
        $this->assertStatus($inquiry, [InquiryStatus::Draft], 'Only drafts can be deleted; cancel a sent inquiry instead.');

        $inquiry->delete();
        $this->audit->record('inquiry.draft_deleted', $inquiry);
    }

    /**
     * @param  Collection<int, string>  $organizationIds
     */
    private function invite(Inquiry $inquiry, Collection $organizationIds): void
    {
        if ($organizationIds->isEmpty()) {
            return;
        }

        $now = now();
        $this->db->system('inquiry.invite', fn () => DB::table('inquiry_organizations')->insertOrIgnore(
            $organizationIds->map(fn (string $orgId) => [
                'id' => (string) Str::uuid7(),
                'inquiry_id' => $inquiry->id,
                'organization_id' => $orgId,
                'status' => InquiryOrgStatus::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
        ));

        $this->alerts->inquiryReceived($inquiry, $organizationIds);
    }

    /**
     * @param  list<array{service_id?: ?string, custom_service_name?: ?string, notes?: ?string}>  $services
     */
    private function replaceServices(Inquiry $inquiry, array $services): void
    {
        $catalogIds = collect($services)->pluck('service_id')->filter()->unique()->values();

        if ($catalogIds->isNotEmpty()) {
            $known = $this->db->system('services.catalog', fn () => Service::query()->whereIn('id', $catalogIds)->count());

            if ($known !== $catalogIds->count()) {
                throw ValidationException::withMessages(['services' => 'One or more catalog services do not exist.']);
            }
        }

        // Clients may INSERT requested services under RLS but not delete them,
        // so replacing the list is a system write (ownership checked by caller).
        $this->db->system('inquiry.services', function () use ($inquiry, $services) {
            DB::table('inquiry_services')->where('inquiry_id', $inquiry->id)->delete();

            foreach ($services as $service) {
                InquiryService::query()->create([
                    'inquiry_id' => $inquiry->id,
                    'service_id' => $service['service_id'] ?? null,
                    'custom_service_name' => isset($service['custom_service_name']) ? trim($service['custom_service_name']) : null,
                    'notes' => $service['notes'] ?? null,
                ]);
            }
        });
    }

    private function assertEventType(?string $eventTypeId): void
    {
        if ($eventTypeId === null) {
            return;
        }

        // Clients choose from NaSeRy's standard event types.
        $ok = $this->db->system('inquiry.event_type', fn () => DB::table('public_event_types')
            ->where('id', $eventTypeId)->whereNull('organization_id')->exists());

        if (! $ok) {
            throw ValidationException::withMessages(['event_type_id' => 'Choose one of the listed event types.']);
        }
    }

    private function assertComplete(Inquiry $inquiry, string $mode): void
    {
        $missing = [];

        foreach (['event_type_id' => 'Choose an event type.', 'requested_date' => 'Pick the event date.', 'guest_count' => 'Add the expected number of guests.', 'location_text' => 'Add where the event will be.'] as $field => $message) {
            if (blank($inquiry->{$field})) {
                $missing[$field] = $message;
            }
        }

        if ($inquiry->requested_date !== null && $inquiry->requested_date->isPast() && ! $inquiry->requested_date->isToday()) {
            $missing['requested_date'] = 'The event date has already passed.';
        }

        if ($mode === 'open' && ! $inquiry->services()->exists()) {
            $missing['services'] = 'List at least one service so NaSeRy can find matching organizers.';
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /**
     * @param  list<InquiryStatus>  $allowed
     */
    private function assertStatus(Inquiry $inquiry, array $allowed, string $message): void
    {
        if (! in_array($inquiry->status, $allowed, true)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, $message, 409);
        }
    }

    /** Serialize concurrent actions on one inquiry (owner row, RLS allows FOR UPDATE). */
    private function lock(Inquiry $inquiry): void
    {
        $status = Inquiry::query()->whereKey($inquiry->id)->lockForUpdate()->value('status');
        $inquiry->setRawAttributes(array_merge($inquiry->getAttributes(), ['status' => $status]), true);
    }
}
