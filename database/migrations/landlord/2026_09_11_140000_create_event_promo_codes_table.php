<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_promo_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('event_id')->nullable(); // Nullable means global across the tenant
            $table->string('code', 64);
            $table->string('description', 255)->nullable();
            $table->string('discount_type', 32); // percentage, fixed_amount, complimentary
            $table->unsignedInteger('discount_value')->default(0); // percentage (1-100) or minor units (cents/pesewas)
            $table->string('currency', 3)->default('GHS');
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->unsignedInteger('max_per_attendee')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('applicable_ticket_type_ids')->nullable(); // null means all ticket types
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();

            $table->index(['tenant_id', 'code']);
            $table->index(['event_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_promo_codes');
    }
};
