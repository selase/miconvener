<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff room booking: who a room is for, whether staff requests need approval,
 * which bookings are internal (free, for the organisation's own staff), who is
 * staff, and the email domains an organisation has proven it owns. Every
 * default keeps today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('members', function (Blueprint $table): void {
            $table->boolean('is_staff')->default(false);
        });

        Schema::connection('landlord')->table('store_listings', function (Blueprint $table): void {
            $table->string('audience', 16)->default('public');
            $table->boolean('requires_approval')->default(true);
            $table->index(['audience', 'status']);
        });

        Schema::connection('landlord')->table('venue_bookings', function (Blueprint $table): void {
            $table->string('kind', 16)->default('marketplace');
            $table->foreignUuid('requested_for_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('entered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('department')->nullable();
            $table->text('purpose')->nullable();
            $table->text('decline_reason')->nullable();
            $table->index(['store_listing_id', 'kind', 'status']);
        });

        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->jsonb('staff_email_domains')->default('[]');
            $table->jsonb('booking_limits')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenants', fn (Blueprint $table) => $table->dropColumn(['staff_email_domains', 'booking_limits']));
        Schema::connection('landlord')->table('venue_bookings', function (Blueprint $table): void {
            $table->dropIndex(['store_listing_id', 'kind', 'status']);
            $table->dropConstrainedForeignId('requested_for_member_id');
            $table->dropConstrainedForeignId('entered_by_user_id');
            $table->dropColumn(['kind', 'department', 'purpose', 'decline_reason']);
        });
        Schema::connection('landlord')->table('store_listings', function (Blueprint $table): void {
            $table->dropIndex(['audience', 'status']);
            $table->dropColumn(['audience', 'requires_approval']);
        });
        Schema::connection('landlord')->table('members', fn (Blueprint $table) => $table->dropColumn('is_staff'));
    }
};
