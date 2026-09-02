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
        Schema::connection('landlord')->create('plan_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->foreignId('to_package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->string('type'); // upgrade, downgrade, cancel, reactivate
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('plan_changes');
    }
};
