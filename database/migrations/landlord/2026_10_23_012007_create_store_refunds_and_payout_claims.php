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
        $schema->table('store_payment_observations', fn (Blueprint $table) => $table->unique(['id', 'order_id', 'attempt_id'], 'commerce_observation_owner_unique'));
        $schema->create('store_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('shop_id');
            $table->uuid('tenant_id');
            $table->uuid('attempt_id')->nullable()->unique();
            $table->uuid('observation_id')->nullable();
            $table->uuid('operation_key');
            $table->string('reason', 500);
            $table->unsignedBigInteger('requested_by');
            $table->string('account_fingerprint', 64);
            $table->bigInteger('amount_pesewas');
            $table->bigInteger('seller_debit_pesewas');
            $table->bigInteger('commission_return_pesewas');
            $table->bigInteger('gateway_fee_pesewas');
            $table->bigInteger('review_liability_pesewas');
            $table->string('fee_bearer', 20);
            $table->boolean('restock');
            $table->string('status', 20);
            $table->timestamp('submitted_at')->nullable();
            $table->string('provider_refund_id', 100)->nullable();
            $table->string('provider_status', 30)->nullable();
            $table->timestamp('provider_processed_at')->nullable();
            $table->string('review_reason')->nullable();
            $table->foreignUuid('ledger_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'operation_key']);
            $table->foreign(['order_id', 'shop_id', 'tenant_id'], 'commerce_refund_owner_fk')->references(['id', 'shop_id', 'tenant_id'])->on('store_orders')->restrictOnDelete();
            $table->foreign(['observation_id', 'order_id', 'attempt_id'], 'commerce_refund_observation_fk')->references(['id', 'order_id', 'attempt_id'])->on('store_payment_observations')->restrictOnDelete();
        });
        $schema->table('event_payouts', function (Blueprint $table): void {
            $table->uuid('commerce_operation_key')->nullable();
            $table->timestampTz('commerce_cancelled_at')->nullable();
            $table->unsignedBigInteger('commerce_cancelled_by')->nullable();
            $table->string('commerce_account_fingerprint', 64)->nullable();
            $table->string('commerce_recipient_fingerprint', 64)->nullable();
            $table->string('commerce_recipient_display')->nullable();
            $table->unique(['tenant_id', 'commerce_operation_key'], 'commerce_payout_operation_unique');
        });
        $db = $schema->getConnection();
        $db->statement('CREATE UNIQUE INDEX commerce_one_free_cancellation ON store_refunds (order_id) WHERE attempt_id IS NULL');
        $db->statement("ALTER TABLE store_refunds ADD CONSTRAINT commerce_refund_values CHECK (amount_pesewas BETWEEN 0 AND 9007199254740991 AND seller_debit_pesewas BETWEEN 0 AND amount_pesewas AND commission_return_pesewas BETWEEN 0 AND amount_pesewas AND gateway_fee_pesewas BETWEEN 0 AND amount_pesewas AND review_liability_pesewas BETWEEN 0 AND amount_pesewas AND fee_bearer IN ('seller','platform') AND status IN ('needs_funding','reserved','submitting','unknown','pending','confirmed','review') AND ((amount_pesewas = 0 AND attempt_id IS NULL AND observation_id IS NULL) OR (amount_pesewas > 0 AND attempt_id IS NOT NULL AND observation_id IS NOT NULL)))");
        $db->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION commerce_refund_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Refund history cannot be deleted'; END IF;
    IF (to_jsonb(OLD) - ARRAY['status','submitted_at','provider_refund_id','provider_status','provider_processed_at','review_reason','ledger_transaction_id','updated_at']) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['status','submitted_at','provider_refund_id','provider_status','provider_processed_at','review_reason','ledger_transaction_id','updated_at'])
        OR (OLD.submitted_at IS NOT NULL AND NEW.submitted_at IS DISTINCT FROM OLD.submitted_at)
        OR (OLD.provider_refund_id IS NOT NULL AND NEW.provider_refund_id IS DISTINCT FROM OLD.provider_refund_id)
        OR (OLD.status = 'confirmed' AND NEW.status <> 'confirmed')
        OR (OLD.provider_processed_at IS NOT NULL AND NEW.provider_processed_at IS DISTINCT FROM OLD.provider_processed_at)
        OR (OLD.provider_status = 'processed' AND NEW.provider_status IS DISTINCT FROM OLD.provider_status)
        OR (OLD.ledger_transaction_id IS NOT NULL AND NEW.ledger_transaction_id IS DISTINCT FROM OLD.ledger_transaction_id) THEN
        RAISE EXCEPTION 'Refund identity and original financial terms cannot change';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER commerce_refund_guard BEFORE UPDATE OR DELETE ON store_refunds FOR EACH ROW EXECUTE FUNCTION commerce_refund_guard();
