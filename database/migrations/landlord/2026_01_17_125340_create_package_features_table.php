<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('package_features', function (Blueprint $table): void {
            $table->id();
            // Pivot table doesn't strictly need a uuid, but we can keep ID for referencing if needed
            $table->foreignId('package_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->index()->constrained()->cascadeOnDelete();
            $table->string('value')->nullable(); // "true", "10", "unlimited"
            $table->timestamps();

            $table->unique(['package_id', 'feature_id']); // Ensure unique combination
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_features');
    }
};
