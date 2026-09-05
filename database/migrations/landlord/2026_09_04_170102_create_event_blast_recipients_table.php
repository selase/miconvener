<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_blast_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('blast_id')->constrained('event_blasts')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained('event_registrations')->cascadeOnDelete();

            $table->timestamp('opened_at')->nullable();

            $table->timestamps();

            $table->unique(['blast_id', 'registration_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_blast_recipients');
    }
};
