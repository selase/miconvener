<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['venue_settlement_reserves', 'venue_payment_resolutions', 'commerce_payment_references', 'venue_booking_charges', 'venue_financial_decisions', 'venue_financial_quotes'];

    public function up(): void
    {
        $schema = Schema::connection('landlord');
        $db = $schema->getConnection();

        $schema->table('venue_booking_actions', fn (Blueprint $table) => $table->unique(['id', 'booking_id', 'shop_id', 'tenant_id'], 'venue_booking_action_owner_unique'));

        $schema->create('venue_financial_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->uuid('booking_id');
            $table->unsignedInteger('version');
            $table->uuid('agreement_id');
            $table->string('agreement_scope', 20)->default('reservation');
            $table->uuid('hold_action_id');
            $table->timestamp('hold_expires_at');
            $table->string('currency', 3)->default('GHS');
            $table->string('provider', 30)->default('paystack');
            $table->string('account_fingerprint', 64);
            $table->bigInteger('rental_pesewas');
            $table->bigInteger('extras_pesewas')->default(0);
            $table->bigInteger('security_pesewas')->default(0);
            $table->bigInteger('initial_pesewas');
            $table->bigInteger('balance_pesewas')->default(0);
            $table->bigInteger('contract_fee_pesewas');
            $table->bigInteger('initial_fee_pesewas');
            $table->bigInteger('balance_fee_pesewas')->default(0);
            $table->timestamp('event_starts_at');
            $table->timestamp('event_ends_at');
            $table->timestamp('expires_at');
            $table->timestamp('balance_due_at')->nullable();
            $table->timestamp('security_due_at')->nullable();
            $table->text('cancellation_terms');
            $table->text('support_contact');
            $table->unsignedBigInteger('issued_by');
            $table->string('issuer_name');
            $table->uuid('operation_key');
            $table->string('request_hash', 64);
            $table->string('terms_hash', 64);
            $table->jsonb('snapshot');
            $table->timestamps();
            $table->unique(['booking_id', 'version']);
            $table->unique(['shop_id', 'operation_key']);
            $table->unique(['id', 'booking_id', 'shop_id', 'tenant_id'], 'venue_quote_owner_unique');
            $table->index(['tenant_id', 'created_at']);
            $table->foreign(['booking_id', 'shop_id', 'tenant_id'], 'venue_quote_booking_owner_fk')->references(['id', 'shop_id', 'tenant_id'])->on('venue_bookings')->restrictOnDelete();
            $table->foreign(['agreement_id', 'shop_id', 'tenant_id', 'agreement_scope'], 'venue_quote_agreement_fk')->references(['id', 'shop_id', 'tenant_id', 'offering_scope'])->on('store_commercial_agreements')->restrictOnDelete();
            $table->foreign(['hold_action_id', 'booking_id', 'shop_id', 'tenant_id'], 'venue_quote_hold_action_fk')->references(['id', 'booking_id', 'shop_id', 'tenant_id'])->on('venue_booking_actions')->restrictOnDelete();
        });
        $db->statement("ALTER TABLE venue_financial_quotes ADD CONSTRAINT venue_quote_values CHECK (version >= 1 AND currency = 'GHS' AND provider = 'paystack' AND agreement_scope = 'reservation' AND rental_pesewas >= 0 AND extras_pesewas >= 0 AND security_pesewas >= 0 AND initial_pesewas >= 0 AND balance_pesewas >= 0 AND contract_fee_pesewas >= 0 AND initial_fee_pesewas >= 0 AND balance_fee_pesewas >= 0 AND rental_pesewas + extras_pesewas + security_pesewas <= 9007199254740991 AND initial_pesewas + balance_pesewas = rental_pesewas + extras_pesewas AND initial_fee_pesewas + balance_fee_pesewas = contract_fee_pesewas AND contract_fee_pesewas <= rental_pesewas + extras_pesewas AND initial_fee_pesewas <= initial_pesewas AND balance_fee_pesewas <= balance_pesewas AND (balance_pesewas > 0) = (balance_due_at IS NOT NULL) AND (security_pesewas > 0) = (security_due_at IS NOT NULL) AND event_ends_at > event_starts_at AND expires_at <= hold_expires_at AND expires_at <= event_starts_at AND (balance_due_at IS NULL OR balance_due_at < event_starts_at) AND (security_due_at IS NULL OR security_due_at <= event_starts_at))");

        $schema->create('venue_financial_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('quote_id');
            $table->uuid('booking_id');
            $table->uuid('shop_id');
            $table->uuid('tenant_id');
            $table->string('decision', 16);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('buyer_email')->nullable();
            $table->text('reason')->nullable();
            $table->string('terms_hash', 64)->nullable();
            $table->uuid('operation_key');
            $table->string('request_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['quote_id', 'operation_key']);
            $table->unique(['id', 'booking_id', 'shop_id', 'tenant_id'], 'venue_decision_owner_unique');
            $table->foreign(['quote_id', 'booking_id', 'shop_id', 'tenant_id'], 'venue_decision_quote_owner_fk')->references(['id', 'booking_id', 'shop_id', 'tenant_id'])->on('venue_financial_quotes')->restrictOnDelete();
        });
        $db->statement("ALTER TABLE venue_financial_decisions ADD CONSTRAINT venue_decision_values CHECK (decision IN ('accepted','withdrawn','completed') AND actor_type IN ('buyer','host','staff') AND (decision <> 'accepted' OR (actor_type = 'buyer' AND buyer_email IS NOT NULL AND terms_hash IS NOT NULL)) AND (decision <> 'withdrawn' OR (actor_type = 'host' AND reason IS NOT NULL)) AND (decision <> 'completed' OR (actor_type IN ('host','staff') AND reason IS NOT NULL)))");
        $db->statement("CREATE UNIQUE INDEX venue_decision_one_accepted_per_booking ON venue_financial_decisions (booking_id) WHERE decision = 'accepted'");
        $db->statement("CREATE UNIQUE INDEX venue_decision_one_outcome_per_quote ON venue_financial_decisions (quote_id) WHERE decision IN ('accepted','withdrawn')");
        $db->statement("CREATE UNIQUE INDEX venue_decision_one_completion_per_booking ON venue_financial_decisions (booking_id) WHERE decision = 'completed'");

        $schema->create('venue_booking_charges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->uuid('booking_id');
            $table->uuid('quote_id')->nullable();
            $table->uuid('order_id')->unique();
            $table->string('purpose', 20);
            $table->bigInteger('amount_pesewas');
            $table->bigInteger('fee_pesewas');
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
            $table->unique(['quote_id', 'purpose']);
            $table->unique(['id', 'booking_id', 'shop_id', 'tenant_id'], 'venue_charge_owner_unique');
            $table->foreign(['booking_id', 'shop_id', 'tenant_id'], 'venue_charge_booking_owner_fk')->references(['id', 'shop_id', 'tenant_id'])->on('venue_bookings')->restrictOnDelete();
            $table->foreign(['quote_id', 'booking_id', 'shop_id', 'tenant_id'], 'venue_charge_quote_owner_fk')->references(['id', 'booking_id', 'shop_id', 'tenant_id'])->on('venue_financial_quotes')->restrictOnDelete();
            $table->foreign(['order_id', 'shop_id', 'tenant_id'], 'venue_charge_order_owner_fk')->references(['id', 'shop_id', 'tenant_id'])->on('store_orders')->restrictOnDelete();
        });
        $db->statement("ALTER TABLE venue_booking_charges ADD CONSTRAINT venue_charge_values CHECK (purpose IN ('rental_initial','rental_balance','security','legacy_review') AND amount_pesewas BETWEEN 1 AND 9007199254740991 AND fee_pesewas BETWEEN 0 AND amount_pesewas AND (purpose = 'security' AND fee_pesewas = 0 OR purpose <> 'security') AND (purpose = 'legacy_review') = (quote_id IS NULL))");

        $schema->create('commerce_payment_references', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 30);
            $table->string('account_fingerprint', 64);
            $table->string('reference', 191);
            $table->uuid('tenant_id');
            $table->uuid('shop_id')->nullable();
            $table->string('source_type', 30);
            $table->uuid('order_id')->nullable();
            $table->uuid('booking_id')->nullable();
            $table->string('ledger_reference')->nullable();
            $table->string('origin', 30)->default('checkout');
            $table->timestamps();
            $table->unique(['provider', 'account_fingerprint', 'reference'], 'commerce_reference_owner_unique');
            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('order_id')->references('id')->on('store_orders')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('venue_bookings')->restrictOnDelete();
        });
        $db->statement("ALTER TABLE commerce_payment_references ADD CONSTRAINT commerce_reference_values CHECK (length(reference) > 0 AND origin IN ('checkout','legacy_reconciliation') AND ((source_type = 'store_order' AND order_id IS NOT NULL) OR (source_type = 'legacy_venue_booking' AND booking_id IS NOT NULL AND order_id IS NULL) OR (source_type = 'legacy_ledger_posting' AND order_id IS NULL AND ledger_reference IS NOT NULL)))");

        $schema->create('venue_payment_resolutions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->uuid('booking_id');
            $table->uuid('action_id');
            $table->string('step', 16);
            $table->string('provider', 30);
            $table->string('account_fingerprint', 64);
            $table->string('reference', 191);
            $table->bigInteger('verified_amount_pesewas')->nullable();
            $table->string('currency', 3)->default('GHS');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->uuid('operation_key');
            $table->string('request_hash', 64);
            $table->jsonb('evidence')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['action_id', 'operation_key']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign(['action_id', 'booking_id', 'shop_id', 'tenant_id'], 'venue_resolution_action_owner_fk')->references(['id', 'booking_id', 'shop_id', 'tenant_id'])->on('venue_booking_actions')->restrictOnDelete();
        });
        $db->statement("ALTER TABLE venue_payment_resolutions ADD CONSTRAINT venue_resolution_values CHECK (step IN ('verified','retained','refunded','confirmed') AND currency = 'GHS' AND (verified_amount_pesewas IS NULL OR verified_amount_pesewas >= 0))");
        $db->statement("CREATE UNIQUE INDEX venue_resolution_one_terminal_outcome ON venue_payment_resolutions (action_id) WHERE step IN ('refunded','confirmed')");

        $schema->create('venue_settlement_reserves', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shop_id');
            $table->uuid('booking_id');
            $table->uuid('charge_id')->unique();
            $table->bigInteger('reserved_pesewas');
            $table->string('currency', 3)->default('GHS');
            $table->string('state', 16)->default('held');
            $table->uuid('release_decision_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'state']);
            $table->foreign(['charge_id', 'booking_id', 'shop_id', 'tenant_id'], 'venue_reserve_charge_owner_fk')->references(['id', 'booking_id', 'shop_id', 'tenant_id'])->on('venue_booking_charges')->restrictOnDelete();
            $table->foreign(['release_decision_id', 'booking_id', 'shop_id', 'tenant_id'], 'venue_reserve_decision_owner_fk')->references(['id', 'booking_id', 'shop_id', 'tenant_id'])->on('venue_financial_decisions')->restrictOnDelete();
        });
        $db->statement("ALTER TABLE venue_settlement_reserves ADD CONSTRAINT venue_reserve_values CHECK (reserved_pesewas BETWEEN 0 AND 9007199254740991 AND currency = 'GHS' AND ((state = 'held' AND released_at IS NULL AND release_decision_id IS NULL) OR (state = 'released' AND released_at IS NOT NULL AND release_decision_id IS NOT NULL)))");

        $db->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION venue_contract_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Venue contract evidence is immutable';
