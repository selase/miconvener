<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Counting a live poll groups its answers by option. The only index on this
     * table starts with poll_id for a uniqueness check on respondent_token,
     * which does not serve that grouping.
     */
    public function up(): void
    {
        Schema::connection('landlord')->table('event_poll_responses', function (Blueprint $table): void {
            $table->index(['poll_id', 'option_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_poll_responses', function (Blueprint $table): void {
            $table->dropIndex(['poll_id', 'option_id']);
        });
    }
};
