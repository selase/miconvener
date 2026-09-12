<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_notification_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            $table->string('name');
            $table->string('target_role')->default('attendee'); // attendee, speaker, organizer
            $table->string('target_audience')->default('all'); // all, confirmed, checked_in, ticket_type:{id}, group:{id}, speakers
            $table->string('trigger_type')->default('scheduled_offset'); // scheduled_offset, on_registration, on_checkin, on_materials_uploaded
            $table->string('offset_direction')->default('before'); // before, after
            $table->integer('offset_amount')->default(1);
            $table->string('offset_unit')->default('days'); // days, hours, minutes

            $table->json('channels'); // ['email'], ['email', 'sms'], ['whatsapp']
            $table->string('subject');
            $table->text('body_template');

            $table->boolean('is_active')->default(true);
            $table->dateTime('last_dispatched_at')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'is_active']);
            $table->index(['event_id', 'trigger_type']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_notification_rules');
    }
};
