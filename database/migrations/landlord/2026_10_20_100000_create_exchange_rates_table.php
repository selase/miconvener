<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every exchange rate fetched for regional pricing, so the app always has the
 * last known rate and each USD-priced charge can be traced to the rate used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->char('base', 3);
            $table->char('quote', 3);
            $table->decimal('rate', 12, 6);
            $table->date('as_of');
            $table->string('source', 32);
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->unique(['base', 'quote', 'as_of', 'source']);
            $table->index(['base', 'quote', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('exchange_rates');
    }
};
