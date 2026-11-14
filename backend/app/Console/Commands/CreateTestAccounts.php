<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Enums\ApprovalStatus;
use App\Models\User;
use App\Services\Organizations\ApprovalWorkflow;
use App\Services\Organizations\OfferedServices;
use App\Services\Organizations\OrganizerOnboarding;
use App\Support\Database\DatabaseContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Development helper: a small, throwaway set of accounts for manual/browser
 * testing (client@nasery.test, organizer@nasery.test with an approved, listed
 * organization, services and a price list). The password is read from the
 * NASERY_TEST_PASSWORD environment variable at run time and never stored.
 * Remove them with `php artisan nasery:test-data:purge --force` (@nasery.test).
 * Refuses to run in production mode.
 */
#[Signature('nasery:test-accounts')]
#[Description('Create @nasery.test client + organizer accounts for manual testing (development only)')]
class CreateTestAccounts extends Command
{
    public function handle(DatabaseContext $ctx): int
    {
        if (app()->isProduction()) {
            $this->error('Not in production mode. Switch to development first (scripts\\nasery-mode.ps1 development).');

            return self::FAILURE;
        }
        $password = (string) getenv('NASERY_TEST_PASSWORD');
        if (strlen($password) < 8) {
            $this->error('Set NASERY_TEST_PASSWORD (8+ characters, letters and numbers) in this shell first.');

            return self::FAILURE;
        }
        if (DB::table('users')->where('email', 'like', '%@nasery.test')->exists()) {
            $this->error('Test accounts already exist. Remove them first: php artisan nasery:test-data:purge --force');

            return self::FAILURE;
        }
        $admin = User::query()->where('account_type', AccountType::Admin->value)->where('status', 'active')->firstOrFail();

        $make = function (string $local, string $first, string $last, AccountType $type) use ($password): User {
            $user = new User;
            $user->forceFill([
                'email' => "{$local}@nasery.test", 'first_name' => $first, 'last_name' => $last,
                'account_type' => $type, 'password_hash' => $password, 'phone' => '0917 555 0'.random_int(100, 999),
            ])->save();
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user->refresh();
        };

        $client = $make('client', 'Tess', 'Client', AccountType::Client);
        $owner = $make('organizer', 'Oscar', 'Organizer', AccountType::Business);

        $org = $ctx->asUser($owner->id, fn () => app(OrganizerOnboarding::class)->create($owner, [
            'legal_name' => 'Test Events Co.', 'display_name' => 'Test Events Co.',
            'profile' => ['business_name' => 'Test Events Co.', 'description' => 'Test organizer for manual checks.',
                'location_text' => 'Poblacion, Davao City', 'latitude' => 7.0731, 'longitude' => 125.6128,
                'contact_email' => 'hello@organizer.nasery.test', 'contact_phone' => '0917 555 0100'],
        ]));
        config(['nasery.organizer_documents.required' => false]); // a manual-check account, no paperwork
        $ctx->asUser($admin->id, fn () => app(ApprovalWorkflow::class)->decide($org, ApprovalStatus::Approved, null));
        DB::table('organization_profiles')->where('organization_id', $org->id)->update(['is_published' => true]);

        foreach (['Event Coordination' => 40000, 'Catering' => 600, 'Hosting / Emcee' => 8000] as $service => $price) {
            $serviceId = DB::table('services')->whereNull('deleted_at')->where('name', $service)->value('id');
            $ctx->asUser($owner->id, fn () => app(OfferedServices::class)->create($org, ['service_id' => $serviceId, 'base_price' => $price, 'is_active' => true]));
        }
        $ctx->asUser($owner->id, function () use ($org) {
            foreach ([['Coordination package', 'flat', 40000], ['Buffet per guest', 'per_guest', 600], ['Emcee (4 hours)', 'flat', 8000]] as $i => [$name, $unit, $price]) {
                $org->priceOptions()->create(['name' => $name, 'pricing_unit' => $unit, 'price' => $price, 'is_active' => true, 'sort_order' => $i]);
            }
        });

        $this->info('Created client@nasery.test and organizer@nasery.test ('.$org->refresh()->slug.'). Password: the value of NASERY_TEST_PASSWORD.');

        return self::SUCCESS;
    }
}
