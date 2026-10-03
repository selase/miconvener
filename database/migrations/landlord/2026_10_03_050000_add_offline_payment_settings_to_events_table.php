<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->boolean('allow_offline_payments')->default(false)->after('requires_approval');
            $table->text('offline_payment_instructions')->nullable()->after('allow_offline_payments');
            $table->string('offline_payment_bank_name')->nullable()->after('offline_payment_instructions');
            $table->string('offline_payment_account_name')->nullable()->after('offline_payment_bank_name');
            $table->string('offline_payment_account_number')->nullable()->after('offline_payment_account_name');
            $table->string('offline_payment_momo_number')->nullable()->after('offline_payment_account_number');
            $table->string('offline_payment_momo_network')->nullable()->after('offline_payment_momo_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn([
                'allow_offline_payments',
                'offline_payment_instructions',
                'offline_payment_bank_name',
                'offline_payment_account_name',
                'offline_payment_account_number',
                'offline_payment_momo_number',
                'offline_payment_momo_network',
            ]);
        });
    }
};
