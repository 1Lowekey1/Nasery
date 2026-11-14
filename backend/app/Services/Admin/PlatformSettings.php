<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Platform settings (0018 platform_settings) and the platform fee (0013
 * platform_fee_configs). Only whitelisted keys are editable, each with
 * bounds. A fee change never edits a row: the current version is closed
 * (effective_to = now) and a new one starts, so every payment's snapshot
 * still points at the version it was charged under.
 */
class PlatformSettings
{
    /** @var array<string, array{label: string, help: string, min: int, max: int, default: int, unit: string}> */
    public const EDITABLE = [
        'ticket_hold_default_minutes' => ['label' => 'Checkout hold', 'help' => 'How long tickets stay reserved while a buyer pays.', 'min' => 5, 'max' => 120, 'default' => 15, 'unit' => 'minutes'],
        'ticket_hold_max_minutes' => ['label' => 'Longest allowed hold', 'help' => 'Ceiling for the checkout hold.', 'min' => 5, 'max' => 120, 'default' => 60, 'unit' => 'minutes'],
        'admin_grant_default_expiry_hours' => ['label' => 'Admin access length', 'help' => 'How long approved workspace access lasts.', 'min' => 1, 'max' => 72, 'default' => 24, 'unit' => 'hours'],
        'cancellation_grace_hours' => ['label' => 'Time to answer a cancellation', 'help' => 'If the other side doesn\'t answer a request to cancel a booking within this time, it counts as agreed.', 'min' => 1, 'max' => 336, 'default' => 72, 'unit' => 'hours'],
        // Mock defense: VAT is NaSeRy's rate, not typed per offer. Organizers only say whether they charge it.
        'vat_percent' => ['label' => 'VAT rate', 'help' => 'Added to offers and estimates of organizers who charge VAT (VAT-registered businesses).', 'min' => 0, 'max' => 30, 'default' => 12, 'unit' => '%'],
        'inquiry_auto_close_days' => ['label' => 'Inquiry auto-close', 'help' => 'Unanswered inquiries close after this many days.', 'min' => 7, 'max' => 180, 'default' => 30, 'unit' => 'days'],
    ];

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        // Read every time: services (and the controllers holding them) can
        // outlive a request, so a cached value could go stale after an edit.
        $value = $this->db->system('settings.read', fn () => DB::table('platform_settings')->where('key', $key)->value('value'));

