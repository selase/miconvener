<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_notification_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('rule_id')->nullable()->constrained('event_notification_rules')->nullOnDelete();

            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable()->index();
            $table->string('recipient_phone')->nullable()->index();
            $table->string('channel')->default('email'); // email, sms, whatsapp
            $table->string('status')->default('sent'); // sent, staged_omnichannel, failed, suppressed_quota, unsubscribed
            $table->string('subject')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('cost_billed')->default(0); // in minor units
            $table->json('metadata')->nullable();
            $table->dateTime('sent_at')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_notification_logs');
    }
};