END;
$$;
CREATE TRIGGER venue_quote_immutable BEFORE UPDATE OR DELETE ON venue_financial_quotes FOR EACH ROW EXECUTE FUNCTION venue_contract_immutable();
CREATE TRIGGER venue_decision_immutable BEFORE UPDATE OR DELETE ON venue_financial_decisions FOR EACH ROW EXECUTE FUNCTION venue_contract_immutable();
CREATE TRIGGER venue_charge_immutable BEFORE UPDATE OR DELETE ON venue_booking_charges FOR EACH ROW EXECUTE FUNCTION venue_contract_immutable();
CREATE TRIGGER venue_resolution_immutable BEFORE UPDATE OR DELETE ON venue_payment_resolutions FOR EACH ROW EXECUTE FUNCTION venue_contract_immutable();

CREATE OR REPLACE FUNCTION venue_quote_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM venue_booking_actions a WHERE a.id = NEW.hold_action_id AND a.action IN ('approved','renewed') AND a.hold_expires_at = NEW.hold_expires_at) THEN
        RAISE EXCEPTION 'A financial quote must cite the host approval hold it was issued under';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM venue_bookings b WHERE b.id = NEW.booking_id AND b.approval_flow_version IS NOT NULL AND b.starts_at = NEW.event_starts_at AND b.ends_at = NEW.event_ends_at) THEN
        RAISE EXCEPTION 'A financial quote must match the approved request dates';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER venue_quote_guard BEFORE INSERT ON venue_financial_quotes FOR EACH ROW EXECUTE FUNCTION venue_quote_guard();

