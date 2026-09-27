<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'system';

    /**
     * Social sign-in identities (Google / Facebook / Apple) linked to
     * public.users. Kept in the private `laravel` schema like other
     * credential material.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id'); // public.users(id)
            $table->string('provider', 20);
            $table->string('provider_user_id');
            $table->string('provider_email')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
