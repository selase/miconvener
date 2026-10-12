<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the platform's commission lands when an organiser takes payments on
     * their own Paystack account. Each organiser's subaccount points at one of
     * these accounts, so a move is gradual: the old account stays accepted
     * until accepted_until, while organisers are shown only the current one.
     */
    public function up(): void
    {
        Schema::connection('landlord')->create('commission_destinations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();

            $table->string('label');
            $table->string('currency', 3);
            $table->string('business_name');
            $table->string('settlement_bank')->nullable();
            $table->text('account_number');
            $table->boolean('is_active')->default(true);
            $table->timestamp('accepted_until')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('commission_destinations');
    }
};
