<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_materials', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('event_sessions')->nullOnDelete();

            $table->string('title');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mime_type')->nullable();

            $table->unsignedInteger('download_limit')->default(3);
            $table->dateTime('release_at')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_materials');
    }
};
