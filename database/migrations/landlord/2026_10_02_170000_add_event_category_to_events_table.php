<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The database schema has events on landlord.
     */
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            if (! Schema::connection('landlord')->hasColumn('events', 'event_category')) {
                $table->string('event_category', 32)->default('general')->index()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            if (Schema::connection('landlord')->hasColumn('events', 'event_category')) {
                $table->dropIndex(['event_category']);
                $table->dropColumn('event_category');
            }
        });
    }
};
