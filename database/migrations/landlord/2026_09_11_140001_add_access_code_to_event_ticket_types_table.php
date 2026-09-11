<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_ticket_types', function (Blueprint $table): void {
            $table->string('access_code', 64)->nullable()->after('is_active');
            $table->index('access_code');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_ticket_types', function (Blueprint $table): void {
            $table->dropIndex(['access_code']);
            $table->dropColumn('access_code');
        });
    }
};
