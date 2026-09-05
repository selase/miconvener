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
        Schema::connection('landlord')->create('events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('cover_image_path')->nullable();

            $table->string('status')->default('draft'); // draft, published, cancelled

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone')->default('Africa/Accra');

            $table->string('location_type')->default('in_person'); // in_person, virtual
            $table->string('address')->nullable();
            $table->string('virtual_link')->nullable();

            $table->unsignedInteger('capacity')->nullable();

            $table->bigInteger('ticket_price')->default(0); // minor units
            $table->string('currency')->default('GHS');

            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('events');
    }
};
