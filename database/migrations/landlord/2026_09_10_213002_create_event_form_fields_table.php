<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_form_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->index()->constrained('events')->cascadeOnDelete();

            $table->string('label');
            $table->string('field_key');
            $table->string('field_type')->default('text'); // radio, select, text, textarea, checkbox, number
            $table->string('help_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->json('options')->nullable(); // array of {label, value, price?}
            $table->json('conditional_logic')->nullable(); // {depends_on, operator, value}
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['event_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_form_fields');
    }
};
