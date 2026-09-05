<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_sponsor_deliverables', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('sponsor_id')->constrained('event_sponsors')->cascadeOnDelete();

            $table->string('description');
            $table->boolean('is_done')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_sponsor_deliverables');
    }
};
