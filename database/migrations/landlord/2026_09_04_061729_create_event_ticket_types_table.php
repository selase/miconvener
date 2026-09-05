<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_ticket_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('price')->default(0); // minor units, shares the event's currency
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['event_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_ticket_types');
    }
};