CREATE OR REPLACE FUNCTION venue_charge_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    o store_orders%ROWTYPE;
    q venue_financial_quotes%ROWTYPE;
    expected_amount bigint;
    expected_fee bigint;
BEGIN
    SELECT * INTO o FROM store_orders WHERE id = NEW.order_id;
    IF o.offering_type <> 'reservation' OR o.principal_pesewas <> NEW.amount_pesewas OR o.platform_fee_pesewas <> NEW.fee_pesewas OR o.charged_pesewas <> NEW.amount_pesewas THEN
        RAISE EXCEPTION 'A venue charge must match its reservation order';
    END IF;
    IF NEW.quote_id IS NOT NULL THEN
        SELECT * INTO q FROM venue_financial_quotes WHERE id = NEW.quote_id;
        IF o.agreement_id <> q.agreement_id THEN
            RAISE EXCEPTION 'A venue charge order must rest on the quoted agreement';
        END IF;
        IF NEW.purpose = 'rental_initial' THEN expected_amount := q.initial_pesewas; expected_fee := q.initial_fee_pesewas;
        ELSIF NEW.purpose = 'rental_balance' THEN expected_amount := q.balance_pesewas; expected_fee := q.balance_fee_pesewas;
        ELSE expected_amount := q.security_pesewas; expected_fee := 0;
        END IF;
        IF NEW.amount_pesewas <> expected_amount OR NEW.fee_pesewas <> expected_fee THEN
            RAISE EXCEPTION 'A venue charge must be the full amount the accepted quote names';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER venue_charge_guard BEFORE INSERT ON venue_booking_charges FOR EACH ROW EXECUTE FUNCTION venue_charge_guard();

