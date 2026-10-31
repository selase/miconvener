<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('landlord');

        $schema->create('venue_quote_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->uuid('booking_id');
            $table->uuid('quote_id');
            $table->string('kind', 12);
            $table->string('state', 12)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['quote_id', 'kind']);
            $table->index(['state', 'next_attempt_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('venue_bookings')->restrictOnDelete();
            $table->foreign('quote_id')->references('id')->on('venue_financial_quotes')->restrictOnDelete();
        });
        $schema->getConnection()->statement("ALTER TABLE venue_quote_notices ADD CONSTRAINT venue_quote_notice_values CHECK (kind IN ('issued','withdrawn') AND state IN ('queued','sending','retry','sent','failed'))");
    }

    public function down(): void
    {
        if (Schema::connection('landlord')->hasTable('venue_quote_notices') && Schema::connection('landlord')->getConnection()->table('venue_quote_notices')->exists()) {
            throw new RuntimeException('Quote notices are a delivery record and are not dropped once they exist.');
        }
        Schema::connection('landlord')->dropIfExists('venue_quote_notices');
    }
};
