<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('store_listings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('listing_kind', 50)->default('venue'); // venue, rental, service, merchandise
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('rental_price_pesewas')->default(0);
            $table->string('pricing_model', 50)->default('per_day'); // per_day, per_half_day, per_hour, flat_rate
            $table->string('price_visibility', 50)->default('public'); // public, on_request
            $table->unsignedBigInteger('security_deposit_pesewas')->nullable();
            $table->jsonb('capacity_breakdown')->default('{}'); // {"theater": 600, "banquet": 350, "cocktail": 800, "classroom": 250}
            $table->decimal('floor_area_sqm', 8, 2)->nullable();
            $table->decimal('ceiling_height_meters', 5, 2)->nullable();
            $table->jsonb('rules_and_policies')->default('{}'); // {"outside_catering": true, "curfew": "23:00"}
            $table->string('status', 50)->default('draft'); // draft, published, suspended
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['shop_id', 'slug']);
            $table->index(['status', 'listing_kind']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('store_listings');
    }
};
