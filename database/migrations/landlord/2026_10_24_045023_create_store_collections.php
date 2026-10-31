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
        $schema->create('store_collections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->uuid('order_id')->unique();
            $table->uuid('pass_id')->unique();
            $table->string('method', 20);
            $table->uuid('operation_key');
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->string('source', 20);
            $table->jsonb('lines_snapshot');
            $table->timestamp('handed_over_at');
            $table->timestamps();
            $table->unique(['order_id', 'operation_key']);
            $table->foreign(['pass_id', 'order_id', 'shop_id'], 'store_collection_proof_owner')->references(['id', 'order_id', 'shop_id'])->on('store_collection_passes')->restrictOnDelete();
            $table->foreign(['shop_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('shops')->restrictOnDelete();
            $table->foreign(['order_id', 'shop_id', 'method'], 'store_collection_arrangement_owner')->references(['order_id', 'shop_id', 'method'])->on('store_fulfilment_arrangements')->restrictOnDelete();
        });
        $db = $schema->getConnection();
        $db->statement("ALTER TABLE store_collections ADD CONSTRAINT store_collection_values CHECK (method IN ('collection','delivery') AND source IN ('manual','qr'))");
        $db->statement('CREATE TRIGGER store_collection_immutable BEFORE UPDATE OR DELETE ON store_collections FOR EACH ROW EXECUTE FUNCTION commerce_snapshot_immutable()');
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('store_collections');
    }
};
