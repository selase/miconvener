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
        Schema::connection('landlord')->create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('number')->unique();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('due_at')->nullable();
            $table->string('status')->default('draft'); // draft, pending, paid, overdue, cancelled
            $table->string('currency', 3)->default('USD');
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('total', 20, 4)->default(0);
            $table->jsonb('tax_details')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        Schema::connection('landlord')->create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('invoice_id')->index();
            $table->string('metric')->nullable();
            $table->string('description');
            $table->decimal('quantity', 20, 4)->default(0);
            $table->decimal('unit_price', 20, 6)->default(0);
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('invoice_items');
        Schema::connection('landlord')->dropIfExists('invoices');
    }
};