CREATE OR REPLACE FUNCTION commerce_reference_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Provider reference ownership is retained'; END IF;
    IF OLD.ledger_reference IS NOT NULL OR (to_jsonb(OLD) - ARRAY['ledger_reference','updated_at']) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['ledger_reference','updated_at']) THEN
        RAISE EXCEPTION 'Only the first ledger link of a provider reference may be recorded';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER commerce_reference_guard BEFORE UPDATE OR DELETE ON commerce_payment_references FOR EACH ROW EXECUTE FUNCTION commerce_reference_guard();

CREATE OR REPLACE FUNCTION venue_resolution_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM venue_booking_actions a WHERE a.id = NEW.action_id AND a.action = 'payment_calendar_conflict') THEN
        RAISE EXCEPTION 'Only payment conflict evidence can be resolved';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER venue_resolution_guard BEFORE INSERT ON venue_payment_resolutions FOR EACH ROW EXECUTE FUNCTION venue_resolution_guard();

CREATE OR REPLACE FUNCTION venue_reserve_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Settlement reserves are retained'; END IF;
    IF TG_OP = 'INSERT' THEN
        IF NOT EXISTS (SELECT 1 FROM venue_booking_charges c WHERE c.id = NEW.charge_id AND c.purpose IN ('rental_initial','rental_balance')) THEN
            RAISE EXCEPTION 'Only rental money is held as settlement reserve';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.state <> 'held' OR NEW.state <> 'released'
        OR (to_jsonb(OLD) - ARRAY['state','released_at','release_decision_id','updated_at']) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['state','released_at','release_decision_id','updated_at']) THEN
        RAISE EXCEPTION 'A settlement reserve can only be released once';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM venue_financial_decisions d WHERE d.id = NEW.release_decision_id AND d.decision = 'completed') THEN
        RAISE EXCEPTION 'A reserve is released only by a completion decision';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER venue_reserve_guard BEFORE INSERT OR UPDATE OR DELETE ON venue_settlement_reserves FOR EACH ROW EXECUTE FUNCTION venue_reserve_guard();
SQL);
    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        $db = $schema->getConnection();
        foreach (self::TABLES as $table) {
            if ($schema->hasTable($table) && $db->table($table)->exists()) {
                throw new RuntimeException("{$table} holds venue contract or payment evidence; retain it and review this rollback manually.");
            }
        }

        foreach (self::TABLES as $table) {
            $schema->dropIfExists($table);
        }
        $db->unprepared('DROP FUNCTION IF EXISTS venue_contract_immutable(), venue_quote_guard(), venue_charge_guard(), commerce_reference_guard(), venue_resolution_guard(), venue_reserve_guard()');
        $schema->table('venue_booking_actions', fn (Blueprint $table) => $table->dropUnique('venue_booking_action_owner_unique'));
    }
};
