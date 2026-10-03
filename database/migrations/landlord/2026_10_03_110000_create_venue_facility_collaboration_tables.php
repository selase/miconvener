<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('venue_facility_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUuid('venue_booking_id')->nullable()->constrained('venue_bookings')->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_type'); // host, planner
            $table->string('sender_name');
            $table->text('message');
            $table->string('attachment_path')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['venue_booking_id', 'created_at']);
            $table->index(['venue_booking_id', 'read_at']);
            $table->index(['event_id', 'created_at']);
        });

        Schema::connection('landlord')->create('venue_inspection_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUuid('venue_booking_id')->nullable()->constrained('venue_bookings')->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('store_listing_id')->nullable()->constrained('store_listings')->nullOnDelete();
            $table->string('type'); // check_in, check_out
            $table->foreignId('inspector_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('inspector_name');
            $table->string('inspector_role'); // host, planner
            $table->string('status')->default('passed'); // passed, flagged
            $table->json('checklist')->nullable();
            $table->text('general_notes')->nullable();
            $table->string('signed_by_name')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['venue_booking_id', 'type']);
            $table->index(['event_id', 'type']);
        });

        Schema::connection('landlord')->table('event_operation_tasks', function (Blueprint $table): void {
            $table->boolean('is_venue_task')->default(false)->index();
            $table->foreignUuid('venue_shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->index(['venue_shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_operation_tasks', function (Blueprint $table): void {
            $table->dropIndex(['venue_shop_id', 'status']);
            $table->dropConstrainedForeignId('venue_shop_id');
            $table->dropColumn('is_venue_task');
        });

        Schema::connection('landlord')->dropIfExists('venue_inspection_logs');
        Schema::connection('landlord')->dropIfExists('venue_facility_messages');
    }
};
