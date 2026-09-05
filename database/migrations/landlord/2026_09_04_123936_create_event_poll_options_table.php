<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_poll_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('poll_id')->constrained('event_polls')->cascadeOnDelete();

            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_poll_options');
    }
};
