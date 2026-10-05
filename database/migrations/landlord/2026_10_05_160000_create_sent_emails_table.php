<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of event emails sent straight to attendees (tickets, payment
 * invites, approvals...), which were otherwise sent without a trace, so
 * support can answer "did they get it?". Announcements and automated
 * notifications are recorded elsewhere and are not repeated here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('sent_emails', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('event_id')->nullable();
            $table->string('recipient_email');
            $table->string('subject')->nullable();
            $table->string('mailable', 120);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index('recipient_email');
            $table->index(['tenant_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('sent_emails');
    }
};
