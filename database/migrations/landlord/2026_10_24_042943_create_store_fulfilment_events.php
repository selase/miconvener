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
        $schema->create('store_fulfilment_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->uuid('order_id');
            $table->string('kind', 30);
            $table->uuid('operation_key');
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->text('reason')->nullable();
            $table->jsonb('lines_snapshot');
            $table->string('terms_hash', 64);
            $table->timestamps();
            $table->unique(['order_id', 'operation_key']);
            $table->unique(['id', 'order_id', 'shop_id'], 'store_fulfilment_event_owner');
            $table->foreign(['order_id', 'shop_id'])->references(['id', 'shop_id'])->on('store_orders')->restrictOnDelete();
            $table->foreign(['shop_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('shops')->restrictOnDelete();
        });
        $schema->getConnection()->statement('CREATE TRIGGER store_fulfilment_event_immutable BEFORE UPDATE OR DELETE ON store_fulfilment_events FOR EACH ROW EXECUTE FUNCTION commerce_snapshot_immutable()');
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('store_fulfilment_events');
    }
};
