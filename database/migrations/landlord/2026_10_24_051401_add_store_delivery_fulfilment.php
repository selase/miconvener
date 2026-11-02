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
        $db = $schema->getConnection();
        $db->statement('ALTER TABLE store_orders DROP CONSTRAINT commerce_order_values');
        $db->statement("ALTER TABLE store_orders ADD CONSTRAINT commerce_order_values CHECK (buyer_email = lower(trim(buyer_email)) AND length(buyer_email) > 3 AND principal_pesewas BETWEEN 0 AND 9007199254740991 AND platform_fee_pesewas BETWEEN 0 AND principal_pesewas AND gateway_estimate_pesewas >= 0 AND charged_pesewas BETWEEN principal_pesewas AND 9007199254740991 AND currency = 'GHS' AND offering_type = 'purchase' AND state IN ('quoted','awaiting_payment','confirmed','cancelled','review') AND payment_state IN ('not_required','awaiting','captured_review','paid','refund_pending','refunded') AND ledger_state IN ('not_applicable','pending','posted','review') AND fulfilment_state IN ('awaiting_preparation','ready','dispatched','collected','delivered','cancelled'))");
        $schema->table('store_fulfilment_arrangements', fn (Blueprint $table) => $table->unique(['id', 'order_id', 'shop_id'], 'store_arrangement_event_owner'));
        $schema->table('store_fulfilment_events', function (Blueprint $table): void {
            $table->uuid('arrangement_id')->nullable();
            $table->foreign(['arrangement_id', 'order_id', 'shop_id'], 'store_event_arrangement_owner')->references(['id', 'order_id', 'shop_id'])->on('store_fulfilment_arrangements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        $schema->table('store_fulfilment_events', function (Blueprint $table): void {
            $table->dropForeign('store_event_arrangement_owner');
            $table->dropColumn('arrangement_id');
        });
        $schema->table('store_fulfilment_arrangements', fn (Blueprint $table) => $table->dropUnique('store_arrangement_event_owner'));
        $db = $schema->getConnection();
        $db->statement('ALTER TABLE store_orders DROP CONSTRAINT commerce_order_values');
        $db->statement("ALTER TABLE store_orders ADD CONSTRAINT commerce_order_values CHECK (buyer_email = lower(trim(buyer_email)) AND length(buyer_email) > 3 AND principal_pesewas BETWEEN 0 AND 9007199254740991 AND platform_fee_pesewas BETWEEN 0 AND principal_pesewas AND gateway_estimate_pesewas >= 0 AND charged_pesewas BETWEEN principal_pesewas AND 9007199254740991 AND currency = 'GHS' AND offering_type = 'purchase' AND state IN ('quoted','awaiting_payment','confirmed','cancelled','review') AND payment_state IN ('not_required','awaiting','captured_review','paid','refund_pending','refunded') AND ledger_state IN ('not_applicable','pending','posted','review') AND fulfilment_state IN ('awaiting_preparation','ready','collected','cancelled'))");
    }
};
