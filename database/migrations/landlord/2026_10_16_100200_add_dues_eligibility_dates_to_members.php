<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('members', function (Blueprint $table): void {
            $table->date('active_since')->nullable();
            $table->date('lapsed_on')->nullable();
            $table->date('rejoined_on')->nullable();
        });
        DB::connection('landlord')->statement('UPDATE members SET active_since = COALESCE(joined_on, created_at::date)');
        Schema::connection('landlord')->table('community_settings', function (Blueprint $table): void {
            $table->date('last_generated_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('community_settings', function (Blueprint $table): void {
            $table->dropColumn('last_generated_on');
        });
        Schema::connection('landlord')->table('members', function (Blueprint $table): void {
            $table->dropColumn(['active_since', 'lapsed_on', 'rejoined_on']);
        });
    }
};
