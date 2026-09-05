<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('speakers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();

            $table->string('name');
            $table->string('title')->nullable();
            $table->string('organization')->nullable();
            $table->text('bio')->nullable();
            $table->string('photo_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('speakers');
    }
};
