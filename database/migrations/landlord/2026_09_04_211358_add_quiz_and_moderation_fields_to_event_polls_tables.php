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
            $table->unsignedInteger('timer_seconds')->nullable()->after('type');
            $table->unsignedInteger('points')->default(10)->after('timer_seconds');
            $table->timestamp('went_live_at')->nullable()->after('status');
            $table->boolean('requires_moderation')->default(false)->after('went_live_at');
        });

        Schema::connection('landlord')->table('event_poll_options', function (Blueprint $table): void {
            $table->boolean('is_correct')->default(false)->after('label');
        });

        Schema::connection('landlord')->table('event_poll_responses', function (Blueprint $table): void {
            $table->string('respondent_name')->nullable()->after('respondent_token');
            $table->boolean('is_correct')->nullable()->after('respondent_name');
            $table->unsignedInteger('points_awarded')->default(0)->after('is_correct');
            $table->boolean('is_approved')->nullable()->default(true)->after('points_awarded');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_poll_responses', function (Blueprint $table): void {
            $table->dropColumn(['respondent_name', 'is_correct', 'points_awarded', 'is_approved']);
        });

        Schema::connection('landlord')->table('event_poll_options', function (Blueprint $table): void {
            $table->dropColumn('is_correct');
        });

        Schema::connection('landlord')->table('event_polls', function (Blueprint $table): void {
            $table->dropColumn(['timer_seconds', 'points', 'went_live_at', 'requires_moderation']);
        });
    }
};
