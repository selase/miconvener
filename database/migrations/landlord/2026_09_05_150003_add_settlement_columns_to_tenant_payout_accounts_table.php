<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('tenant_payout_accounts', function (Blueprint $table): void {
            $table->string('bank_code')->nullable()->after('type');
            $table->string('recipient_code')->nullable()->after('is_verified');
            $table->string('resolved_account_name')->nullable()->after('recipient_code');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenant_payout_accounts', function (Blueprint $table): void {
            $table->dropColumn(['bank_code', 'recipient_code', 'resolved_account_name']);
        });
    }
};
