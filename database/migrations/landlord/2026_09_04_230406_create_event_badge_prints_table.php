<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_badge_prints', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained('event_registrations')->cascadeOnDelete();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['registration_id', 'created_at']);
            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_badge_prints');
    }
};
