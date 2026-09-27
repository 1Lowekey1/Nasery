<?php

namespace App\Services\Reviews;

use App\Enums\EventStatus;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\Notifications\AdminAlerts;
use App\Services\Notifications\Alerts;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * B16 reviews. Paper: "verified client reviews tied to bid reference
 * numbers, with administrator moderation authority"; "unsolicited public
 * reviews are not supported". So only the client whose accepted quotation
 * became the event may review it, once, after it's completed.
 *
 *  - edit or remove for 30 days (user); each earlier version is kept in
 *    review_edits and the public review shows "edited"
 *  - organization owners write one public reply (0038 shows it publicly)
 *  - anyone signed in (except the author) may report a review; a report
 *    alone never hides it. An admin may take the case (the review is then
 *    "disputed" and hidden until the decision) and keep or hide it (B17,
 *    ReviewModeration)
 *
 * Writes run in the system context after these checks.
 */
class ReviewManager
{
    public const EDIT_DAYS = 30;

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly Alerts $alerts,
        private readonly AdminAlerts $adminAlerts,
    ) {}

    /**
     * The client's view of reviewing this event.
     *
     * @return array{can_review: bool, reason: ?string, review: ?array<string, mixed>}
     */
    public function forClient(User $client, Event $event): array
    {
        $reason = $this->ineligible($client, $event);
        $review = $this->db->system('reviews.mine', fn () => DB::table('reviews')->where('event_id', $event->id)
            ->where('client_user_id', $client->id)->whereNull('deleted_at')->first());

        return [
            'can_review' => $reason === null && $review === null,
            'reason' => $review === null ? $reason : null,
            'review' => $review ? $this->find($review->id) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function create(User $client, Event $event, int $rating, ?string $text): array
    {
        if ($reason = $this->ineligible($client, $event)) {
            throw new ApiException(ErrorCode::BusinessRuleViolation, $reason, 409);
        }

        $id = $this->db->system('reviews.create', function () use ($client, $event, $rating, $text) {
            if (DB::table('reviews')->where('event_id', $event->id)->where('client_user_id', $client->id)->whereNull('deleted_at')->exists()) {
                throw new ApiException(ErrorCode::Conflict, 'You already reviewed this event. Edit your review instead.', 409);
            }
            $id = (string) Str::uuid7();
            DB::table('reviews')->insert([
                'id' => $id, 'event_id' => $event->id, 'organization_id' => $event->organization_id, 'client_user_id' => $client->id,
                'rating' => $rating, 'written_review' => $text, 'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });

        $this->audit->record('review.created', ['reviews', $id], new: ['event_id' => $event->id, 'rating' => $rating], organizationId: $event->organization_id);
        $this->alerts->reviewReceived($event, $rating);

        return $this->find($id);
    }

    /** @return array<string, mixed> */
    public function update(User $client, Event $event, int $rating, ?string $text): array
    {
        $review = $this->ownEditable($client, $event);

        $this->db->system('reviews.update', function () use ($review, $rating, $text) {
            DB::table('review_edits')->insert([
                'id' => (string) Str::uuid7(), 'review_id' => $review->id,
                'previous_rating' => $review->rating, 'previous_written_review' => $review->written_review, 'edited_at' => now(),
            ]);
            DB::table('reviews')->where('id', $review->id)->update(['rating' => $rating, 'written_review' => $text, 'updated_at' => now()]);
        });

        $this->audit->record('review.edited', ['reviews', $review->id], old: ['rating' => $review->rating], new: ['event_id' => $event->id, 'rating' => $rating],
            organizationId: $event->organization_id);

        return $this->find($review->id);
    }

    public function remove(User $client, Event $event): void
    {
        $review = $this->ownEditable($client, $event);
        $this->db->system('reviews.remove', fn () => DB::table('reviews')->where('id', $review->id)->update(['deleted_at' => now(), 'updated_at' => now()]));
        $this->audit->record('review.removed', ['reviews', $review->id], new: ['event_id' => $event->id], organizationId: $event->organization_id);
    }

    /** One public reply per review (0017), written or rewritten by an owner. */
    public function reply(Organization $org, OrganizationMember $by, string $reviewId, string $text): array
    {
        $review = $this->orgReview($org, $reviewId);

        $this->db->system('reviews.reply', function () use ($review, $by, $text) {
            $existing = DB::table('review_responses')->where('review_id', $review->id)->first();
            $existing
                ? DB::table('review_responses')->where('id', $existing->id)->update(['response_text' => $text, 'organization_member_id' => $by->id, 'deleted_at' => null, 'updated_at' => now()])
                : DB::table('review_responses')->insert(['id' => (string) Str::uuid7(), 'review_id' => $review->id, 'organization_member_id' => $by->id,
                    'response_text' => $text, 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->audit->record('review.replied', ['reviews', $review->id], new: ['event_id' => $review->event_id], organizationId: $org->id);
        $this->alerts->reviewReplied($review->client_user_id, (string) $org->display_name, $review->event_id);

        return $this->find($review->id);
    }

    public function removeReply(Organization $org, string $reviewId): void
    {
        $review = $this->orgReview($org, $reviewId);
        $this->db->system('reviews.reply_remove', fn () => DB::table('review_responses')->where('review_id', $review->id)->update(['deleted_at' => now(), 'updated_at' => now()]));
        $this->audit->record('review.reply_removed', ['reviews', $review->id], new: ['event_id' => $review->event_id], organizationId: $org->id);
    }

    /** Flag a review for the admin (B17). The review stays visible meanwhile. */
    public function report(User $by, string $reviewId, string $reason): void
    {
        $review = $this->db->system('reviews.report_find', fn () => DB::table('reviews')->where('id', $reviewId)->whereNull('deleted_at')->first());

        if ($review === null) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
        if ($review->client_user_id === $by->id) {
            throw new ApiException(ErrorCode::BusinessRuleViolation, 'You can edit or remove your own review instead of reporting it.', 409);
        }

        $this->db->system('reviews.report', function () use ($review, $by, $reason) {
            if (DB::table('review_reports')->where('review_id', $review->id)->where('reported_by', $by->id)->whereIn('status', ['open', 'in_review'])->exists()) {
                throw new ApiException(ErrorCode::Conflict, 'You already reported this review; an admin will look at it.', 409);
            }
            DB::table('review_reports')->insert(['id' => (string) Str::uuid7(), 'review_id' => $review->id, 'reported_by' => $by->id, 'reason' => $reason, 'status' => 'open', 'created_at' => now()]);
        });

        $this->audit->record('review.reported', ['reviews', $review->id], new: ['event_id' => $review->event_id], organizationId: $review->organization_id, reason: $reason);
        $this->adminAlerts->reviewReported($review->id, (string) $this->db->system('reviews.report_org', fn () => DB::table('organizations')->where('id', $review->organization_id)->value('display_name')));
    }

    /**
     * Everything the organization's team sees: all its reviews (any status),
     * replies, open reports, and the rating summary.
     *
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $org): array
    {
        return $this->db->system('reviews.org', function () use ($org) {
            $rows = $this->baseQuery()->where('r.organization_id', $org->id)->whereNull('r.deleted_at')->orderByDesc('r.created_at')->limit(200)->get();
            $published = $rows->where('status', 'published');

            return [
                'summary' => [
                    'average' => $published->isEmpty() ? null : round($published->avg('rating'), 2),
                    'count' => $published->count(),
                    'distribution' => collect(range(5, 1))->mapWithKeys(fn ($n) => [$n => $published->where('rating', $n)->count()]),
                    'awaiting_reply' => $published->whereNull('response_text')->count(),
                ],
                'reviews' => $rows->map(fn ($r) => $this->present($r, includeStatus: true, includeReports: true))->values(),
            ];
        });
    }

    private function ineligible(User $client, Event $event): ?string
    {
        $inquiryClient = $event->inquiry_id
            ? $this->db->system('reviews.eligible', fn () => DB::table('inquiries')->where('id', $event->inquiry_id)->value('client_user_id'))
            : null;

        return match (true) {
            $inquiryClient !== $client->id || $event->accepted_quotation_id === null => 'Only the client who booked this organizer through NaSeRy can review this event.',
            $event->status !== EventStatus::Completed => 'You can review the event once your organizer marks it completed.',
            default => null,
        };
    }

    private function ownEditable(User $client, Event $event): object
    {
        $review = $this->db->system('reviews.own', fn () => DB::table('reviews')->where('event_id', $event->id)->where('client_user_id', $client->id)->whereNull('deleted_at')->first());

        if ($review === null) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
        if (CarbonImmutable::parse($review->created_at)->addDays(self::EDIT_DAYS)->isPast()) {
            throw new ApiException(ErrorCode::BusinessRuleViolation, 'Reviews can be changed for '.self::EDIT_DAYS.' days after posting. Contact NaSeRy if something needs correcting.', 409);
        }

        return $review;
    }

    private function orgReview(Organization $org, string $reviewId): object
    {
        return $this->db->system('reviews.org_find', fn () => DB::table('reviews')->where('id', $reviewId)->where('organization_id', $org->id)->whereNull('deleted_at')->first())
            ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        return $this->db->system('reviews.find', fn () => $this->present($this->baseQuery()->where('r.id', $id)->first(), includeStatus: true));
    }

    private function baseQuery()
    {
        return DB::table('reviews as r')
            ->join('users as u', 'u.id', '=', 'r.client_user_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->leftJoin('event_types as et', 'et.id', '=', 'e.event_type_id')
            ->leftJoin('review_responses as rr', fn ($j) => $j->on('rr.review_id', '=', 'r.id')->whereNull('rr.deleted_at'))
            ->select(['r.id', 'r.event_id', 'r.rating', 'r.written_review', 'r.status', 'r.created_at', 'r.updated_at',
                'u.first_name', 'u.last_name', 'e.name as event_name', 'et.name as event_type', 'rr.response_text', 'rr.updated_at as responded_at',
                DB::raw('exists (select 1 from review_edits x where x.review_id = r.id) as is_edited'),
                DB::raw("(select count(*) from review_reports rp where rp.review_id = r.id and rp.status in ('open', 'in_review')) as open_reports")]);
    }

    /** @return array<string, mixed> */
    private function present(object $r, bool $includeStatus = false, bool $includeReports = false): array
    {
        $created = CarbonImmutable::parse($r->created_at);

        return [
            'id' => $r->id,
            'event_id' => $r->event_id,
            'event_name' => $r->event_name ?? null,
            'event_type' => $r->event_type ?? null,
            'rating' => (int) $r->rating,
            'written_review' => $r->written_review,
            'reviewer' => isset($r->first_name) ? trim($r->first_name.' '.mb_substr((string) $r->last_name, 0, 1).'.') : null,
            'created_at' => $created->toIso8601String(),
            'edited' => (bool) ($r->is_edited ?? false),
            'editable_until' => $created->addDays(self::EDIT_DAYS)->toIso8601String(),
            'reply' => ($r->response_text ?? null) ? ['text' => $r->response_text, 'at' => CarbonImmutable::parse($r->responded_at)->toIso8601String()] : null,
        ] + ($includeStatus ? ['status' => $r->status] : []) + ($includeReports ? ['open_reports' => (int) ($r->open_reports ?? 0)] : []);
    }
}