        return $value !== null ? json_decode((string) $value, true) : ($default ?? (self::EDITABLE[$key]['default'] ?? null));
    }

    /**
     * The VAT rate an organizer applies: NaSeRy's rate when they charge VAT
     * (their profile's estimate_tax_percent is only an on/off marker), else null.
     */
    public function vatFor(mixed $storedTaxPercent): ?float
    {
        return $storedTaxPercent !== null && (float) $storedTaxPercent > 0 ? (float) $this->get('vat_percent') : null;
    }

    public function holdMinutes(): int
    {
        return max(5, min((int) $this->get('ticket_hold_default_minutes'), (int) $this->get('ticket_hold_max_minutes')));
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        return [
            'settings' => collect(self::EDITABLE)->map(fn ($meta, $key) => ['key' => $key, 'value' => (int) $this->get($key)] + $meta)->values(),
            'fee' => $this->fees(),
        ];
    }

    /** @param  array<string, int>  $values */
    public function update(array $values, User $by): array
    {
        $values = array_intersect_key($values, self::EDITABLE);
        $merged = array_merge(collect(self::EDITABLE)->map(fn ($m, $k) => (int) $this->get($k))->all(), $values);
        if ($merged['ticket_hold_default_minutes'] > $merged['ticket_hold_max_minutes']) {
            throw ValidationException::withMessages(['ticket_hold_default_minutes' => 'The checkout hold cannot be longer than the longest allowed hold.']);
        }

        $old = [];
        $this->db->system('settings.write', function () use ($values, $by, &$old) {
            foreach ($values as $key => $value) {
                $old[$key] = $this->get($key);
                DB::table('platform_settings')->upsert(
                    [['id' => (string) Str::uuid7(), 'key' => $key, 'value' => json_encode($value), 'description' => self::EDITABLE[$key]['help'], 'updated_by' => $by->id, 'updated_at' => now()]],
                    ['key'], ['value', 'updated_by', 'updated_at'],
                );
            }
        });
        $this->audit->record('platform.settings_updated', ['platform_settings', null], old: $old, new: $values);

        return $this->overview();
    }

    /** @return array<string, mixed> */
    public function fees(): array
    {
        return $this->db->system('settings.fees', function () {
            $rows = DB::table('platform_fee_configs as f')->leftJoin('users as u', 'u.id', '=', 'f.created_by')
                ->orderByDesc('f.effective_from')->limit(20)->get(['f.*', 'u.first_name', 'u.last_name']);
            $present = fn ($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'enabled' => (bool) $f->is_enabled,
                'model' => $f->fee_model,
                'percent' => $f->percentage_rate !== null ? round((float) $f->percentage_rate * 100, 2) : null,
                'fixed' => $f->fixed_amount !== null ? (float) $f->fixed_amount : null,
                'from' => CarbonImmutable::parse($f->effective_from)->toIso8601String(),
                'to' => $f->effective_to ? CarbonImmutable::parse($f->effective_to)->toIso8601String() : null,
                'by' => $f->first_name ? trim("{$f->first_name} {$f->last_name}") : 'NaSeRy (default)',
            ];
            $current = $rows->first(fn ($f) => $f->effective_to === null || CarbonImmutable::parse($f->effective_to)->isFuture());

            return ['current' => $current ? $present($current) : null, 'history' => $rows->map($present)->values()];
        });
    }

    /**
     * Start a new fee version now. Applies to payments settled from now on;
     * earlier payments keep their snapshot.
     *
     * @param  array{enabled: bool, model: string, percent?: float|null, fixed?: float|null, reason: string}  $input
     */
    public function changeFee(array $input, User $by): array
    {
        $rate = isset($input['percent']) ? round($input['percent'] / 100, 4) : null;
        $fixed = isset($input['fixed']) ? round($input['fixed'], 2) : null;
        $model = $input['model'];
        if (! $input['enabled']) {
            // A switched-off version still needs valid fields (0013 check).
            [$model, $rate, $fixed] = ['percentage', 0.0, null];
        }

        if (in_array($model, ['percentage', 'hybrid'], true) && $rate === null) {
            throw ValidationException::withMessages(['percent' => 'Enter the percentage.']);
        }
        if (in_array($model, ['fixed', 'hybrid'], true) && $fixed === null) {
            throw ValidationException::withMessages(['fixed' => 'Enter the fixed amount.']);
        }

        $old = $this->fees()['current'];
        $this->db->system('settings.fee_change', function () use ($input, $rate, $fixed, $model, $by) {
            $now = now();
            $stamp = $now->format('Y-m-d H:i:s.uP');
            DB::table('platform_fee_configs')->whereNull('effective_to')->orWhere('effective_to', '>', $stamp)->update(['effective_to' => $stamp]);
            DB::table('platform_fee_configs')->insert([
                'id' => (string) Str::uuid7(),
                'name' => $input['enabled'] ? 'Platform fee '.$now->format('Y-m-d') : 'Fee turned off '.$now->format('Y-m-d'),
                'is_enabled' => $input['enabled'],
                'fee_model' => $model,
                'percentage_rate' => $model === 'fixed' ? null : $rate,
                'fixed_amount' => $model === 'percentage' ? null : $fixed,
                'effective_from' => $stamp,
                'created_by' => $by->id,
                'created_at' => $now,
            ]);
        });

        $new = $this->fees()['current'];
        if ($new === null) {
            throw new ApiException(ErrorCode::ServerError, 'The new fee could not be read back.', 500);
        }
        $this->audit->record('platform.fee_changed', ['platform_fee_configs', $new['id']], old: $old, new: $new, reason: $input['reason']);

        return $this->fees();
    }
}
