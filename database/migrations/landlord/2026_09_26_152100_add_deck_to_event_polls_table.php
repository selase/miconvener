<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_polls', function (Blueprint $table): void {
            $table->foreignUuid('deck_id')->nullable()->after('event_id')->constrained('poll_decks')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0)->after('deck_id');
            $table->index(['deck_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_polls', function (Blueprint $table): void {
            $table->dropIndex(['deck_id', 'position']);
            $table->dropConstrainedForeignId('deck_id');
            $table->dropColumn('position');
        });
    }
};
