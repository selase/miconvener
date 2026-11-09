<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->table('event_payouts', function (Blueprint $table): void {
            $table->bigInteger('reversed_amount')->default(0);
            $table->timestampTz('reversed_at')->nullable();
        });
        DB::connection('landlord')->unprepared(<<<'SQL'
            ALTER TABLE organisation_payout_policies ADD CONSTRAINT payout_policy_tenant_pair UNIQUE(id,tenant_id);
            ALTER TABLE scheduled_payout_proposals ADD CONSTRAINT payout_proposal_policy_tenant FOREIGN KEY(policy_id,tenant_id) REFERENCES organisation_payout_policies(id,tenant_id);
            ALTER TABLE payout_liquidity_allocations ADD CONSTRAINT payout_liquidity_positive CHECK (amount > 0);
            CREATE OR REPLACE FUNCTION guard_payout_proposal() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Retain payout proposals'; END IF;
              IF (to_jsonb(NEW) - ARRAY['status','reason','updated_at','payout_id','operation_key','approved_by','submit_before'])
                IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','reason','updated_at','payout_id','operation_key','approved_by','submit_before']) THEN
                RAISE EXCEPTION 'Payout proposal financial terms are immutable';
              END IF;
              IF OLD.payout_id IS NOT NULL AND (NEW.payout_id,NEW.operation_key,NEW.approved_by,NEW.submit_before)
                IS DISTINCT FROM (OLD.payout_id,OLD.operation_key,OLD.approved_by,OLD.submit_before) THEN
                RAISE EXCEPTION 'Payout approval evidence is immutable';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER payout_proposal_immutable BEFORE UPDATE OR DELETE ON scheduled_payout_proposals FOR EACH ROW EXECUTE FUNCTION guard_payout_proposal();
            CREATE OR REPLACE FUNCTION guard_payout_allocation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Retain payout allocations'; END IF;
              IF (to_jsonb(NEW) - 'reconciled_at') IS DISTINCT FROM (to_jsonb(OLD) - 'reconciled_at') THEN
                RAISE EXCEPTION 'Payout allocation evidence is immutable';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER payout_allocation_immutable BEFORE UPDATE OR DELETE ON payout_liquidity_allocations FOR EACH ROW EXECUTE FUNCTION guard_payout_allocation();
            SQL);
    }

    public function down(): void
    {
        DB::connection('landlord')->unprepared('DROP TRIGGER payout_proposal_immutable ON scheduled_payout_proposals; DROP FUNCTION guard_payout_proposal(); DROP TRIGGER payout_allocation_immutable ON payout_liquidity_allocations; DROP FUNCTION guard_payout_allocation(); ALTER TABLE scheduled_payout_proposals DROP CONSTRAINT payout_proposal_policy_tenant; ALTER TABLE organisation_payout_policies DROP CONSTRAINT payout_policy_tenant_pair; ALTER TABLE payout_liquidity_allocations DROP CONSTRAINT payout_liquidity_positive;');
        Schema::connection('landlord')->table('event_payouts', function (Blueprint $table): void {
            $table->dropColumn(['reversed_amount', 'reversed_at']);
        });
    }
};
