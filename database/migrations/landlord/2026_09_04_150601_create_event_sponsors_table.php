<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_sponsors', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            $table->string('name');
            $table->string('tier')->default('supporting'); // headline, supporting, partner
            $table->string('logo_path')->nullable();
            $table->string('booth')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->unsignedInteger('amount')->default(0);
            $table->string('currency', 3)->default('GHS');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['event_id', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_sponsors');
    }
};
