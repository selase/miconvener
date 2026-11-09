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
        Schema::connection('landlord')->create('provider_payout_liquidity', function (Blueprint $table): void {
            $table->string('account_fingerprint', 64)->primary();
            $table->bigInteger('remaining');
            $table->bigInteger('observed_balance');
            $table->timestampTz('observed_at');
            $table->boolean('blocked')->default(false);
            $table->timestampsTz();
        });
        Schema::connection('landlord')->create('payout_liquidity_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payout_id')->unique()->constrained('event_payouts')->restrictOnDelete();
            $table->string('account_fingerprint', 64);
            $table->string('provider_reference')->unique();
            $table->bigInteger('amount');
            $table->timestampTz('created_at');
            $table->timestampTz('reconciled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('payout_liquidity_allocations');
        Schema::connection('landlord')->dropIfExists('provider_payout_liquidity');
    }
};
