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
        $schema->table('store_orders', fn (Blueprint $table) => $table->unique(['id', 'shop_id', 'tenant_id'], 'commerce_order_tenant_owner_unique'));
        $schema->create('store_seller_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->unique();
            $table->uuid('shop_id');
            $table->uuid('tenant_id');
            $table->unsignedBigInteger('issued_by');
            $table->string('issuer_name');
            $table->uuid('operation_key');
            $table->string('request_hash', 64);
            $table->jsonb('snapshot');
            $table->timestamp('withdrawn_at')->nullable();
            $table->unsignedBigInteger('withdrawn_by')->nullable();
            $table->string('withdrawal_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'operation_key']);
            $table->foreign(['order_id', 'shop_id', 'tenant_id'], 'seller_quote_order_owner_fk')->references(['id', 'shop_id', 'tenant_id'])->on('store_orders')->restrictOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });
        $db = $schema->getConnection();
        $db->statement('ALTER TABLE store_order_lines DROP CONSTRAINT commerce_line_values');
        $db->statement("ALTER TABLE store_order_lines ADD CONSTRAINT commerce_line_values CHECK (offering_type = 'purchase' AND quantity BETWEEN 1 AND 10000 AND unit_price_pesewas BETWEEN 0 AND 9007199254740991 AND line_amount_pesewas BETWEEN 0 AND 9007199254740991 AND line_amount_pesewas = quantity::numeric * unit_price_pesewas::numeric)");
        $db->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION seller_quote_snapshot_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Seller quote evidence is retained'; END IF;
    IF (to_jsonb(OLD) - ARRAY['withdrawn_at','withdrawn_by','withdrawal_reason','updated_at']) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['withdrawn_at','withdrawn_by','withdrawal_reason','updated_at']) THEN
        RAISE EXCEPTION 'Issued quote ownership and terms cannot change';
    END IF;
    IF OLD.withdrawn_at IS NOT NULL AND (OLD.withdrawn_at,OLD.withdrawn_by,OLD.withdrawal_reason) IS DISTINCT FROM (NEW.withdrawn_at,NEW.withdrawn_by,NEW.withdrawal_reason) THEN
        RAISE EXCEPTION 'Quote withdrawal evidence is immutable';
    END IF;
    IF (NEW.withdrawn_at IS NULL) IS DISTINCT FROM (NEW.withdrawn_by IS NULL) OR (NEW.withdrawn_at IS NULL) IS DISTINCT FROM (NEW.withdrawal_reason IS NULL) THEN
        RAISE EXCEPTION 'Quote withdrawal requires actor, timestamp and reason';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER seller_quote_snapshot_guard BEFORE UPDATE OR DELETE ON store_seller_quotes FOR EACH ROW EXECUTE FUNCTION seller_quote_snapshot_guard();
SQL);
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        if ($schema->hasTable('store_seller_quotes') && $schema->getConnection()->table('store_seller_quotes')->exists()) {
            throw new RuntimeException('Issued quote evidence must be retained; review this rollback manually.');
        }
        if ($schema->getConnection()->table('store_order_lines')->where('quantity', '>', 99)->exists()) {
            throw new RuntimeException('Retain historical large-quantity orders; review this migration rollback manually.');
        }
        $schema->dropIfExists('store_seller_quotes');
        $schema->table('store_orders', fn (Blueprint $table) => $table->dropUnique('commerce_order_tenant_owner_unique'));
        $db = $schema->getConnection();
        $db->statement('DROP FUNCTION IF EXISTS seller_quote_snapshot_guard()');
        $db->statement('ALTER TABLE store_order_lines DROP CONSTRAINT commerce_line_values');
        $db->statement("ALTER TABLE store_order_lines ADD CONSTRAINT commerce_line_values CHECK (offering_type = 'purchase' AND quantity BETWEEN 1 AND 99 AND unit_price_pesewas BETWEEN 0 AND 9007199254740991 AND line_amount_pesewas BETWEEN 0 AND 9007199254740991 AND line_amount_pesewas = quantity::numeric * unit_price_pesewas::numeric)");
    }
};
