<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('tenant_payout_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();

            $table->string('type'); // mobile_money, bank
            $table->string('label');
            $table->string('account_name');
            $table->text('account_number_encrypted');
            $table->boolean('is_verified')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('tenant_payout_accounts');
    }
};
