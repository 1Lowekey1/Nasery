<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Notifications\AdminAlerts;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin moderation of reported reviews (B17; paper: "administrator
 * moderation authority"). A report alone never changes a review. Then:
 *
 *   published --start--> disputed (hidden while an admin looks into it,
 *                                  team decision 2026-09-25)
 *   published/disputed --keep--> published (reports dismissed)
 *   published/disputed --hide--> hidden    (reports resolved)
 *   hidden --restore--> published
 *
 * public_reviews shows published reviews only, so disputed and hidden
 * reviews leave the profile and the rating. Every step is a moderation
 * case (0018), audited, and tells the client, the organizer and, at the
 * decision, the people who reported it. Reviews and review_reports have no
 * admin RLS policy, so the desk works in the system context after the
 * account:admin route check.
 */
class ReviewModeration
{
    public const QUEUES = ['open', 'in_review', 'hidden', 'closed'];

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly AdminAlerts $alerts,
    ) {}

    /** @return array<string, mixed> */
    public function queue(string $queue): array
    {
        return $this->db->system('moderation.queue', function () use ($queue) {
            $q = $this->reviews();
            match ($queue) {
                'open' => $q->where('r.status', 'published')->whereExists(fn ($s) => $s->from('review_reports as rp')->whereColumn('rp.review_id', 'r.id')->where('rp.status', 'open')),
                'in_review' => $q->where('r.status', 'disputed'),
                'hidden' => $q->where('r.status', 'hidden'),
                'closed' => $q->where('r.status', 'published')->whereExists(fn ($s) => $s->from('review_reports as rp')->whereColumn('rp.review_id', 'r.id')->whereIn('rp.status', ['resolved', 'dismissed']))
                    ->whereNotExists(fn ($s) => $s->from('review_reports as rp')->whereColumn('rp.review_id', 'r.id')->whereIn('rp.status', ['open', 'in_review'])),
            };
            $rows = $q->orderByDesc('r.updated_at')->limit(100)->get();
            $reports = DB::table('review_reports as rp')->join('users as u', 'u.id', '=', 'rp.reported_by')
                ->whereIn('rp.review_id', $rows->pluck('id'))->orderBy('rp.created_at')
                ->get(['rp.*', 'u.first_name', 'u.last_name', 'u.account_type'])->groupBy('review_id');
            $cases = DB::table('moderation_cases as c')->leftJoin('users as u', 'u.id', '=', 'c.opened_by')
                ->where('c.subject_type', 'review')->whereIn('c.subject_id', $rows->pluck('id'))->orderByDesc('c.created_at')->orderByDesc('c.id')
                ->get(['c.*', 'u.first_name', 'u.last_name'])->groupBy('subject_id');

            return [
                'counts' => $this->counts(),
                'items' => $rows->map(fn ($r) => $this->present($r, $reports->get($r->id, collect()), $cases->get($r->id, collect())))->values(),
            ];
        });
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->db->system('moderation.counts', fn () => [
            'open' => DB::table('reviews as r')->whereNull('r.deleted_at')->where('r.status', 'published')
                ->whereExists(fn ($s) => $s->from('review_reports as rp')->whereColumn('rp.review_id', 'r.id')->where('rp.status', 'open'))->count(),
            'in_review' => DB::table('reviews')->whereNull('deleted_at')->where('status', 'disputed')->count(),
            'hidden' => DB::table('reviews')->whereNull('deleted_at')->where('status', 'hidden')->count(),
        ]);
    }

    /** Take the case: the review is hidden (disputed) until a decision. */
    public function start(User $admin, string $reviewId, ?string $note): void
    {
        $review = $this->review($reviewId);
        if ($review->status !== 'published' || ! $this->hasOpenReports($review->id)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only a published review with open reports can be taken for review.', 409);
        }

        $this->db->system('moderation.start', function () use ($review, $admin, $note) {
            DB::table('reviews')->where('id', $review->id)->update(['status' => 'disputed', 'updated_at' => now()]);
            DB::table('review_reports')->where('review_id', $review->id)->where('status', 'open')->update(['status' => 'in_review']);
            DB::table('moderation_cases')->insert(['id' => (string) Str::uuid7(), 'subject_type' => 'review', 'subject_id' => $review->id,
                'opened_by' => $admin->id, 'status' => 'in_review', 'resolution' => $note, 'created_at' => now()]);
        });

        $this->audit->record('review.moderation_started', ['reviews', $review->id], old: ['status' => 'published'], new: ['status' => 'disputed'], organizationId: $review->organization_id, reason: $note);
        $this->alerts->reviewModerated($review, 'reviewing', null, []);
    }

    /** @param  'keep'|'hide'  $decision */
    public function decide(User $admin, string $reviewId, string $decision, string $reason): void
    {
        $review = $this->review($reviewId);
        if (! in_array($review->status, ['published', 'disputed'], true) || ($review->status === 'published' && ! $this->hasOpenReports($review->id))) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'There is no open report on this review to decide.', 409);
        }
        $to = $decision === 'hide' ? 'hidden' : 'published';

        $reporters = $this->db->system('moderation.decide', function () use ($review, $admin, $decision, $reason, $to) {
            $reporters = DB::table('review_reports')->where('review_id', $review->id)->whereIn('status', ['open', 'in_review'])->pluck('reported_by')->unique()->values()->all();
            DB::table('reviews')->where('id', $review->id)->update(['status' => $to, 'updated_at' => now()]);
            DB::table('review_reports')->where('review_id', $review->id)->whereIn('status', ['open', 'in_review'])
                ->update(['status' => $decision === 'hide' ? 'resolved' : 'dismissed', 'resolved_by' => $admin->id, 'resolved_at' => now()]);
            $this->closeCase($review->id, $admin, $decision === 'hide' ? 'hidden' : 'kept', $reason);

            return $reporters;
        });

        $this->audit->record($decision === 'hide' ? 'review.hidden' : 'review.kept', ['reviews', $review->id],
            old: ['status' => $review->status], new: ['status' => $to], organizationId: $review->organization_id, reason: $reason);
        $this->alerts->reviewModerated($review, $decision === 'hide' ? 'hidden' : 'kept', $reason, $reporters);
    }

    public function restore(User $admin, string $reviewId, string $reason): void
    {
        $review = $this->review($reviewId);
        if ($review->status !== 'hidden') {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only a hidden review can be restored.', 409);
        }

        $this->db->system('moderation.restore', function () use ($review, $admin, $reason) {
            DB::table('reviews')->where('id', $review->id)->update(['status' => 'published', 'updated_at' => now()]);
            DB::table('moderation_cases')->insert(['id' => (string) Str::uuid7(), 'subject_type' => 'review', 'subject_id' => $review->id,
                'opened_by' => $admin->id, 'status' => 'resolved', 'resolution' => $reason, 'action_taken' => 'restored', 'resolved_at' => now(), 'created_at' => now()]);
        });

        $this->audit->record('review.restored', ['reviews', $review->id], old: ['status' => 'hidden'], new: ['status' => 'published'], organizationId: $review->organization_id, reason: $reason);
        $this->alerts->reviewModerated($review, 'restored', $reason, []);
    }

    private function closeCase(string $reviewId, User $admin, string $action, string $reason): void
    {
        $case = DB::table('moderation_cases')->where('subject_type', 'review')->where('subject_id', $reviewId)
            ->whereIn('status', ['open', 'in_review'])->orderByDesc('created_at')->orderByDesc('id')->first();
        $values = ['status' => $action === 'kept' ? 'dismissed' : 'resolved', 'resolution' => $reason, 'action_taken' => $action, 'resolved_at' => now()];

        $case
            ? DB::table('moderation_cases')->where('id', $case->id)->update($values)
            : DB::table('moderation_cases')->insert($values + ['id' => (string) Str::uuid7(), 'subject_type' => 'review', 'subject_id' => $reviewId, 'opened_by' => $admin->id, 'created_at' => now()]);
    }

    private function hasOpenReports(string $reviewId): bool
    {
        return $this->db->system('moderation.has_reports', fn () => DB::table('review_reports')->where('review_id', $reviewId)->where('status', 'open')->exists());
    }

    private function review(string $id): object
    {
        return $this->db->system('moderation.review', fn () => DB::table('reviews as r')->join('organizations as o', 'o.id', '=', 'r.organization_id')
            ->where('r.id', $id)->whereNull('r.deleted_at')->lockForUpdate()->first(['r.*', 'o.display_name as organizer']))
            ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
    }

    private function reviews()
    {
        return DB::table('reviews as r')
            ->join('users as u', 'u.id', '=', 'r.client_user_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('organizations as o', 'o.id', '=', 'r.organization_id')
            ->leftJoin('review_responses as rr', fn ($j) => $j->on('rr.review_id', '=', 'r.id')->whereNull('rr.deleted_at'))
            ->whereNull('r.deleted_at')
            ->select(['r.id', 'r.event_id', 'r.organization_id', 'r.client_user_id', 'r.rating', 'r.written_review', 'r.status', 'r.created_at', 'r.updated_at',
                'u.first_name', 'u.last_name', 'e.name as event_name', 'o.display_name as organizer', 'o.slug as organizer_slug', 'rr.response_text']);
    }

    /** @return array<string, mixed> */
    private function present(object $r, $reports, $cases): array
    {
        $iso = fn ($t) => $t ? CarbonImmutable::parse($t)->toIso8601String() : null;

        return [
            'id' => $r->id,
            'status' => $r->status,
            'rating' => (int) $r->rating,
            'written_review' => $r->written_review,
            'reviewer' => trim($r->first_name.' '.mb_substr((string) $r->last_name, 0, 1).'.'),
            'event_name' => $r->event_name,
            'organizer' => ['id' => $r->organization_id, 'name' => $r->organizer, 'slug' => $r->organizer_slug],
            'reply' => $r->response_text,
            'created_at' => $iso($r->created_at),
            'reports' => $reports->map(fn ($p) => [
                'reason' => $p->reason,
                'status' => $p->status,
                'by' => trim($p->first_name.' '.mb_substr((string) $p->last_name, 0, 1).'.'),
                'by_role' => $p->account_type === 'business' ? 'organizer' : $p->account_type,
                'at' => $iso($p->created_at),
            ])->values(),
            'history' => $cases->map(fn ($c) => [
                'status' => $c->status,
                'action' => $c->action_taken,
                'note' => $c->resolution,
                'by' => $c->first_name ? trim("{$c->first_name} {$c->last_name}") : null,
                'at' => $iso($c->resolved_at ?? $c->created_at),
            ])->values(),
        ];
    }
}
