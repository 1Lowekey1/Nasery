<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'system';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            // users.id of the caller, or "guest" for public endpoints.
            $table->string('scope', 64);
            $table->string('key', 255);
            $table->string('method', 10);
            $table->string('path', 255);
            // sha256 of method|path|raw body: same key + different payload = 409.
            $table->char('fingerprint', 64);
            $table->string('status', 16); // processing | completed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->text('response_headers')->nullable();
            $table->timestampTz('locked_until');
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz(6);

            $table->unique(['scope', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
