<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_payouts', function (Blueprint $table): void {
            $table->string('provider_reference')->nullable()->after('note');
            $table->string('failure_reason')->nullable()->after('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_payouts', function (Blueprint $table): void {
            $table->dropColumn(['provider_reference', 'failure_reason']);
        });
    }
};
