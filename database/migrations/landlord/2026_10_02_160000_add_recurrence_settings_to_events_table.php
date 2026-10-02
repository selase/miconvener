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
            $table->boolean('is_recurring')->default(false)->after('show_contributor_amounts');
            $table->string('recurrence_pattern', 32)->nullable()->after('is_recurring');
            $table->json('recurrence_days')->nullable()->after('recurrence_pattern');
            $table->string('recurrence_time_start', 8)->nullable()->after('recurrence_days');
            $table->string('recurrence_time_end', 8)->nullable()->after('recurrence_time_start');
            $table->unsignedSmallInteger('recurrence_interval')->default(1)->after('recurrence_time_end');
            $table->date('recurrence_until')->nullable()->after('recurrence_interval');
            $table->unsignedSmallInteger('recurrence_auto_generate_weeks')->default(4)->after('recurrence_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn([
                'is_recurring',
                'recurrence_pattern',
                'recurrence_days',
                'recurrence_time_start',
                'recurrence_time_end',
                'recurrence_interval',
                'recurrence_until',
                'recurrence_auto_generate_weeks',
            ]);
        });
    }
};
