<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('store_listing_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('store_listings')->cascadeOnDelete();
            $table->string('media_type', 50)->default('photo'); // photo, floor_plan_pdf
            $table->string('file_path');
            $table->string('title')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->string('status', 32)->default('approved'); // pending, approved, rejected
            $table->timestamps();

            $table->index(['listing_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('store_listing_media');
    }
};
