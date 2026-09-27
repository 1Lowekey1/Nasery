<?php

namespace App\Services\Organizations;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\OrganizationService;
use App\Models\Service;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Services an organizer offers. Rows are written under RLS as the owner;
 * the platform catalog (public.services) has no RLS policy, so catalog
 * lookups are system reads.
 */
class OfferedServices
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, OrganizationService>
     */
    public function list(Organization $organization, bool $includeInactive = true): Collection
    {
        $services = OrganizationService::query()
            ->where('organization_id', $organization->id)
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('created_at')
            ->get();

        return $this->withCatalogNames($services);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Organization $organization, array $data): OrganizationService
    {
        $this->assertCatalogService($data['service_id'] ?? null);

        $service = OrganizationService::query()->create(['organization_id' => $organization->id] + $data);
        $this->audit->record('organization.service_added', $service, new: $data, organizationId: $organization->id);

        return $this->withCatalogNames(collect([$service->refresh()]))->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(OrganizationService $service, array $data): OrganizationService
    {
        if (array_key_exists('service_id', $data)) {
            $this->assertCatalogService($data['service_id']);
        }

        $old = $service->only(array_keys($data));
        $service->fill($data);

        if ($service->service_id === null && blank($service->custom_name)) {
            throw ValidationException::withMessages(['custom_name' => 'A service needs a name or a catalog service.']);
        }

        $service->save();
        $this->audit->record('organization.service_updated', $service, old: $old, new: $data, organizationId: $service->organization_id);

        return $this->withCatalogNames(collect([$service]))->first();
    }

    public function delete(OrganizationService $service): void
    {
        $service->delete(); // soft delete: quotations may reference it later
        $this->audit->record('organization.service_removed', $service, organizationId: $service->organization_id);
    }

    /**
     * @return Collection<int, Service>
     */
    public function catalog(): Collection
    {
        return $this->db->system('services.catalog', fn () => Service::query()->orderBy('category')->orderBy('name')->get());
    }

    /**
     * @param  Collection<int, OrganizationService>  $services
     * @return Collection<int, OrganizationService>
     */
    private function withCatalogNames(Collection $services): Collection
    {
        $ids = $services->pluck('service_id')->filter()->unique()->values();
        $names = $ids->isEmpty() ? collect() : $this->db->system('services.catalog', fn () => Service::query()->whereIn('id', $ids)->pluck('name', 'id'));

        return $services->each(fn (OrganizationService $s) => $s->setAttribute('catalog_name', $names[$s->service_id] ?? null));
    }

    private function assertCatalogService(?string $serviceId): void
    {
        if ($serviceId === null) {
            return;
        }

        $exists = $this->db->system('services.catalog', fn () => Service::query()->whereKey($serviceId)->exists());

        if (! $exists) {
            throw new ApiException(ErrorCode::ValidationError, 'The given data was invalid.', 422, [
                'fields' => ['service_id' => ['That catalog service does not exist.']],
            ]);
        }
    }
}
