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
        $schema->create('store_fulfilment_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->uuid('order_id');
            $table->uuid('shop_id');
            $table->uuid('tenant_id');
            $table->foreign(['event_id', 'order_id', 'shop_id'])->references(['id', 'order_id', 'shop_id'])->on('store_fulfilment_events')->restrictOnDelete();
            $table->foreign(['order_id', 'shop_id', 'tenant_id'])->references(['id', 'shop_id', 'tenant_id'])->on('store_orders')->restrictOnDelete();
            $table->text('destination');
            $table->text('snapshot');
            $table->string('state', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'next_attempt_at']);
        });
        $db = $schema->getConnection();
        $db->statement("ALTER TABLE store_fulfilment_notices ADD CONSTRAINT store_ready_notice_values CHECK (state IN ('queued','sending','retryable','exhausted','sent','superseded') AND attempts BETWEEN 0 AND 5)");
        $db->unprepared("CREATE OR REPLACE FUNCTION store_ready_notice_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Ready notice evidence is retained'; END IF; IF (NEW.id,NEW.event_id,NEW.order_id,NEW.shop_id,NEW.tenant_id,NEW.destination,NEW.snapshot) IS DISTINCT FROM (OLD.id,OLD.event_id,OLD.order_id,OLD.shop_id,OLD.tenant_id,OLD.destination,OLD.snapshot) THEN RAISE EXCEPTION 'Ready notice identity is immutable'; END IF; RETURN NEW; END; $$");
        $db->statement('CREATE TRIGGER store_ready_notice_identity BEFORE UPDATE OR DELETE ON store_fulfilment_notices FOR EACH ROW EXECUTE FUNCTION store_ready_notice_guard()');
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('store_fulfilment_notices');
    }
};
