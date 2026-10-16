<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('community_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->string('subject');
            $table->text('body');
            $table->jsonb('channels');
            $table->jsonb('audience');
            $table->jsonb('estimate');
            $table->string('status')->default('draft');
            $table->string('pause_reason')->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('last_member_id')->nullable();
            $table->boolean('selection_complete')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['status', 'scheduled_at']);
        });
        Schema::connection('landlord')->create('community_message_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained('community_messages')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            $table->char('address_hash', 64);
            $table->text('address');
            $table->text('unsubscribe_token')->nullable();
            $table->string('status')->default('queued');
            $table->string('reason')->nullable();
            $table->foreignUuid('credit_receipt_id')->nullable()->constrained('prepaid_credit_receipts');
            $table->foreignUuid('sms_reservation_id')->nullable()->constrained('community_sms_reservations');
            $table->string('provider_reference')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'channel', 'address_hash'], 'community_message_address_unique');
            $table->index(['tenant_id', 'message_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('community_message_deliveries');
        Schema::connection('landlord')->dropIfExists('community_messages');
    }
};
