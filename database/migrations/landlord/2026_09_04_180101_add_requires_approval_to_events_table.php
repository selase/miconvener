<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->boolean('requires_approval')->default(false)->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn('requires_approval');
        });
    }
};
