<?php

namespace App\Http\Controllers\Api\V1\Inquiries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inquiries\InquiryRequest;
use App\Http\Requests\Inquiries\SubmitInquiryRequest;
use App\Models\Inquiry;
use App\Services\Inquiries\InquiryManager;
use App\Services\Inquiries\InquiryMatcher;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A client's own inquiries. {inquiry} binds under RLS, so another client's
 * inquiry is a 404 (inquiries_client_owner).
 */
class ClientInquiryController extends Controller
{
    public function __construct(
        private readonly InquiryManager $manager,
        private readonly InquiryMatcher $matcher,
        private readonly DatabaseContext $db,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string', 'max:30']]);

        $page = Inquiry::query()
            ->where('client_user_id', $request->user()->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->withCount([
                'invitations',
                'invitations as viewed_count' => fn ($q) => $q->whereIn('status', ['viewed', 'quoted', 'accepted']),
                'invitations as declined_count' => fn ($q) => $q->where('status', 'declined'),
            ])
            ->orderByDesc('created_at')
            ->paginate(15);

        $types = $this->eventTypeNames($page->getCollection());
        $page->getCollection()->transform(fn (Inquiry $i) => $this->summary($i, $types));

        return ApiResponse::success($page);
    }

    public function store(InquiryRequest $request): JsonResponse
    {
        $inquiry = $this->manager->createDraft($request->user(), $request->validated());

        return ApiResponse::created($this->detail($inquiry), 'Draft saved.');
    }

    public function show(Inquiry $inquiry): JsonResponse
    {
        return ApiResponse::success($this->detail($inquiry));
    }

    public function update(InquiryRequest $request, Inquiry $inquiry): JsonResponse
    {
        // A draft is edited freely; a sent, open request is revised (0051).
        if ($inquiry->status->value === 'draft') {
            return ApiResponse::success($this->detail($this->manager->updateDraft($inquiry, $request->validated())), 'Draft saved.');
        }

        return ApiResponse::success($this->detail($this->manager->revise($inquiry, $request->validated())),
            'Request updated. The organizers were told what changed.');
    }

    public function destroy(Inquiry $inquiry): JsonResponse
    {
        $this->manager->deleteDraft($inquiry);

        return ApiResponse::success(null, 'Draft deleted.');
    }

    /** Organizers an open posting would reach right now (for the preview). */
    public function matches(Request $request, Inquiry $inquiry): JsonResponse
    {
        $data = $request->validate(['match_radius_km' => ['required', 'numeric', 'min:1', 'max:500']]);
        $inquiry->match_radius_km = $data['match_radius_km']; // preview only, not saved

        $matches = $this->matcher->matches($inquiry);

        return ApiResponse::success($matches->map(fn ($m) => [
            'organization_id' => $m->organization_id,
            'slug' => $m->slug,
            'display_name' => $m->display_name,
            'distance_km' => round((float) $m->distance_km, 1),
        ])->values(), meta: ['count' => $matches->count()]);
    }

    public function submit(SubmitInquiryRequest $request, Inquiry $inquiry): JsonResponse
    {
        $inquiry = $this->manager->submit($inquiry, $request->validated());

        return ApiResponse::success($this->detail($inquiry), 'Inquiry sent to organizers.');
    }

    public function invite(Request $request, Inquiry $inquiry): JsonResponse
    {
        $data = $request->validate([
            'organization_ids' => ['required', 'array', 'min:1', 'max:'.InquiryMatcher::MAX_DIRECT_INVITES],
            'organization_ids.*' => ['uuid', 'distinct'],
        ]);

        $added = $this->manager->inviteMore($inquiry, $data['organization_ids']);

        return ApiResponse::success($this->detail($inquiry->refresh()), "{$added} organizer(s) invited.");
    }

    public function cancel(Request $request, Inquiry $inquiry): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::success($this->detail($this->manager->cancel($inquiry, $data['reason'] ?? null)), 'Inquiry cancelled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Inquiry $inquiry): array
    {
        $invitations = $inquiry->invitations()->orderBy('created_at')->get();

        // Invited organizers are listed ones; names come from the public view.
        $names = $invitations->isEmpty() ? collect() : $this->db->system('inquiry.invitee_names', fn () => DB::table('public_organization_profiles')
            ->whereIn('organization_id', $invitations->pluck('organization_id'))->get(['organization_id', 'display_name', 'slug'])->keyBy('organization_id'));

        return [
            ...$this->summary($inquiry, $this->eventTypeNames(collect([$inquiry]))),
            'latitude' => $inquiry->latitude !== null ? (float) $inquiry->latitude : null,
            'longitude' => $inquiry->longitude !== null ? (float) $inquiry->longitude : null,
            'match_radius_km' => $inquiry->match_radius_km !== null ? (float) $inquiry->match_radius_km : null,
            'additional_requirements' => $inquiry->additional_requirements,
            // 0051: changes after sending (capped).
            'revised_at' => $inquiry->revised_at?->toIso8601String(),
            'revisions_left' => max(0, Inquiry::MAX_REVISIONS - $inquiry->revision_count),
            // The event the accepted quotation became (for "See your event").
            'event_id' => $inquiry->status->value === 'converted_to_event'
                ? $this->db->system('inquiry.event_id', fn () => DB::table('events')->where('inquiry_id', $inquiry->id)->whereNull('deleted_at')->value('id'))
                : null,
            'services' => $inquiry->services()->get(['id', 'service_id', 'custom_service_name', 'notes'])->map(fn ($s) => [
                'id' => $s->id, 'service_id' => $s->service_id, 'custom_service_name' => $s->custom_service_name, 'notes' => $s->notes,
            ]),
            'invitations' => $invitations->map(fn ($i) => [
                'organization_id' => $i->organization_id,
                'display_name' => $names[$i->organization_id]->display_name ?? 'Organizer no longer listed',
                'slug' => $names[$i->organization_id]->slug ?? null,
                'status' => $i->status->value,
                'decline_reason' => $i->decline_reason,
                'invited_at' => $i->created_at?->toIso8601String(),
                'responded_at' => $i->responded_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Inquiry $inquiry, $types): array
    {
        return [
            'id' => $inquiry->id,
            'status' => $inquiry->status->value,
            'via' => $inquiry->isOpenPosting() ? 'open_posting' : ($inquiry->status->value === 'draft' ? null : 'direct'),
            'event_type_id' => $inquiry->event_type_id,
            'event_type' => $types[$inquiry->event_type_id] ?? null,
            'requested_date' => $inquiry->requested_date?->toDateString(),
            'guest_count' => $inquiry->guest_count,
            'budget_min' => $inquiry->budget_min,
            'budget_max' => $inquiry->budget_max,
            'location_text' => $inquiry->location_text,
            'invited_count' => $inquiry->invitations_count ?? null,
            'viewed_count' => $inquiry->viewed_count ?? null,
            'declined_count' => $inquiry->declined_count ?? null,
            'created_at' => $inquiry->created_at?->toIso8601String(),
        ];
    }

    private function eventTypeNames($inquiries)
    {
        return DB::table('event_types')->whereIn('id', $inquiries->pluck('event_type_id')->filter()->unique())->pluck('name', 'id');
    }
}
