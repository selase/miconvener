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
        $schema->table('shops', fn (Blueprint $table) => $table->unique(['id', 'tenant_id'], 'commerce_shop_tenant_unique'));
        $schema->create('store_commercial_agreements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->unsignedInteger('version');
            $table->decimal('percentage', 5, 2);
            $table->bigInteger('cap_pesewas')->nullable();
            $table->string('fee_bearer', 20)->default('organizer');
            $table->decimal('gateway_estimate_rate', 5, 2);
            $table->string('currency', 3)->default('GHS');
            $table->string('settlement_mode', 30)->default('platform_default');
            $table->string('provider', 30)->default('paystack');
            $table->string('account_fingerprint', 64);
            $table->string('refund_gateway_fee_bearer', 20);
            $table->text('collection_terms');
            $table->text('refund_terms');
            $table->text('support_contact');
            $table->text('receipt_terms');
            $table->jsonb('payout_policy');
            $table->unsignedBigInteger('approved_by');
            $table->timestamp('approved_at');
            $table->timestamps();
            $table->unique(['shop_id', 'version']);
            $table->unique(['id', 'shop_id', 'tenant_id'], 'commerce_agreement_owner_unique');
            $table->foreign(['shop_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('shops')->restrictOnDelete();
        });
        $schema->create('store_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->uuid('agreement_id');
            $table->string('buyer_email');
            $table->uuid('member_id')->nullable();
            $table->uuid('submission_key');
            $table->string('cart_hash', 64);
            $table->string('currency', 3)->default('GHS');
            $table->string('offering_type', 20)->default('purchase');
            $table->bigInteger('principal_pesewas');
            $table->bigInteger('platform_fee_pesewas');
            $table->bigInteger('gateway_estimate_pesewas');
            $table->bigInteger('charged_pesewas');
            $table->string('fee_bearer', 20);
            $table->string('settlement_mode', 30);
            $table->string('provider', 30);
            $table->string('account_fingerprint', 64);
            $table->jsonb('seller_snapshot');
            $table->jsonb('terms_snapshot');
            $table->string('terms_hash', 64);
            $table->timestamp('expires_at');
            $table->string('state', 30)->default('quoted');
            $table->string('payment_state', 30)->default('awaiting');
            $table->string('ledger_state', 30)->default('pending');
            $table->string('fulfilment_state', 30)->default('awaiting_preparation');
            $table->string('review_reason')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'buyer_email', 'submission_key'], 'commerce_quote_submission_unique');
            $table->unique(['id', 'shop_id'], 'commerce_order_owner_unique');
            $table->index(['buyer_email', 'created_at']);
            $table->index(['state', 'expires_at']);
            $table->foreign(['agreement_id', 'shop_id', 'tenant_id'], 'commerce_order_agreement_fk')->references(['id', 'shop_id', 'tenant_id'])->on('store_commercial_agreements')->restrictOnDelete();
        });
        $schema->table('store_variants', fn (Blueprint $table) => $table->unique(['id', 'shop_id', 'listing_id'], 'commerce_variant_listing_unique'));
        $schema->create('store_order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('shop_id');
            $table->uuid('variant_id');
            $table->uuid('listing_id');
            $table->string('offering_type', 20)->default('purchase');
            $table->text('title_snapshot');
            $table->string('sku_snapshot', 64);
            $table->jsonb('options_snapshot');
            $table->integer('quantity');
            $table->bigInteger('unit_price_pesewas');
            $table->bigInteger('line_amount_pesewas');
            $table->timestamps();
            $table->unique(['order_id', 'variant_id']);
            $table->unique(['id', 'order_id', 'shop_id', 'variant_id'], 'commerce_line_allocation_unique');
            $table->foreign(['order_id', 'shop_id'])->references(['id', 'shop_id'])->on('store_orders')->restrictOnDelete();
            $table->foreign(['variant_id', 'shop_id', 'listing_id'], 'commerce_line_variant_fk')->references(['id', 'shop_id', 'listing_id'])->on('store_variants')->restrictOnDelete();
            $table->foreign('listing_id')->references('id')->on('store_listings')->restrictOnDelete();
        });
        $db = $schema->getConnection();
        $db->statement("ALTER TABLE store_commercial_agreements ADD CONSTRAINT commerce_agreement_values CHECK (percentage BETWEEN 0 AND 100 AND (cap_pesewas IS NULL OR cap_pesewas >= 0) AND gateway_estimate_rate BETWEEN 0 AND 100 AND fee_bearer IN ('organizer','attendee') AND refund_gateway_fee_bearer IN ('seller','platform') AND currency = 'GHS' AND settlement_mode = 'platform_default' AND provider = 'paystack')");
        $db->statement("ALTER TABLE store_orders ADD CONSTRAINT commerce_order_values CHECK (buyer_email = lower(trim(buyer_email)) AND length(buyer_email) > 3 AND principal_pesewas BETWEEN 0 AND 9007199254740991 AND platform_fee_pesewas BETWEEN 0 AND principal_pesewas AND gateway_estimate_pesewas >= 0 AND charged_pesewas BETWEEN principal_pesewas AND 9007199254740991 AND currency = 'GHS' AND offering_type = 'purchase' AND state IN ('quoted','awaiting_payment','confirmed','cancelled','review') AND payment_state IN ('not_required','awaiting','captured_review','paid','refund_pending','refunded') AND ledger_state IN ('not_applicable','pending','posted','review') AND fulfilment_state IN ('awaiting_preparation','ready','collected','cancelled'))");
        $db->statement("ALTER TABLE store_order_lines ADD CONSTRAINT commerce_line_values CHECK (offering_type = 'purchase' AND quantity BETWEEN 1 AND 99 AND unit_price_pesewas BETWEEN 0 AND 9007199254740991 AND line_amount_pesewas BETWEEN 0 AND 9007199254740991 AND line_amount_pesewas = quantity::numeric * unit_price_pesewas::numeric)");
        $db->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION commerce_snapshot_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Commerce snapshots are immutable';
END;
$$;
CREATE TRIGGER commerce_line_immutable BEFORE UPDATE OR DELETE ON store_order_lines FOR EACH ROW EXECUTE FUNCTION commerce_snapshot_immutable();
CREATE TRIGGER commerce_agreement_immutable BEFORE UPDATE OR DELETE ON store_commercial_agreements FOR EACH ROW EXECUTE FUNCTION commerce_snapshot_immutable();
CREATE OR REPLACE FUNCTION commerce_order_snapshot_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Order history cannot be deleted'; END IF;
    IF (to_jsonb(OLD) - ARRAY['state','payment_state','ledger_state','fulfilment_state','review_reason','accepted_at','paid_at','updated_at']) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['state','payment_state','ledger_state','fulfilment_state','review_reason','accepted_at','paid_at','updated_at']) THEN
        RAISE EXCEPTION 'Quoted ownership, prices and terms cannot change';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER commerce_order_snapshot_guard BEFORE UPDATE OR DELETE ON store_orders FOR EACH ROW EXECUTE FUNCTION commerce_order_snapshot_guard();
SQL);
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        $schema->dropIfExists('store_order_lines');
        $schema->table('store_variants', fn (Blueprint $table) => $table->dropUnique('commerce_variant_listing_unique'));
        $schema->dropIfExists('store_orders');
        $schema->dropIfExists('store_commercial_agreements');
        $schema->getConnection()->statement('DROP FUNCTION IF EXISTS commerce_order_snapshot_guard(), commerce_snapshot_immutable()');
        $schema->table('shops', fn (Blueprint $table) => $table->dropUnique('commerce_shop_tenant_unique'));
    }
};
