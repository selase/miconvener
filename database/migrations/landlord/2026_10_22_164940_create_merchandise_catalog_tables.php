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
        $schema->table('store_listings', function (Blueprint $table): void {
            $table->unique(['id', 'shop_id', 'listing_kind'], 'store_listing_kind_owner_unique');
        });
        $schema->create('store_merchandise', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('listing_id')->unique();
            $table->uuid('shop_id');
            $table->string('listing_kind', 50)->default('merchandise');
            $table->string('visibility', 20)->default('public');
            $table->string('currency', 3)->default('GHS');
            $table->text('collection_instructions')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['listing_id', 'shop_id']);
            $table->foreign(['listing_id', 'shop_id', 'listing_kind'], 'merchandise_listing_owner_fk')->references(['id', 'shop_id', 'listing_kind'])->on('store_listings')->restrictOnDelete();
        });
        $schema->create('store_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('listing_id');
            $table->uuid('shop_id');
            $table->string('sku', 64);
            $table->jsonb('options')->default('{}');
            $table->string('option_key', 64);
            $table->bigInteger('price_pesewas');
            $table->integer('on_hand')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['shop_id', 'sku']);
            $table->unique(['listing_id', 'option_key']);
            $table->unique(['id', 'shop_id']);
            $table->foreign(['listing_id', 'shop_id'])->references(['listing_id', 'shop_id'])->on('store_merchandise')->restrictOnDelete();
        });
        $schema->create('store_stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('variant_id');
            $table->uuid('shop_id');
            $table->uuid('idempotency_key');
            $table->integer('delta');
            $table->integer('balance_after');
            $table->string('reason', 500);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['variant_id', 'idempotency_key']);
            $table->foreign(['variant_id', 'shop_id'])->references(['id', 'shop_id'])->on('store_variants')->restrictOnDelete();
        });
        $connection = $schema->getConnection();
        $connection->statement("ALTER TABLE store_merchandise ADD CONSTRAINT merchandise_kind_check CHECK (listing_kind = 'merchandise'), ADD CONSTRAINT merchandise_visibility_check CHECK (visibility IN ('public', 'members')), ADD CONSTRAINT merchandise_currency_check CHECK (currency = 'GHS')");
        $connection->statement('ALTER TABLE store_variants ADD CONSTRAINT merchandise_price_check CHECK (price_pesewas >= 0), ADD CONSTRAINT merchandise_stock_check CHECK (on_hand >= 0)');
        $connection->statement('ALTER TABLE store_stock_movements ADD CONSTRAINT merchandise_movement_check CHECK (delta <> 0 AND balance_after >= 0)');
        $connection->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION store_stock_movement_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Stock movements are immutable; record a reversing adjustment instead';
END;
$$;
CREATE TRIGGER store_stock_movement_immutable BEFORE UPDATE OR DELETE ON store_stock_movements
FOR EACH ROW EXECUTE FUNCTION store_stock_movement_immutable();
SQL);
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        $schema->dropIfExists('store_stock_movements');
        $schema->getConnection()->statement('DROP FUNCTION IF EXISTS store_stock_movement_immutable()');
        $schema->dropIfExists('store_variants');
        $schema->dropIfExists('store_merchandise');
        $schema->table('store_listings', fn (Blueprint $table) => $table->dropUnique('store_listing_kind_owner_unique'));
    }
};
