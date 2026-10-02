<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('store_listing_amenities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('store_listings')->cascadeOnDelete();
            $table->foreignUuid('amenity_id')->constrained('store_amenities')->cascadeOnDelete();
            $table->boolean('is_included')->default(true); // true = included in base price, false = excluded / optional
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['listing_id', 'amenity_id']);
            $table->index(['listing_id', 'is_included']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('store_listing_amenities');
    }
};
