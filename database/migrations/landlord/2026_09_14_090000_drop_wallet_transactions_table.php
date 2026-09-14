<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * wallet_transactions came in with a prepaid "credits" wallet from a previous
 * product built on the same starter kit. Its service, model, enum, routes,
 * Livewire component and config were never part of this repository, so nothing
 * could ever write to it; production held no rows. Prepaid credits will be
 * designed afresh, in cedis and against the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->dropIfExists('wallet_transactions');
    }

    public function down(): void
    {
        Schema::connection('landlord')->create('wallet_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('currency', 3)->default('USD');
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }
};
