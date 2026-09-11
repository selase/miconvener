<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_abstract_authors', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('abstract_id')->constrained('event_abstracts')->cascadeOnDelete();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('affiliation');
            $table->string('country')->nullable();
            $table->boolean('is_presenting')->default(false);
            $table->boolean('is_corresponding')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['abstract_id', 'sort_order']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_abstract_authors');
    }
};
