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
        $schema->table('store_orders', fn (Blueprint $table) => $table->unique(['id', 'shop_id', 'tenant_id'], 'commerce_order_tenant_unique'));
        $schema->create('store_payment_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('shop_id');
            $table->uuid('tenant_id');
            $table->uuid('submission_key');
            $table->string('reference', 100)->unique();
            $table->string('provider', 30);
            $table->string('settlement_mode', 30);
            $table->string('account_fingerprint', 64);
            $table->bigInteger('amount_pesewas');
            $table->string('currency', 3);
            $table->string('status', 20)->default('initializing');
            $table->text('checkout_url')->nullable();
            $table->string('review_reason')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'submission_key']);
            $table->unique(['id', 'order_id'], 'commerce_attempt_order_unique');
            $table->foreign(['order_id', 'shop_id', 'tenant_id'], 'commerce_attempt_owner_fk')->references(['id', 'shop_id', 'tenant_id'])->on('store_orders')->restrictOnDelete();
        });
        $db = $schema->getConnection();
        $db->statement("CREATE UNIQUE INDEX commerce_one_active_attempt ON store_payment_attempts (order_id) WHERE status IN ('initializing','unknown','ready','pending')");
        $db->statement("ALTER TABLE store_payment_attempts ADD CONSTRAINT commerce_attempt_values CHECK (amount_pesewas BETWEEN 1 AND 9007199254740991 AND currency = 'GHS' AND provider = 'paystack' AND settlement_mode = 'platform_default' AND status IN ('initializing','unknown','ready','pending','captured','review','succeeded','failed','expired'))");
        $db->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION commerce_attempt_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Payment attempt history cannot be deleted'; END IF;
    IF (to_jsonb(OLD) - ARRAY['status','checkout_url','review_reason','last_verified_at','updated_at']) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['status','checkout_url','review_reason','last_verified_at','updated_at']) THEN
        RAISE EXCEPTION 'Attempt account, reference and amounts cannot change';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER commerce_attempt_guard BEFORE UPDATE OR DELETE ON store_payment_attempts FOR EACH ROW EXECUTE FUNCTION commerce_attempt_guard();
SQL);
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        $schema->dropIfExists('store_payment_attempts');
        $schema->getConnection()->statement('DROP FUNCTION IF EXISTS commerce_attempt_guard()');
        $schema->table('store_orders', fn (Blueprint $table) => $table->dropUnique('commerce_order_tenant_unique'));
    }
};
