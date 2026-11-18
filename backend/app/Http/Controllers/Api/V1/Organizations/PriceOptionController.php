<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationPriceOption;
use App\Models\OrganizationService;
use App\Services\Billing\Entitlements;
use App\Services\Media\ImageStore;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An organizer's price list for the public quotation calculator (0033).
 * Members may view; managing needs the same right as editing services.
 * Rows are written under RLS as the member ({priceOption} is scoped to the
 * organization in the URL).
 */
class PriceOptionController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Entitlements $entitlements,
    ) {}

    public function index(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        return ApiResponse::success($organization->priceOptions()
            ->orderBy('sort_order')->orderBy('created_at')->get()
            ->map(fn (OrganizationPriceOption $o) => $o->toApi())->values());
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manage', $organization);
        $this->entitlements->assertRoom($organization, 'price_option_limit');
        $data = $this->validated($request, $organization);

        $option = $organization->priceOptions()->create($data);
        $this->audit->record('organization.price_option_added', $option, new: $data, organizationId: $organization->id);

        return ApiResponse::created($option->refresh()->toApi(), 'Price option added.');
    }

    public function update(Request $request, Organization $organization, OrganizationPriceOption $priceOption): JsonResponse
    {
        Gate::authorize('manage', $organization);
        $data = $this->validated($request, $organization, partial: true);

        $old = $priceOption->only(array_keys($data));
        $priceOption->fill($data)->save();
        $this->audit->record('organization.price_option_updated', $priceOption, old: $old, new: $data, organizationId: $organization->id);

        return ApiResponse::success($priceOption->toApi(), 'Price option updated.');
    }

    public function destroy(Organization $organization, OrganizationPriceOption $priceOption): JsonResponse
    {
        Gate::authorize('manage', $organization);

        $priceOption->delete(); // soft delete
        $this->audit->record('organization.price_option_removed', $priceOption, organizationId: $organization->id);

        return ApiResponse::success(null, 'Price option removed.');
    }

    /** 0063: add a picture to the item's gallery (up to 10; the first is the cover). */
    public function storeImage(Request $request, Organization $organization, OrganizationPriceOption $priceOption, ImageStore $images): JsonResponse
    {
        Gate::authorize('manage', $organization);
        $request->validate(['image' => ['required', 'file', 'max:'.(int) ceil(config('nasery.media.max_bytes') / 1024)]],
            ['image.required' => 'Choose an image to upload.', 'image.max' => 'Choose an image under 5 MB.']);
        if (count($priceOption->images ?? []) >= OrganizationPriceOption::IMAGE_LIMIT) {
            throw ValidationException::withMessages(['image' => 'You can add up to '.OrganizationPriceOption::IMAGE_LIMIT.' pictures. Remove one first.']);
        }

        $path = $images->store($request->file('image'), 'price-option');
        try {
            $priceOption->forceFill(['images' => [...($priceOption->images ?? []), $path]])->save();
        } catch (\Throwable $e) {
            $images->delete($path);
            throw $e;
        }
        $this->audit->record('organization.price_option_image_added', $priceOption, organizationId: $organization->id);

        return ApiResponse::success($priceOption->toApi(), 'Picture added.');
    }

    public function destroyImage(Organization $organization, OrganizationPriceOption $priceOption, string $image, ImageStore $images): JsonResponse
    {
        Gate::authorize('manage', $organization);
        $path = "price-options/{$image}.webp";
        $current = $priceOption->images ?? [];
        if (in_array($path, $current, true)) {
            $priceOption->forceFill(['images' => array_values(array_filter($current, fn ($p) => $p !== $path))])->save();
            $images->delete($path);
            $this->audit->record('organization.price_option_image_removed', $priceOption, organizationId: $organization->id);
        }

        return ApiResponse::success($priceOption->toApi(), 'Picture removed.');
    }

    /** 0063: make a picture the cover (first in the gallery). */
    public function coverImage(Organization $organization, OrganizationPriceOption $priceOption, string $image): JsonResponse
    {
        Gate::authorize('manage', $organization);
        $path = "price-options/{$image}.webp";
        $current = $priceOption->images ?? [];
        if (in_array($path, $current, true)) {
            $priceOption->forceFill(['images' => [$path, ...array_values(array_filter($current, fn ($p) => $p !== $path))]])->save();
        }

        return ApiResponse::success($priceOption->toApi(), 'Cover picture changed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Organization $organization, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'pricing_unit' => [$required, Rule::in(OrganizationPriceOption::UNITS)],
            'price' => [$required, 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'min_guests' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            // 0048: per_hour / per_day / per_unit options.
            'unit_label' => ['sometimes', 'nullable', 'string', 'max:30'],
            'min_quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'max_quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'organization_service_id' => ['sometimes', 'nullable', 'uuid'],
            // 0052: event types the option is for (names, like the profile's event tags); none = every type.
            'event_tags' => ['sometimes', 'array', 'max:30'],
            'event_tags.*' => ['string', 'distinct', 'max:60'],
            // 0062: what the item includes, one short line each.
            'includes' => ['sometimes', 'array', 'max:20'],
            'includes.*' => ['nullable', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        if (filled($data['organization_service_id'] ?? null)
            && ! OrganizationService::query()->where('organization_id', $organization->id)->whereKey($data['organization_service_id'])->exists()) {
            throw ValidationException::withMessages(['organization_service_id' => 'Choose one of your services.']);
        }

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (array_key_exists('unit_label', $data)) {
            $data['unit_label'] = filled($data['unit_label']) ? trim((string) $data['unit_label']) : null;
        }
        if (array_key_exists('event_tags', $data)) {
            $data['event_tags'] = array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), $data['event_tags']), 'strlen')));
        }
        if (array_key_exists('includes', $data)) {
            $data['includes'] = array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), $data['includes']), 'strlen')));
        }
        if (isset($data['min_quantity'], $data['max_quantity']) && $data['max_quantity'] < $data['min_quantity']) {
            throw ValidationException::withMessages(['max_quantity' => 'The most a client can pick must be at least the least.']);
        }

        return $data;
    }
}
