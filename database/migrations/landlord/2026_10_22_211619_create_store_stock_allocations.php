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
        $schema->create('store_stock_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('line_id')->unique();
            $table->uuid('shop_id');
            $table->uuid('variant_id');
            $table->integer('quantity');
            $table->timestamp('expires_at');
            $table->string('state', 20)->default('active');
            $table->string('release_reason', 30)->nullable();
            $table->timestamps();
            $table->index(['variant_id', 'state', 'expires_at']);
            $table->foreign(['line_id', 'order_id', 'shop_id', 'variant_id'], 'commerce_allocation_line_fk')->references(['id', 'order_id', 'shop_id', 'variant_id'])->on('store_order_lines')->restrictOnDelete();
        });
        $schema->table('store_stock_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('actor_id')->nullable()->change();
            $table->string('source_kind', 20)->default('staff');
            $table->uuid('source_id')->nullable();
            $table->unique(['variant_id', 'source_kind', 'source_id'], 'commerce_stock_source_unique');
        });
        $db = $schema->getConnection();
        $db->statement("ALTER TABLE store_stock_allocations ADD CONSTRAINT commerce_allocation_values CHECK (quantity BETWEEN 1 AND 99 AND state IN ('active','released','consumed'))");
        $db->statement("ALTER TABLE store_stock_movements ADD CONSTRAINT commerce_movement_source CHECK ((source_kind = 'staff' AND actor_id IS NOT NULL AND source_id IS NULL) OR (source_kind IN ('order','refund') AND actor_id IS NULL AND source_id IS NOT NULL))");
        $db->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION commerce_allocation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Allocation history cannot be deleted'; END IF;
    IF (to_jsonb(OLD) - ARRAY['state','release_reason','updated_at']) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['state','release_reason','updated_at']) OR (OLD.state <> 'active' AND NEW.state <> OLD.state) THEN
        RAISE EXCEPTION 'Allocation identity and completed transitions are immutable';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER commerce_allocation_guard BEFORE UPDATE OR DELETE ON store_stock_allocations FOR EACH ROW EXECUTE FUNCTION commerce_allocation_guard();
SQL);
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        $schema->dropIfExists('store_stock_allocations');
        $schema->getConnection()->statement('DROP FUNCTION IF EXISTS commerce_allocation_guard()');
        $schema->getConnection()->statement('ALTER TABLE store_stock_movements DROP CONSTRAINT commerce_movement_source');
        $schema->table('store_stock_movements', function (Blueprint $table): void {
            $table->dropUnique('commerce_stock_source_unique');
            $table->dropColumn(['source_kind', 'source_id']);
        });
    }
};
