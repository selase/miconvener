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
        Schema::create('tenant_features', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('feature_key');
            $table->boolean('enabled')->default(false);
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'feature_key']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_features');
    }
};
