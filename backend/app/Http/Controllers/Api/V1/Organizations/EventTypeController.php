<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Models\EventType;
use App\Models\Organization;
use App\Services\Billing\Entitlements;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * An organization's event types: platform defaults (read-only) plus its own
 * custom types (owner-managed; RLS limits writes to the member's own org).
 */
class EventTypeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Entitlements $entitlements,
    ) {}

    public function index(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        $types = EventType::query()
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organization->id))
            ->orderByRaw('organization_id is not null')->orderBy('name')
            ->get();

        return ApiResponse::success($types->map(fn (EventType $t) => $this->present($t))->values());
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manage', $organization);
        $this->entitlements->assertRoom($organization, 'custom_event_type_limit');
        $data = $this->validated($request, $organization);

        $type = EventType::query()->create(['organization_id' => $organization->id] + $data);
        $this->audit->record('organization.event_type_added', $type, new: $data, organizationId: $organization->id);

        return ApiResponse::created($this->present($type->refresh()), 'Event type added.');
    }

    public function update(Request $request, Organization $organization, EventType $eventType): JsonResponse
    {
        Gate::authorize('manage', $organization);
        abort_if($eventType->organization_id !== $organization->id, 404);

        $data = $this->validated($request, $organization, $eventType);
        $old = $eventType->only(array_keys($data));
        $eventType->update($data);
        $this->audit->record('organization.event_type_updated', $eventType, old: $old, new: $data, organizationId: $organization->id);

        return ApiResponse::success($this->present($eventType), 'Event type updated.');
    }

    public function destroy(Organization $organization, EventType $eventType): JsonResponse
    {
        Gate::authorize('manage', $organization);
        abort_if($eventType->organization_id !== $organization->id, 404);

        $eventType->delete(); // soft delete: past events keep referencing it
        $this->audit->record('organization.event_type_removed', $eventType, organizationId: $organization->id);

        return ApiResponse::success(null, 'Event type removed.');
    }

    /**
     * @return array{name: string, description?: ?string}
     */
    private function validated(Request $request, Organization $organization, ?EventType $current = null): array
    {
        $data = $request->validate([
            'name' => [$current ? 'sometimes' : 'required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        if (isset($data['name'])) {
            $data['name'] = trim(preg_replace('/\s+/', ' ', $data['name']));

            $clash = EventType::query()
                ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organization->id))
                ->when($current, fn ($q) => $q->whereKeyNot($current->id))
                ->whereRaw('lower(name) = lower(?)', [$data['name']])
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages(['name' => 'An event type with this name already exists.']);
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EventType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'description' => $type->description,
            'is_platform_default' => $type->isPlatformDefault(),
        ];
    }
}
