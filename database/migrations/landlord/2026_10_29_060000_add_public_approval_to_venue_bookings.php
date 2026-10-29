<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval-first public venue requests: a request does not reserve time, a host
 * approval creates a bounded hold, and every transition leaves immutable
 * evidence. Existing rows keep a null flow version and so stay legacy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('landlord');

        $schema->table('store_listings', function (Blueprint $table): void {
            $table->jsonb('public_booking_policy')->nullable();
        });

        $schema->table('venue_bookings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('approval_flow_version')->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->uuid('submission_key')->nullable();
            $table->unique(['shop_id', 'submission_key'], 'venue_bookings_shop_submission_unique');
            $table->index(['store_listing_id', 'status', 'hold_expires_at'], 'venue_bookings_hold_lookup');
        });

        $schema->create('venue_booking_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('venue_bookings')->restrictOnDelete();
            $table->uuid('shop_id');
            $table->uuid('tenant_id');
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('buyer_email')->nullable();
            $table->string('action', 40);
            $table->uuid('operation_key');
            $table->string('request_hash', 64);
            $table->string('previous_status', 32)->nullable();
            $table->string('result_status', 32);
            $table->timestamp('hold_expires_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->string('delivery_state', 16)->default('queued');
            $table->unsignedSmallInteger('delivery_attempts')->default(0);
            $table->timestamp('delivery_claimed_at')->nullable();
            $table->timestamp('delivery_completed_at')->nullable();
            $table->string('delivery_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'operation_key'], 'venue_booking_action_operation_unique');
            $table->index(['tenant_id', 'action', 'created_at']);
        });

        $schema->getConnection()->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION venue_booking_action_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Venue booking action evidence is retained'; END IF;
    IF (to_jsonb(OLD) - ARRAY['delivery_state','delivery_attempts','delivery_claimed_at','delivery_completed_at','delivery_error','updated_at'])
        IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['delivery_state','delivery_attempts','delivery_claimed_at','delivery_completed_at','delivery_error','updated_at']) THEN
        RAISE EXCEPTION 'Venue booking action evidence cannot change';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER venue_booking_action_guard BEFORE UPDATE OR DELETE ON venue_booking_actions FOR EACH ROW EXECUTE FUNCTION venue_booking_action_guard();
SQL);
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        $connection = $schema->getConnection();

        if ($schema->hasTable('venue_booking_actions') && $connection->table('venue_booking_actions')->exists()) {
            throw new RuntimeException('Venue approval evidence must be retained; review this rollback manually.');
        }
        if ($connection->table('venue_bookings')->whereNotNull('approval_flow_version')->exists()) {
            throw new RuntimeException('Approval-first venue requests exist; review this rollback manually.');
        }

        $schema->dropIfExists('venue_booking_actions');
        $connection->unprepared('DROP FUNCTION IF EXISTS venue_booking_action_guard()');
        $schema->table('venue_bookings', function (Blueprint $table): void {
            $table->dropUnique('venue_bookings_shop_submission_unique');
            $table->dropIndex('venue_bookings_hold_lookup');
            $table->dropColumn(['approval_flow_version', 'hold_expires_at', 'submission_key']);
        });
        $schema->table('store_listings', fn (Blueprint $table) => $table->dropColumn('public_booking_policy'));
    }
};
