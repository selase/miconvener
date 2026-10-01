<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->foreignUuid('store_listing_id')
                ->nullable()
                ->after('plan_your_visit_content')
                ->constrained('store_listings')
                ->nullOnDelete();

            $table->foreignUuid('venue_booking_id')
                ->nullable()
                ->after('store_listing_id')
                ->constrained('venue_bookings')
                ->nullOnDelete();

            $table->index(['tenant_id', 'store_listing_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropForeign(['store_listing_id']);
            $table->dropForeign(['venue_booking_id']);
            $table->dropIndex(['tenant_id', 'store_listing_id']);
            $table->dropColumn(['store_listing_id', 'venue_booking_id']);
        });
    }
};
