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
        $schema->table('store_fulfilment_events', fn (Blueprint $table) => $table->bigInteger('sequence')->generatedAs()->always()->unique());
        $schema->table('store_fulfilment_arrangements', fn (Blueprint $table) => $table->unique(['order_id', 'shop_id', 'method'], 'store_arrangement_method_owner'));
        $schema->create('store_collection_passes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->uuid('order_id');
            $table->uuid('event_id');
            $table->string('method', 20);
            $table->uuid('operation_key');
            $table->text('issued_to');
            $table->string('qr_hash', 64);
            $table->string('manual_hash', 64);
            $table->text('secret_material')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 50)->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'operation_key']);
            $table->unique(['id', 'order_id', 'shop_id'], 'store_collection_pass_owner');
            $table->foreign(['event_id', 'order_id', 'shop_id'], 'store_pass_event_owner')->references(['id', 'order_id', 'shop_id'])->on('store_fulfilment_events')->restrictOnDelete();
            $table->foreign(['order_id', 'shop_id', 'method'], 'store_pass_arrangement_owner')->references(['order_id', 'shop_id', 'method'])->on('store_fulfilment_arrangements')->restrictOnDelete();
            $table->foreign(['shop_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('shops')->restrictOnDelete();
        });
        $db = $schema->getConnection();
        $db->statement('CREATE UNIQUE INDEX store_one_current_collection_pass ON store_collection_passes(order_id) WHERE revoked_at IS NULL AND consumed_at IS NULL');
        $db->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION store_collection_pass_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Pass history cannot be deleted'; END IF;
 IF (to_jsonb(OLD) - ARRAY['revoked_at','revocation_reason','consumed_at','secret_material','updated_at']) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['revoked_at','revocation_reason','consumed_at','secret_material','updated_at'])
 OR (OLD.revoked_at IS NOT NULL AND (NEW.revoked_at IS DISTINCT FROM OLD.revoked_at OR NEW.revocation_reason IS DISTINCT FROM OLD.revocation_reason))
 OR (OLD.consumed_at IS NOT NULL AND NEW.consumed_at IS DISTINCT FROM OLD.consumed_at)
 OR (NEW.secret_material IS NOT NULL AND NEW.secret_material IS DISTINCT FROM OLD.secret_material) THEN
 RAISE EXCEPTION 'Pass identity and completed transitions are immutable'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER store_collection_pass_guard BEFORE UPDATE OR DELETE ON store_collection_passes FOR EACH ROW EXECUTE FUNCTION store_collection_pass_guard();
SQL);
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        $schema->dropIfExists('store_collection_passes');
        $schema->getConnection()->statement('DROP FUNCTION IF EXISTS store_collection_pass_guard()');
        $schema->table('store_fulfilment_arrangements', fn (Blueprint $table) => $table->dropUnique('store_arrangement_method_owner'));
        $schema->table('store_fulfilment_events', fn (Blueprint $table) => $table->dropColumn('sequence'));
    }
};
