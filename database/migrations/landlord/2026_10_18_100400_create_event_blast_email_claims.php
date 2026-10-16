<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_blast_email_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('blast_id')->constrained('event_blasts')->cascadeOnDelete();
            $table->char('address_hash', 64);
            $table->foreignUuid('recipient_id')->nullable()->constrained('event_blast_recipients');
            $table->foreignUuid('credit_receipt_id')->nullable()->constrained('prepaid_credit_receipts');
            $table->text('unsubscribe_token')->nullable();
            $table->string('status');
            $table->string('reason')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'blast_id', 'address_hash'], 'event_blast_email_address_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_blast_email_claims');
    }
};
