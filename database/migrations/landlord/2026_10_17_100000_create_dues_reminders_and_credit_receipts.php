<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->create('prepaid_credit_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('feature');
            $table->jsonb('sources');
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('landlord')->create('dues_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('step');
            $table->string('channel');
            $table->jsonb('charge_ids');
            $table->string('status');
            $table->string('reason')->nullable();
            $table->string('provider_reference')->nullable();
            $table->foreignUuid('credit_receipt_id')->nullable()->constrained('prepaid_credit_receipts');
            $table->date('sent_for_date');
            $table->timestamps();
            $table->unique(['member_id', 'channel', 'sent_for_date']);
            $table->index(['tenant_id', 'channel', 'status', 'sent_for_date']);
        });
        Schema::connection('landlord')->table('community_settings', function (Blueprint $table): void {
            $table->boolean('reminders_enabled')->default(true);
            $table->jsonb('reminder_offsets')->default('{"before":7,"on_day":0,"after_7":7,"after_30":30}');
            $table->string('frequent_digest')->default('daily');
            $table->unsignedInteger('sms_monthly_limit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('dues_reminders');
        Schema::connection('landlord')->dropIfExists('prepaid_credit_receipts');
        Schema::connection('landlord')->table('community_settings', fn (Blueprint $table) => $table->dropColumn(['reminders_enabled', 'reminder_offsets', 'frequent_digest', 'sms_monthly_limit']));
    }
};
