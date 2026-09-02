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
        $tableNames = config('permission.table_names');

        Schema::create('feature_permissions', function (Blueprint $table) use ($tableNames): void {
            $table->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained($tableNames['permissions'])->cascadeOnDelete();
            $table->primary(['feature_id', 'permission_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_permissions');
    }
};
