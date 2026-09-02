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
        Schema::connection('landlord')->create('taxes', function (Blueprint $table): void {
            $table->id();
            $table->string('name'); // VAT, NHIL, etc.
            $table->decimal('rate', 5, 2); // percentage, e.g., 15.00
            $table->boolean('is_compound')->default(false); // If true, applies to subtotal + previous taxes
            $table->integer('priority')->default(0); // Order of application
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('taxes');
    }
};
