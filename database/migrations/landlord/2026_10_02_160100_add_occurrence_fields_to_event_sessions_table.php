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
        Schema::connection('landlord')->table('event_sessions', function (Blueprint $table): void {
            $table->boolean('is_occurrence')->default(false)->after('sort_order');
            $table->date('occurrence_date')->nullable()->after('is_occurrence');
            $table->string('occurrence_status', 32)->default('scheduled')->after('occurrence_date');
            $table->text('notes')->nullable()->after('occurrence_status');
            $table->string('presentation_url')->nullable()->after('notes');

            $table->index(['event_id', 'is_occurrence', 'occurrence_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->table('event_sessions', function (Blueprint $table): void {
            $table->dropIndex(['event_id', 'is_occurrence', 'occurrence_date']);
            $table->dropColumn([
                'is_occurrence',
                'occurrence_date',
                'occurrence_status',
                'notes',
                'presentation_url',
            ]);
        });
    }
};
