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
        Schema::connection('landlord')->create('usage_limits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->string('metric');
            $table->decimal('limit_value', 20, 4);
            $table->string('period')->default('month'); // daily, monthly
            $table->integer('alert_threshold')->default(80); // percentage, e.g., 80%
            $table->boolean('block_on_limit')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_alert_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('usage_limits');
    }
};
