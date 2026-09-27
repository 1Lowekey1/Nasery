<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'system';

    /**
     * B13: delivery log for ticket e-mails, which often go to guests without
     * a NaSeRy account (public.notifications needs a user). Lets the guest
     * list show "Emailed 2:14 PM" / "Failed" and resend. Private `laravel`
     * schema, like jobs and cache; not readable by nasery_app.
     */
    public function up(): void
    {
        Schema::create('mail_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 40);            // ticket | ticket_reminder
            $table->string('channel', 10)->default('email');
            $table->string('recipient', 254)->nullable();
            $table->string('subject', 200);
            $table->uuid('event_id')->nullable();  // public.events(id)
            $table->uuid('organization_id')->nullable();
            $table->uuid('session_id')->nullable(); // reminders: one per ticket per session
            $table->jsonb('ticket_ids');
            $table->string('status', 12);          // queued | sent | failed | suppressed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['event_id', 'created_at']);
            $table->index(['kind', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_deliveries');
    }
};
