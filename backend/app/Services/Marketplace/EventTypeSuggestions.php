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
 * Organizers suggest new standard event types (0054). What can be decided
 * automatically is:
 *   - the name already exists (ignoring capitals, spacing, "&"/"and"): use it
 *   - it looks like a typo or variant of one (1–2 letters off, singular /
 *     plural, same words in another order): "Did you mean …?" until the
 *     organizer confirms it's really new
 *   - the organization already suggested it: nothing new
 * Everything else waits for a platform admin, grouped by name so the same
 * suggestion from several organizers is decided once: approve (it becomes a
 * standard type, optionally with fixed spelling), merge into an existing
 * type, or reject. The organizations that suggested it get the resulting
 * type on their profile's event tags and a notification.
 */
class EventTypeSuggestions
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly AdminAlerts $admin,
    ) {}

    /** "  gender   reveal " → "Gender reveal" (first letter capitalised, single spaces). */
    public static function clean(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        return Str::ucfirst($name);
    }

    /** Lower case, "&" = "and", no punctuation, single spaces: how names are compared. */
    public static function key(string $name): string
    {
        $k = mb_strtolower(self::clean($name));
        $k = str_replace('&', ' and ', $k);
        $k = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $k);

        return trim(preg_replace('/\s+/u', ' ', $k));
    }

    /**
     * The existing standard type a name probably means, or null. Exact keys
     * are handled by the caller; this catches typos and variants.
     *
     * @param  Collection<int, string>  $names
     */
    public static function similar(string $name, Collection $names): ?string
    {
        $key = self::key($name);
        $stem = fn (string $k) => collect(explode(' ', $k))->map(fn ($w) => preg_replace('/(es|s)$/u', '', $w))->sort()->implode(' ');
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($names as $existing) {
            $other = self::key($existing);
            if ($stem($other) === $stem($key)) {
                return $existing; // plural / singular / word order
            }
            $distance = levenshtein($key, $other);
            $allowed = min(mb_strlen($key), mb_strlen($other)) >= 6 ? 2 : 1;
            if ($distance <= $allowed && $distance < $bestDistance) {
                [$best, $bestDistance] = [$existing, $distance];
            }
        }

        return $best;
    }

    /**
     * @return array{result: string, event_type?: string, suggestion?: array<string, mixed>}
     *   result: exists | similar | already_suggested | pending
     */
    public function suggest(Organization $organization, User $by, string $name, bool $confirmNew): array
    {
        $name = self::clean($name);
        $key = self::key($name);
        if (mb_strlen($key) < 2) {
            throw ValidationException::withMessages(['name' => 'Write the event type, e.g. Gender reveal.']);
        }

        return $this->db->system('event_types.suggest', function () use ($organization, $by, $name, $key, $confirmNew) {
            $standard = $this->standardNames();
            $exact = $standard->first(fn ($n) => self::key($n) === $key);
            if ($exact !== null) {
                return ['result' => 'exists', 'event_type' => $exact];
            }
            if (! $confirmNew && ($close = self::similar($name, $standard)) !== null) {
                return ['result' => 'similar', 'event_type' => $close];
            }
            if (DB::table('event_type_suggestions')->where('organization_id', $organization->id)->where('name_key', $key)->where('status', 'pending')->exists()) {
                return ['result' => 'already_suggested'];
            }

            $id = (string) Str::uuid7();
            DB::table('event_type_suggestions')->insert(['id' => $id, 'organization_id' => $organization->id, 'suggested_by' => $by->id,
                'name' => $name, 'name_key' => $key, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('event_type.suggested', ['event_type_suggestions', $id], new: ['name' => $name], organizationId: $organization->id);
            $this->notifier->send($this->admin->admins(), 'admin.event_type', "New event type suggested: {$name}",
                "{$organization->display_name} suggested it. Approve, merge or reject it on Event Tags.", ['suggestion_id' => $id],
                '/superadmin/event-tags', "event_type:{$key}:".now()->format('YmdH'));

            return ['result' => 'pending', 'suggestion' => $this->present(DB::table('event_type_suggestions')->find($id))];
        });
    }

    /** @return list<array<string, mixed>> the organization's suggestions, newest first */
    public function forOrganization(Organization $organization): array
    {
        return $this->db->system('event_types.org_suggestions', fn () => DB::table('event_type_suggestions')
            ->where('organization_id', $organization->id)->orderByDesc('created_at')->limit(30)->get()
            ->map(fn ($s) => $this->present($s))->all());
    }

    /**
     * Pending suggestions for admins, one row per name (with every organization that asked).
     *
     * @return list<array<string, mixed>>
     */
    public function pendingForAdmins(): array
    {
        return $this->db->system('event_types.admin_suggestions', function () {
            $standard = $this->standardNames();

            return DB::table('event_type_suggestions as s')->join('organizations as o', 'o.id', '=', 's.organization_id')
                ->where('s.status', 'pending')->orderBy('s.created_at')
                ->get(['s.id', 's.name', 's.name_key', 's.created_at', 'o.id as organization_id', 'o.display_name'])
                ->groupBy('name_key')->map(fn (Collection $rows) => [
                    'id' => $rows->first()->id,
                    'name' => $rows->first()->name,
                    'suggested_at' => $rows->first()->created_at,
                    'organizations' => $rows->map(fn ($r) => ['id' => $r->organization_id, 'name' => $r->display_name])->values()->all(),
                    'similar_to' => self::similar($rows->first()->name, $standard),
                ])->values()->all();
        });
    }

    /**
     * Decide every pending suggestion with the same name as $suggestionId.
     *
     * @param  'approve'|'merge'|'reject'  $decision
     */
    public function decide(User $admin, string $suggestionId, string $decision, ?string $name, ?string $eventTypeId, ?string $note): void
    {
        [$typeName, $organizations, $label] = $this->db->system('event_types.decide', function () use ($admin, $suggestionId, $decision, $name, $eventTypeId, $note) {
            $first = DB::table('event_type_suggestions')->where('id', $suggestionId)->where('status', 'pending')->first()
                ?? throw new ApiException(ErrorCode::NotFound, 'This suggestion was already decided.', 404);
            $group = DB::table('event_type_suggestions')->where('name_key', $first->name_key)->where('status', 'pending')->lockForUpdate()->get();
            $typeId = null;
            $typeName = null;

            if ($decision === 'approve') {
                $typeName = self::clean($name ?: $first->name);
                // Approving a name that already exists is a merge into it.
                $existing = DB::table('event_types')->whereNull('organization_id')->whereNull('deleted_at')->get(['id', 'name'])
                    ->first(fn ($t) => self::key($t->name) === self::key($typeName));
                if ($existing) {
                    [$typeId, $typeName] = [$existing->id, $existing->name];
                } else {
                    $typeId = (string) Str::uuid7();
                    DB::table('event_types')->insert(['id' => $typeId, 'organization_id' => null, 'name' => $typeName, 'description' => null,
                        'is_system_default' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            } elseif ($decision === 'merge') {
                $type = DB::table('event_types')->where('id', $eventTypeId)->whereNull('organization_id')->whereNull('deleted_at')->first(['id', 'name'])
                    ?? throw ValidationException::withMessages(['event_type_id' => 'Choose one of NaSeRy\'s event types.']);
                [$typeId, $typeName] = [$type->id, $type->name];
            }

            DB::table('event_type_suggestions')->whereIn('id', $group->pluck('id'))->update([
                'status' => ['approve' => 'approved', 'merge' => 'merged', 'reject' => 'rejected'][$decision],
                'event_type_id' => $typeId, 'decided_by' => $admin->id, 'decided_at' => now(),
                'decision_note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'updated_at' => now(),
            ]);

            // The organizations that asked now offer the type (approved or merged).
            if ($typeName !== null) {
                foreach ($group->pluck('organization_id')->unique() as $orgId) {
                    $tags = collect(json_decode((string) DB::table('organization_profiles')->where('organization_id', $orgId)->value('event_tags'), true) ?: []);
                    if (! $tags->contains(fn ($t) => self::key((string) $t) === self::key($typeName))) {
                        DB::table('organization_profiles')->where('organization_id', $orgId)
                            ->update(['event_tags' => json_encode($tags->push($typeName)->values()->all()), 'updated_at' => now()]);
                    }
                }
            }

            return [$typeName, $group->pluck('organization_id')->unique()->values()->all(), $first->name];
        });

        $this->audit->record("event_type.suggestion_{$decision}d", ['event_type_suggestions', $suggestionId],
            new: ['name' => $label, 'event_type' => $typeName, 'organizations' => count($organizations)], reason: $note);

        $why = $note !== null && trim($note) !== '' ? ' Note: '.rtrim(trim($note), '.').'.' : '';
        foreach ($organizations as $orgId) {
            $this->notifier->send($this->admin->owners($orgId), 'event_type.decided', match ($decision) {
                'approve' => "“{$typeName}” is now a NaSeRy event type",
                'merge' => "Your suggested event type was matched to “{$typeName}”",
                default => "Your suggested event type “{$label}” wasn't added",
            }, match ($decision) {
                'approve', 'merge' => "It's on your business profile, so clients asking for {$typeName} events can now find you.{$why}",
                default => "NaSeRy didn't add it to the list.{$why}",
            }, ['organization_id' => $orgId], '/organizer/business-profile');
        }
    }

    /** @return Collection<int, string> */
    private function standardNames(): Collection
    {
        return DB::table('event_types')->whereNull('organization_id')->whereNull('deleted_at')->orderBy('name')->pluck('name');
    }

    /** @return array<string, mixed> */
    private function present(object $s): array
    {
        $type = $s->event_type_id ? DB::table('event_types')->where('id', $s->event_type_id)->value('name') : null;

        return ['id' => $s->id, 'name' => $s->name, 'status' => $s->status, 'event_type' => $type, 'note' => $s->decision_note,
            'created_at' => $s->created_at, 'decided_at' => $s->decided_at];
    }
}
