<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('payout_account_id')->constrained('tenant_payout_accounts')->cascadeOnDelete();

            $table->unsignedInteger('amount');
            $table->string('status')->default('scheduled'); // scheduled, paid
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_payouts');
    }
};
