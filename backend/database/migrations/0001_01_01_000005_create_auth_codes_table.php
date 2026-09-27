<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'system';

    /**
     * One-time 6-digit codes (email verification, password reset). Credential
     * material, so it lives in the private `laravel` schema: not exposed by
     * the Supabase Data API and not readable by the nasery_app role.
     */
    public function up(): void
    {
        Schema::create('auth_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id'); // public.users(id)
            $table->string('purpose', 32); // App\Enums\AuthCodePurpose
            $table->char('code_hash', 64); // HMAC-SHA256 of the code, never the code
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('last_sent_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            // One live code per user and purpose; re-sending replaces it.
            $table->unique(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_codes');
    }
};
