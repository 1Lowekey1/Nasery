<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Marketplace\ServiceSuggestions;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Services organizers add that aren't in NaSeRy's catalog (0056): their own
 * service right away, and a suggestion for the catalog. Members may see their
 * organization's suggestions; adding needs the right to manage the
 * organization. Admins decide them on Service Tags.
 */
class ServiceSuggestionController extends Controller
{
    public function __construct(private readonly ServiceSuggestions $suggestions) {}

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
            'base_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            // Sent after "Did you mean …?" when the organizer says it really is a new service.
            'confirm_new' => ['sometimes', 'boolean'],
        ]);

        $result = $this->suggestions->suggest($organization, $request->user(), $data['name'],
            isset($data['base_price']) ? (float) $data['base_price'] : null, (bool) ($data['confirm_new'] ?? false));
        $message = match ($result['result']) {
            'exists' => $result['offered'] ? "Added “{$result['service']}” from NaSeRy's catalog." : "You already offer “{$result['service']}”.",
            'similar' => "Did you mean “{$result['service']}”?",
            'already_suggested' => 'You already suggested this one; NaSeRy will let you know.',
            default => 'Added as your own service. NaSeRy will review it for the catalog and let you know.',
        };

        return in_array($result['result'], ['pending'], true) || ($result['result'] === 'exists' && $result['offered'])
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
            'category' => [Rule::excludeIf($decision !== 'approve'), 'nullable', 'string', 'max:60'],
            'service_id' => [Rule::requiredIf($decision === 'merge'), Rule::excludeIf($decision !== 'merge'), 'uuid'],
            'note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
        ]);

        $this->suggestions->decide($request->user(), $suggestion, $decision, $data['name'] ?? null, $data['service_id'] ?? null,
            $data['category'] ?? null, $data['note'] ?? null);

        return ApiResponse::success($this->suggestions->pendingForAdmins(), match ($decision) {
            'approve' => 'Service added to the catalog. The organizers who suggested it were told.',
            'merge' => 'Merged. The organizers who suggested it were told.',
            default => 'Suggestion rejected. The organizers who suggested it were told.',
        });
    }
}
