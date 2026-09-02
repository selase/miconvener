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
        Schema::create('tenant_migration_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->string('migration_path');
            $table->integer('batch');
            $table->string('status'); // success/failed
            $table->text('output')->nullable();
            $table->text('exception')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_migration_runs');
    }
};
