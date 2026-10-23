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
        $schema->create('store_quote_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->string('buyer_email');
            $table->uuid('submission_key');
            $table->string('cart_hash', 64);
            $table->timestampsTz();
            $table->unique(['shop_id', 'buyer_email', 'submission_key'], 'commerce_quote_alias_unique');
            $table->foreign(['order_id', 'shop_id', 'tenant_id'])->references(['id', 'shop_id', 'tenant_id'])->on('store_orders')->restrictOnDelete();
        });
        $schema->create('store_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->unique();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->string('number')->unique();
            $table->text('snapshot');
            $table->timestampsTz();
            $table->foreign(['order_id', 'shop_id', 'tenant_id'])->references(['id', 'shop_id', 'tenant_id'])->on('store_orders')->restrictOnDelete();
        });
        $schema->create('store_outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('receipt_id')->unique();
            $table->foreign('receipt_id')->references('id')->on('store_receipts')->restrictOnDelete();
            $table->text('destination');
            $table->string('state')->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
        });
        $db = $schema->getConnection();
        $db->statement(<<<'SQL'
CREATE OR REPLACE FUNCTION commerce_receipt_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Commerce receipts are immutable retained payment records';
END $$;
SQL);
        $db->statement('CREATE TRIGGER commerce_receipt_guard BEFORE UPDATE OR DELETE ON store_receipts FOR EACH ROW EXECUTE FUNCTION commerce_receipt_guard()');
        $db->statement('CREATE TRIGGER commerce_quote_submission_guard BEFORE UPDATE OR DELETE ON store_quote_submissions FOR EACH ROW EXECUTE FUNCTION commerce_receipt_guard()');
        $db->statement("ALTER TABLE store_outbox_messages ADD CONSTRAINT commerce_outbox_state CHECK (state IN ('queued','sending','retryable','sent','exhausted') AND attempts BETWEEN 0 AND 100)");
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        $schema->dropIfExists('store_outbox_messages');
        $schema->dropIfExists('store_receipts');
        $schema->dropIfExists('store_quote_submissions');
        $schema->getConnection()->statement('DROP FUNCTION IF EXISTS commerce_receipt_guard()');
    }
};
