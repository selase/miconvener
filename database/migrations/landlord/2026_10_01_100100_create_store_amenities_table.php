<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('store_amenities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('category', 50); // power_climate, furniture, av_tech, facilities, catering_rules
            $table->string('slug', 100)->unique();
            $table->string('name');
            $table->string('icon', 100);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('store_amenities');
    }
};
