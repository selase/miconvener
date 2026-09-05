<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_registration_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained('event_registrations')->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained('event_sessions')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['registration_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_registration_sessions');
    }
};
