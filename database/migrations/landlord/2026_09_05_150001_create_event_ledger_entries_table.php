<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->nullable()->constrained('event_registrations')->nullOnDelete();
            $table->foreignUuid('payout_id')->nullable()->constrained('event_payouts')->nullOnDelete();

            $table->string('type'); // charge, refund, payout
            $table->unsignedInteger('gross_amount');
            $table->unsignedInteger('gateway_fee_amount');
            $table->unsignedInteger('commission_amount');
            $table->integer('net_amount'); // signed: a refund's net is stored as a negative reversal
            $table->string('currency');
            $table->string('provider');
            $table->string('provider_reference')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_ledger_entries');
    }
};
