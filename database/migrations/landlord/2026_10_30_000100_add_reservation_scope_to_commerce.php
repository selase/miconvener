<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LINE_AMOUNTS = 'unit_price_pesewas BETWEEN 0 AND 9007199254740991 AND line_amount_pesewas BETWEEN 0 AND 9007199254740991 AND line_amount_pesewas = quantity::numeric * unit_price_pesewas::numeric';

    private const ORDER_VALUES = "buyer_email = lower(trim(buyer_email)) AND length(buyer_email) > 3 AND principal_pesewas BETWEEN 0 AND 9007199254740991 AND platform_fee_pesewas BETWEEN 0 AND principal_pesewas AND gateway_estimate_pesewas >= 0 AND charged_pesewas BETWEEN principal_pesewas AND 9007199254740991 AND currency = 'GHS' AND %s AND state IN ('quoted','awaiting_payment','confirmed','cancelled','review') AND payment_state IN ('not_required','awaiting','captured_review','paid','refund_pending','refunded') AND ledger_state IN ('not_applicable','pending','posted','review') AND fulfilment_state IN ('awaiting_preparation','ready','dispatched','collected','delivered','cancelled')";

    public function up(): void
    {
        $schema = Schema::connection('landlord');
        $db = $schema->getConnection();

        $schema->table('venue_bookings', fn (Blueprint $table) => $table->unique(['id', 'shop_id', 'tenant_id'], 'venue_booking_owner_unique'));

        $schema->table('store_commercial_agreements', function (Blueprint $table): void {
            $table->string('offering_scope', 20)->default('purchase');
            $table->unique(['id', 'shop_id', 'tenant_id', 'offering_scope'], 'commerce_agreement_scope_owner_unique');
        });
        $db->statement("ALTER TABLE store_commercial_agreements ADD CONSTRAINT commerce_agreement_scope CHECK (offering_scope IN ('purchase','reservation'))");

        $schema->table('store_orders', fn (Blueprint $table) => $table->dropForeign('commerce_order_agreement_fk'));
        $db->statement('ALTER TABLE store_orders ADD CONSTRAINT commerce_order_agreement_fk FOREIGN KEY (agreement_id, shop_id, tenant_id, offering_type) REFERENCES store_commercial_agreements (id, shop_id, tenant_id, offering_scope) ON DELETE RESTRICT');
        $db->statement('ALTER TABLE store_orders DROP CONSTRAINT commerce_order_values');
        $db->statement('ALTER TABLE store_orders ADD CONSTRAINT commerce_order_values CHECK ('.sprintf(self::ORDER_VALUES, "offering_type IN ('purchase','reservation')").')');

        $schema->table('store_order_lines', function (Blueprint $table): void {
            $table->uuid('booking_id')->nullable();
            $table->foreign('booking_id')->references('id')->on('venue_bookings')->restrictOnDelete();
        });
        $db->statement('ALTER TABLE store_order_lines ALTER COLUMN variant_id DROP NOT NULL');
        $db->statement('ALTER TABLE store_order_lines DROP CONSTRAINT commerce_line_values');
        $db->statement("ALTER TABLE store_order_lines ADD CONSTRAINT commerce_line_values CHECK ((offering_type = 'purchase' AND variant_id IS NOT NULL AND booking_id IS NULL AND quantity BETWEEN 1 AND 10000 AND ".self::LINE_AMOUNTS.") OR (offering_type = 'reservation' AND variant_id IS NULL AND booking_id IS NOT NULL AND quantity = 1 AND ".self::LINE_AMOUNTS.'))');
        $db->statement("CREATE UNIQUE INDEX commerce_reservation_line_unique ON store_order_lines (order_id) WHERE offering_type = 'reservation'");
        $db->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION commerce_order_line_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM store_orders o WHERE o.id = NEW.order_id AND o.offering_type = NEW.offering_type) THEN
        RAISE EXCEPTION 'An order line must be of the same type as its order';
    END IF;
    IF NEW.offering_type = 'reservation' AND NOT EXISTS (SELECT 1 FROM venue_bookings b WHERE b.id = NEW.booking_id AND b.shop_id = NEW.shop_id AND b.store_listing_id = NEW.listing_id) THEN
        RAISE EXCEPTION 'A reservation line must name the booked venue of the same seller';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER commerce_order_line_source_guard BEFORE INSERT ON store_order_lines FOR EACH ROW EXECUTE FUNCTION commerce_order_line_source_guard();
SQL);
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        $db = $schema->getConnection();
        if ($db->table('store_orders')->where('offering_type', 'reservation')->exists() || $db->table('store_commercial_agreements')->where('offering_scope', 'reservation')->exists()) {
            throw new RuntimeException('Reservation orders or agreements exist; retain them and review this rollback manually.');
        }

        $db->statement('DROP TRIGGER commerce_order_line_source_guard ON store_order_lines');
        $db->statement('DROP FUNCTION commerce_order_line_source_guard()');
        $db->statement('DROP INDEX commerce_reservation_line_unique');
        $db->statement('ALTER TABLE store_order_lines DROP CONSTRAINT commerce_line_values');
        $schema->table('store_order_lines', function (Blueprint $table): void {
            $table->dropForeign(['booking_id']);
            $table->dropColumn('booking_id');
        });
        $db->statement("ALTER TABLE store_order_lines ADD CONSTRAINT commerce_line_values CHECK (offering_type = 'purchase' AND quantity BETWEEN 1 AND 10000 AND ".self::LINE_AMOUNTS.')');
        $db->statement('ALTER TABLE store_order_lines ALTER COLUMN variant_id SET NOT NULL');
        $db->statement('ALTER TABLE store_orders DROP CONSTRAINT commerce_order_values');
        $db->statement('ALTER TABLE store_orders ADD CONSTRAINT commerce_order_values CHECK ('.sprintf(self::ORDER_VALUES, "offering_type = 'purchase'").')');
        $db->statement('ALTER TABLE store_orders DROP CONSTRAINT commerce_order_agreement_fk');
        $schema->table('store_orders', fn (Blueprint $table) => $table->foreign(['agreement_id', 'shop_id', 'tenant_id'], 'commerce_order_agreement_fk')->references(['id', 'shop_id', 'tenant_id'])->on('store_commercial_agreements')->restrictOnDelete());
        $db->statement('ALTER TABLE store_commercial_agreements DROP CONSTRAINT commerce_agreement_scope');
        $schema->table('store_commercial_agreements', function (Blueprint $table): void {
            $table->dropUnique('commerce_agreement_scope_owner_unique');
            $table->dropColumn('offering_scope');
        });
        $schema->table('venue_bookings', fn (Blueprint $table) => $table->dropUnique('venue_booking_owner_unique'));
    }
};
