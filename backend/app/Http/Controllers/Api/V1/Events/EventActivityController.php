<?php

namespace App\Http\Controllers\Api\V1\Events;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Services\Events\EventActivities;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The Activity Timeline (0045): written event program + automatic history. */
class EventActivityController extends Controller
{
    public function __construct(private readonly EventActivities $activities) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate(['event_id' => ['nullable', 'uuid']]);

        return ApiResponse::success($this->activities->forOrganization($request->user(), $organization, $data['event_id'] ?? null));
    }

    public function store(Request $request, Organization $organization, Event $event): JsonResponse
    {
        return ApiResponse::created($this->activities->create($request->user(), $event, $this->data($request, creating: true)), 'Activity added.');
    }

    public function update(Request $request, Organization $organization, Event $event, string $activity): JsonResponse
    {
        return ApiResponse::success($this->activities->update($request->user(), $event, $activity, $this->data($request, creating: false)), 'Activity saved.');
    }

    public function destroy(Request $request, Organization $organization, Event $event, string $activity): JsonResponse
    {
        $this->activities->delete($request->user(), $event, $activity);

        return ApiResponse::success(null, 'Activity removed.');
    }

    /** @return array<string, mixed> */
    private function data(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tag' => ['sometimes', 'nullable', 'string', 'max:60'],
            'person' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed'])],
            'activity_at' => ['sometimes', 'nullable', 'date'],
            'visible_to_client' => ['sometimes', 'boolean'],
        ]);
    }
}
