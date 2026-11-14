<?php

namespace App\Services\Marketplace;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\AdminAlerts;
use App\Services\Notifications\Notifier;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The service catalog's version of EventTypeSuggestions (0056). An organizer
 * adds a service once:
 *   - already in the catalog (any capitals / spacing / "&"): they offer that one
 *   - one or two letters off, singular / plural, words swapped: "Did you mean …?"
 *     until they confirm it's really new
 *   - new: it's their own (custom) service right away, and it waits for a
 *     platform admin, grouped by name across organizers
 * The admin approves it (a catalog service, optionally with fixed spelling),
 * merges it into an existing one, or rejects it. Approved or merged: the
 * organizers' custom services are linked to the catalog service (price and
 * price options kept). Rejected: it stays their own.
 */
class ServiceSuggestions
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly AdminAlerts $admin,
    ) {}

    /**
     * @return array<string, mixed> result: exists | similar | already_suggested | pending
     */
    public function suggest(Organization $organization, User $by, string $name, ?float $basePrice, bool $confirmNew): array
    {
        $name = EventTypeSuggestions::clean($name);
        $key = EventTypeSuggestions::key($name);
        if (mb_strlen($key) < 2) {
            throw ValidationException::withMessages(['name' => 'Write the service, e.g. Balloon styling.']);
        }

        return $this->db->system('services.suggest', function () use ($organization, $by, $name, $key, $basePrice, $confirmNew) {
            $catalog = $this->catalog();
            $exact = $catalog->first(fn ($s) => EventTypeSuggestions::key($s->name) === $key);
            if ($exact !== null) {
                // Already in the catalog: they simply offer it (unless they already do).
                $offered = $this->offer($organization, $exact->id, null, $basePrice);

                return ['result' => 'exists', 'service' => $exact->name, 'offered' => $offered];
            }
            if (! $confirmNew && ($close = EventTypeSuggestions::similar($name, $catalog->pluck('name'))) !== null) {
                return ['result' => 'similar', 'service' => $close];
            }

            // Their own service right away (one per name).
            $own = $this->customServices($organization->id, $key)->first();
            if ($own === null) {
                $this->offer($organization, null, $name, $basePrice);
            }

            if (DB::table('service_suggestions')->where('organization_id', $organization->id)->where('name_key', $key)->where('status', 'pending')->exists()) {
                return ['result' => 'already_suggested'];
            }

            $id = (string) Str::uuid7();
            DB::table('service_suggestions')->insert(['id' => $id, 'organization_id' => $organization->id, 'suggested_by' => $by->id,
                'name' => $name, 'name_key' => $key, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('service.suggested', ['service_suggestions', $id], new: ['name' => $name], organizationId: $organization->id);
            $this->notifier->send($this->admin->admins(), 'admin.service', "New service suggested: {$name}",
                "{$organization->display_name} suggested it. Approve, merge or reject it on Service Tags.", ['suggestion_id' => $id],
                '/superadmin/service-tags', "service:{$key}:".now()->format('YmdH'));

            return ['result' => 'pending', 'suggestion' => $this->present(DB::table('service_suggestions')->find($id))];
        });
    }

    /** @return list<array<string, mixed>> the organization's suggestions, newest first */
    public function forOrganization(Organization $organization): array
    {
        return $this->db->system('services.org_suggestions', fn () => DB::table('service_suggestions')
            ->where('organization_id', $organization->id)->orderByDesc('created_at')->limit(30)->get()
            ->map(fn ($s) => $this->present($s))->all());
    }

    /** @return list<array<string, mixed>> pending suggestions, one row per name, with every organization that asked */
    public function pendingForAdmins(): array
    {
        return $this->db->system('services.admin_suggestions', function () {
            $catalog = $this->catalog()->pluck('name');

            return DB::table('service_suggestions as s')->join('organizations as o', 'o.id', '=', 's.organization_id')
                ->where('s.status', 'pending')->orderBy('s.created_at')
                ->get(['s.id', 's.name', 's.name_key', 's.created_at', 'o.id as organization_id', 'o.display_name'])
                ->groupBy('name_key')->map(fn (Collection $rows) => [
                    'id' => $rows->first()->id,
                    'name' => $rows->first()->name,
                    'suggested_at' => $rows->first()->created_at,
                    'organizations' => $rows->map(fn ($r) => ['id' => $r->organization_id, 'name' => $r->display_name])->values()->all(),
                    'similar_to' => EventTypeSuggestions::similar($rows->first()->name, $catalog),
                ])->values()->all();
        });
    }

    /**
     * Decide every pending suggestion with the same name as $suggestionId.
     *
     * @param  'approve'|'merge'|'reject'  $decision
     */
    public function decide(User $admin, string $suggestionId, string $decision, ?string $name, ?string $serviceId, ?string $category, ?string $note): void
    {
        [$serviceName, $organizations, $label] = $this->db->system('services.decide', function () use ($admin, $suggestionId, $decision, $name, $serviceId, $category, $note) {
            $first = DB::table('service_suggestions')->where('id', $suggestionId)->where('status', 'pending')->first()
                ?? throw new ApiException(ErrorCode::NotFound, 'This suggestion was already decided.', 404);
            $group = DB::table('service_suggestions')->where('name_key', $first->name_key)->where('status', 'pending')->lockForUpdate()->get();
            $id = null;
            $serviceName = null;

            if ($decision === 'approve') {
                $serviceName = EventTypeSuggestions::clean($name ?: $first->name);
                // Approving a name that's already in the catalog is a merge into it.
                $existing = $this->catalog()->first(fn ($s) => EventTypeSuggestions::key($s->name) === EventTypeSuggestions::key($serviceName));
                if ($existing) {
                    [$id, $serviceName] = [$existing->id, $existing->name];
                } else {
                    $id = (string) Str::uuid7();
                    DB::table('services')->insert(['id' => $id, 'name' => $serviceName, 'category' => $category !== null && trim($category) !== '' ? trim($category) : null,
                        'is_system_default' => true, 'created_at' => now()]);
                }
            } elseif ($decision === 'merge') {
                $service = DB::table('services')->where('id', $serviceId)->whereNull('deleted_at')->first(['id', 'name'])
                    ?? throw ValidationException::withMessages(['service_id' => 'Choose one of NaSeRy\'s catalog services.']);
                [$id, $serviceName] = [$service->id, $service->name];
            }

            DB::table('service_suggestions')->whereIn('id', $group->pluck('id'))->update([
                'status' => ['approve' => 'approved', 'merge' => 'merged', 'reject' => 'rejected'][$decision],
                'service_id' => $id, 'decided_by' => $admin->id, 'decided_at' => now(),
                'decision_note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'updated_at' => now(),
            ]);

            // Their custom services become the catalog service (price and price options kept).
            if ($id !== null) {
                foreach ($group->pluck('organization_id')->unique() as $orgId) {
                    $already = DB::table('organization_services')->where('organization_id', $orgId)->where('service_id', $id)->whereNull('deleted_at')->value('id');
                    foreach ($this->customServices($orgId, $first->name_key) as $custom) {
                        if ($already) {
                            // They already offer it: move the price options over and drop the duplicate.
                            DB::table('organization_price_options')->where('organization_service_id', $custom->id)->update(['organization_service_id' => $already]);
                            DB::table('organization_services')->where('id', $custom->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
                        } else {
                            DB::table('organization_services')->where('id', $custom->id)->update(['service_id' => $id, 'custom_name' => null, 'updated_at' => now()]);
                            $already = $custom->id;
                        }
                    }
                }
            }

            return [$serviceName, $group->pluck('organization_id')->unique()->values()->all(), $first->name];
        });

        $this->audit->record("service.suggestion_{$decision}d", ['service_suggestions', $suggestionId],
            new: ['name' => $label, 'service' => $serviceName, 'organizations' => count($organizations)], reason: $note);

        $why = $note !== null && trim($note) !== '' ? ' Note: '.rtrim(trim($note), '.').'.' : '';
        foreach ($organizations as $orgId) {
            $this->notifier->send($this->admin->owners($orgId), 'service.decided', match ($decision) {
                'approve' => "“{$serviceName}” is now a NaSeRy service",
                'merge' => "Your suggested service was matched to “{$serviceName}”",
                default => "Your suggested service “{$label}” wasn't added",
            }, match ($decision) {
                'approve', 'merge' => "Your “{$label}” is now listed as {$serviceName}, so clients looking for it can find you.{$why}",
                default => "NaSeRy didn't add it to the catalog. It stays on your profile as your own service.{$why}",
            }, ['organization_id' => $orgId], '/organizer/business-profile');
        }
    }

    /** @return Collection<int, object> the catalog's live services (id, name) */
    private function catalog(): Collection
    {
        return DB::table('services')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, object> the organization's live custom services with this name key */
    private function customServices(string $organizationId, string $key): Collection
    {
        return DB::table('organization_services')->where('organization_id', $organizationId)->whereNull('service_id')->whereNull('deleted_at')
            ->get(['id', 'custom_name'])->filter(fn ($s) => EventTypeSuggestions::key((string) $s->custom_name) === $key)->values();
    }

    /** Offer a catalog service ($serviceId) or a custom one ($customName); false when it's already offered. */
    private function offer(Organization $organization, ?string $serviceId, ?string $customName, ?float $basePrice): bool
    {
        if ($serviceId !== null && DB::table('organization_services')->where('organization_id', $organization->id)->where('service_id', $serviceId)->whereNull('deleted_at')->exists()) {
            return false;
        }
        $id = (string) Str::uuid7();
        DB::table('organization_services')->insert(['id' => $id, 'organization_id' => $organization->id, 'service_id' => $serviceId,
            'custom_name' => $customName, 'base_price' => $basePrice, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('organization.service_added', ['organization_services', $id],
            new: ['service_id' => $serviceId, 'custom_name' => $customName, 'base_price' => $basePrice], organizationId: $organization->id);

        return true;
    }

    /** @return array<string, mixed> */
    private function present(object $s): array
    {
        $service = $s->service_id ? DB::table('services')->where('id', $s->service_id)->value('name') : null;

        return ['id' => $s->id, 'name' => $s->name, 'status' => $s->status, 'service' => $service, 'note' => $s->decision_note,
            'created_at' => $s->created_at, 'decided_at' => $s->decided_at];
    }
}
