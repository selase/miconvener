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
            $table->boolean('allow_contributions')->default(false)->after('speaker_slide_policy');
            $table->string('contribution_title')->nullable()->after('allow_contributions');
            $table->text('contribution_description')->nullable()->after('contribution_title');
            $table->json('contribution_presets')->nullable()->after('contribution_description');
            $table->unsignedInteger('contribution_min_amount_pesewas')->default(100)->after('contribution_presets');
            $table->unsignedBigInteger('contribution_goal_amount_pesewas')->nullable()->after('contribution_min_amount_pesewas');
            $table->boolean('show_tribute_wall')->default(true)->after('contribution_goal_amount_pesewas');
            $table->boolean('show_contributor_amounts')->default(false)->after('show_tribute_wall');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn([
                'allow_contributions',
                'contribution_title',
                'contribution_description',
                'contribution_presets',
                'contribution_min_amount_pesewas',
                'contribution_goal_amount_pesewas',
                'show_tribute_wall',
                'show_contributor_amounts',
            ]);
        });
    }
};