SQL);
        $db->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION commerce_payout_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.category = 'commerce' THEN
            RAISE EXCEPTION 'Commerce payout records must be retained';
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.category = 'commerce' OR NEW.category = 'commerce' THEN
        IF ROW(OLD.tenant_id, OLD.event_id, OLD.category, OLD.payout_account_id, OLD.amount, OLD.currency, OLD.commerce_operation_key, OLD.commerce_account_fingerprint, OLD.commerce_recipient_fingerprint, OLD.commerce_recipient_display)
            IS DISTINCT FROM ROW(NEW.tenant_id, NEW.event_id, NEW.category, NEW.payout_account_id, NEW.amount, NEW.currency, NEW.commerce_operation_key, NEW.commerce_account_fingerprint, NEW.commerce_recipient_fingerprint, NEW.commerce_recipient_display)
            OR (OLD.provider_reference IS NOT NULL AND NEW.provider_reference IS DISTINCT FROM OLD.provider_reference)
            OR (NULLIF(OLD.provider_transfer_code, '') IS NOT NULL AND NEW.provider_transfer_code IS DISTINCT FROM OLD.provider_transfer_code)
            OR (OLD.requested_transfer_fee_amount IS NOT NULL AND NEW.requested_transfer_fee_amount IS DISTINCT FROM OLD.requested_transfer_fee_amount)
            OR (OLD.requested_net_amount IS NOT NULL AND NEW.requested_net_amount IS DISTINCT FROM OLD.requested_net_amount)
            OR (OLD.status IN ('paid', 'cancelled') AND NEW.status <> OLD.status)
            OR (OLD.commerce_cancelled_at IS NOT NULL AND ROW(NEW.commerce_cancelled_at, NEW.commerce_cancelled_by) IS DISTINCT FROM ROW(OLD.commerce_cancelled_at, OLD.commerce_cancelled_by))
            OR (NEW.status = 'cancelled' AND (NEW.provider_reference IS NOT NULL OR NULLIF(NEW.provider_transfer_code, '') IS NOT NULL OR NEW.requested_net_amount IS NOT NULL OR NEW.requested_transfer_fee_amount IS NOT NULL OR NEW.commerce_cancelled_at IS NULL OR NEW.commerce_cancelled_by IS NULL OR OLD.status NOT IN ('scheduled','cancelled')))
            OR (NEW.status <> 'cancelled' AND (NEW.commerce_cancelled_at IS NOT NULL OR NEW.commerce_cancelled_by IS NOT NULL)) THEN
            RAISE EXCEPTION 'Commerce payout ownership, original intent and completed transfer cannot change';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER commerce_payout_guard BEFORE UPDATE OR DELETE ON event_payouts FOR EACH ROW EXECUTE FUNCTION commerce_payout_guard();
SQL);
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        if ($schema->getConnection()->table('store_refunds')->exists() || $schema->getConnection()->table('event_payouts')->where('category', 'commerce')->exists()) {
            throw new RuntimeException('Commerce operations exist; refund or payout history cannot be removed.');
        }
        $schema->dropIfExists('store_refunds');
        $schema->getConnection()->statement('DROP FUNCTION IF EXISTS commerce_refund_guard()');
        $schema->getConnection()->statement('DROP TRIGGER IF EXISTS commerce_payout_guard ON event_payouts');
        $schema->getConnection()->statement('DROP FUNCTION IF EXISTS commerce_payout_guard()');
        $schema->table('store_payment_observations', fn (Blueprint $table) => $table->dropUnique('commerce_observation_owner_unique'));
        $schema->table('event_payouts', function (Blueprint $table): void {
            $table->dropUnique('commerce_payout_operation_unique');
            $table->dropColumn(['commerce_cancelled_at', 'commerce_cancelled_by', 'commerce_operation_key', 'commerce_account_fingerprint', 'commerce_recipient_fingerprint', 'commerce_recipient_display']);
        });
    }
};
