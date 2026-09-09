<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A transfer hands a ticket to someone else irreversibly, so it is staged
     * here and only applied once a code sent to the CURRENT holder's address is
     * returned. Holding the link is no longer enough; you must hold the inbox
     * the ticket was issued to.
     */
    public function up(): void
    {
        Schema::connection('landlord')->create('event_registration_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('registration_id')->constrained('event_registrations')->cascadeOnDelete();

            $table->string('to_full_name');
            $table->string('to_email');
            $table->string('to_phone')->nullable();

            // Hashed: a leaked database row must not be a usable transfer code.
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['registration_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_registration_transfers');
    }
};
