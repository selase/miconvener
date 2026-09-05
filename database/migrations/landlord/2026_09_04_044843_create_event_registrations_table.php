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
        Schema::connection('landlord')->create('event_registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            $table->string('full_name');
            $table->string('email');
            $table->string('phone')->nullable();

            $table->string('status')->default('pending_payment'); // pending_payment, confirmed, cancelled, checked_in

            $table->string('ticket_code')->nullable()->unique();
            $table->string('qr_token')->nullable()->unique();

            $table->bigInteger('amount')->default(0); // minor units, what was actually charged
            $table->string('currency')->default('GHS');
            $table->string('payment_reference')->nullable();

            $table->dateTime('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['tenant_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_registrations');
    }
};
