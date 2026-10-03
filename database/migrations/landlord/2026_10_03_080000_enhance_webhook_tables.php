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
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            if (! Schema::hasColumn('webhook_endpoints', 'name')) {
                $table->string('name', 120)->nullable()->after('tenant_id');
            }
            if (! Schema::hasColumn('webhook_endpoints', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
        });

        Schema::table('webhook_calls', function (Blueprint $table): void {
            if (! Schema::hasColumn('webhook_calls', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->nullable()->after('status');
            }

            $table->index(['webhook_endpoint_id', 'created_at'], 'idx_webhook_calls_endpoint_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_calls', function (Blueprint $table): void {
            $table->dropIndex('idx_webhook_calls_endpoint_created');
            if (Schema::hasColumn('webhook_calls', 'duration_ms')) {
                $table->dropColumn('duration_ms');
            }
        });

        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            if (Schema::hasColumn('webhook_endpoints', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('webhook_endpoints', 'name')) {
                $table->dropColumn('name');
            }
        });
    }
};
