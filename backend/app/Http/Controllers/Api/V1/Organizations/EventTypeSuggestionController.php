<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Marketplace\EventTypeSuggestions;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Event types organizers suggest for NaSeRy's list (0054). Members may see
 * their organization's suggestions; suggesting needs the right to manage the
 * organization (like editing the business profile). Admins decide them from
 * the admin portal (AdminController).
 */
class EventTypeSuggestionController extends Controller
{
    public function __construct(private readonly EventTypeSuggestions $suggestions) {}

    public function index(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        return ApiResponse::success($this->suggestions->forOrganization($organization));
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manage', $organization);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            // Sent after "Did you mean …?" when the organizer says it really is a new type.
            'confirm_new' => ['sometimes', 'boolean'],
        ]);

        $result = $this->suggestions->suggest($organization, $request->user(), $data['name'], (bool) ($data['confirm_new'] ?? false));
        $message = match ($result['result']) {
            'exists' => "“{$result['event_type']}” is already on NaSeRy's list.",
            'similar' => "Did you mean “{$result['event_type']}”?",
            'already_suggested' => 'You already suggested this one; NaSeRy will let you know.',
            default => 'Thanks! NaSeRy will review it and let you know.',
        };
        // New names are also the organization's own event type right away (plan permitting).
        if (in_array($result['result'], ['pending', 'already_suggested'], true)) {
            $message .= ! empty($result['own_type'])
                ? ' You can already use it for your own events.'
                : ($result['own_type_limit_reached'] ? ' Your plan\'s own event types are used up, so you can use it once NaSeRy adds it.' : '');
        }

        return $result['result'] === 'pending'
            ? ApiResponse::created($result, $message)
            : ApiResponse::success($result, $message);
    }

    /** Admin portal: pending suggestions (grouped by name) and decisions. */
    public function pending(): JsonResponse
    {
        return ApiResponse::success($this->suggestions->pendingForAdmins());
    }

    public function decide(Request $request, string $suggestion, string $decision): JsonResponse
    {
        $data = $request->validate([
            'name' => [Rule::excludeIf($decision !== 'approve'), 'nullable', 'string', 'min:2', 'max:80'],
            'event_type_id' => [Rule::requiredIf($decision === 'merge'), Rule::excludeIf($decision !== 'merge'), 'uuid'],
            'note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
        ]);

        $this->suggestions->decide($request->user(), $suggestion, $decision, $data['name'] ?? null, $data['event_type_id'] ?? null, $data['note'] ?? null);

        return ApiResponse::success($this->suggestions->pendingForAdmins(), match ($decision) {
            'approve' => 'Event type added. The organizers who suggested it were told.',
            'merge' => 'Merged. The organizers who suggested it were told.',
            default => 'Suggestion rejected. The organizers who suggested it were told.',
        });
    }
}
