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
        Schema::create('webhook_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            // Actually foreignIdFor respects the model's key type in newer Laravel versions, IF the model instance is available or inspection works.
            // BUT simpler is to just use foreignUuid to be explicit since we know it is UUID.
            // Let's replace foreignIdFor with foreignUuid('webhook_endpoint_id')

            $table->string('event_name')->nullable();
            $table->json('payload')->nullable();
            $table->integer('status')->nullable(); // HTTP status
            $table->text('response')->nullable();
            $table->text('exception')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_calls');
    }
};
