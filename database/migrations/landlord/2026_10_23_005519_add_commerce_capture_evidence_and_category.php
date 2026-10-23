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
        $this->categories(true);
        $schema->create('store_payment_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('attempt_id');
            $table->unsignedInteger('observation_version');
            $table->uuid('supersedes_id')->nullable();
            $table->uuid('order_id');
            $table->string('account_fingerprint', 64);
            $table->string('reference', 100);
            $table->boolean('account_matches');
            $table->boolean('reference_matches');
            $table->bigInteger('amount_pesewas')->nullable();
            $table->bigInteger('gateway_fee_pesewas')->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('provider_transaction_id', 100)->nullable();
            $table->jsonb('metadata_snapshot');
            $table->jsonb('integrity_errors');
            $table->string('payload_hash', 64);
            $table->timestamp('verified_at');
            $table->string('processing_state', 20)->default('pending');
            $table->string('review_reason')->nullable();
            $table->foreignUuid('ledger_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['attempt_id', 'payload_hash'], 'commerce_capture_payload_unique');
            $table->unique(['attempt_id', 'observation_version'], 'commerce_capture_version_unique');
            $table->foreign(['attempt_id', 'order_id'])->references(['id', 'order_id'])->on('store_payment_attempts')->restrictOnDelete();
        });
        $schema->table('store_payment_observations', fn (Blueprint $table) => $table->foreign('supersedes_id')->references('id')->on('store_payment_observations')->restrictOnDelete());
        $db = $schema->getConnection();
        $db->statement("ALTER TABLE store_payment_observations ADD CONSTRAINT commerce_capture_values CHECK ((amount_pesewas IS NULL OR amount_pesewas BETWEEN 0 AND 9007199254740991) AND (gateway_fee_pesewas IS NULL OR gateway_fee_pesewas BETWEEN 0 AND 9007199254740991) AND processing_state IN ('pending','posted','review'))");
        $db->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION commerce_capture_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Payment evidence cannot be deleted'; END IF;
    IF (to_jsonb(OLD) - ARRAY['processing_state','review_reason','ledger_transaction_id','updated_at']) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['processing_state','review_reason','ledger_transaction_id','updated_at']) OR (OLD.ledger_transaction_id IS NOT NULL AND NEW.ledger_transaction_id IS DISTINCT FROM OLD.ledger_transaction_id) THEN
        RAISE EXCEPTION 'Verified capture evidence is immutable';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER commerce_capture_guard BEFORE UPDATE OR DELETE ON store_payment_observations FOR EACH ROW EXECUTE FUNCTION commerce_capture_guard();
SQL);
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        foreach (['ledger_accounts', 'ledger_transactions', 'event_payouts'] as $table) {
            if ($schema->getConnection()->table($table)->where('category', 'commerce')->exists()) {
                throw new RuntimeException('Commerce financial records exist; category support cannot be removed.');
            }
        }
        $schema->dropIfExists('store_payment_observations');
        $schema->getConnection()->statement('DROP FUNCTION IF EXISTS commerce_capture_guard()');
        $this->categories(false);
    }

    private function categories(bool $commerce): void
    {
        $db = Schema::connection('landlord')->getConnection();
        $extra = $commerce ? ",'commerce'" : '';
        foreach (['ledger_accounts', 'ledger_transactions', 'event_payouts'] as $table) {
            $db->statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_category_check");
            $db->statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_category_check CHECK (category IN ('event','dues','giving','commission_credit','marketplace'{$extra}) AND (category <> 'event' OR event_id IS NOT NULL) AND (category NOT IN ('dues','giving','commission_credit'{$extra}) OR event_id IS NULL))");
        }
    }
};
