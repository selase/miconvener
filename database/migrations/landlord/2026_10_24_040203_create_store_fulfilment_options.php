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
        $schema->create('store_fulfilment_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->integer('version');
            $table->uuid('operation_key');
            $table->string('fingerprint', 64);
            $table->jsonb('snapshot');
            $table->timestamps();
            $table->unique(['shop_id', 'version']);
            $table->unique(['shop_id', 'operation_key']);
            $table->unique(['id', 'shop_id', 'tenant_id'], 'store_policy_ownership');
            $table->foreign(['shop_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('shops')->restrictOnDelete();
        });
        $schema->create('store_fulfilment_arrangements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->uuid('order_id')->unique();
            $table->uuid('policy_id');
            $table->uuid('replaces_order_id')->nullable();
            $table->foreign('replaces_order_id')->references('id')->on('store_orders')->restrictOnDelete();
            $table->unique(['shop_id', 'operation_key'], 'store_arrangement_operation');
            $table->string('method', 20);
            $table->uuid('operation_key');
            $table->string('fingerprint', 64);
            $table->jsonb('policy_snapshot');
            $table->text('destination')->nullable();
            $table->timestamps();
            $table->foreign(['order_id', 'shop_id'])->references(['id', 'shop_id'])->on('store_orders')->restrictOnDelete();
            $table->foreign(['policy_id', 'shop_id', 'tenant_id'], 'store_arrangement_policy_owner')->references(['id', 'shop_id', 'tenant_id'])->on('store_fulfilment_policies')->restrictOnDelete();
        });
        $db = $schema->getConnection();
        $db->statement("ALTER TABLE store_fulfilment_arrangements ADD CONSTRAINT store_arrangement_method CHECK (method IN ('collection','delivery') AND ((method = 'collection' AND destination IS NULL) OR (method = 'delivery' AND destination IS NOT NULL)))");
        foreach (['store_fulfilment_policies', 'store_fulfilment_arrangements'] as $table) {
            $db->statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION commerce_snapshot_immutable()");
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        $schema->dropIfExists('store_fulfilment_arrangements');
        $schema->dropIfExists('store_fulfilment_policies');
    }
};
