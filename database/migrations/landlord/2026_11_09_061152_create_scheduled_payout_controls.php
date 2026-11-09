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
        Schema::connection('landlord')->create('organisation_payout_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('category', 20);
            $table->string('currency', 3)->default('GHS');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('payout_account_id')->constrained('tenant_payout_accounts')->restrictOnDelete();
            $table->string('account_fingerprint', 64);
            $table->string('recipient_fingerprint', 64);
            $table->string('timezone', 100);
            $table->string('cadence', 10);
            $table->unsignedSmallInteger('weekday')->nullable();
            $table->string('local_time', 5);
            foreach (['minimum_amount', 'maximum_amount', 'daily_limit', 'rolling_limit', 'reserve_amount'] as $column) {
                $table->bigInteger($column);
            }
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('active_after')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'category', 'currency', 'version'], 'payout_policy_version');
        });
        Schema::connection('landlord')->create('payout_policy_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('policy_id')->constrained('organisation_payout_policies')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->jsonb('evidence');
            $table->timestampTz('created_at');
        });
        Schema::connection('landlord')->create('scheduled_payout_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('policy_id')->constrained('organisation_payout_policies')->restrictOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('scope_key', 60);
            $table->string('window_key', 100);
            $table->string('status', 30)->default('proposed');
            $table->string('reason', 200)->nullable();
            $table->jsonb('snapshot');
            $table->bigInteger('amount');
            $table->foreignUuid('payout_id')->nullable()->unique()->constrained('event_payouts')->restrictOnDelete();
            $table->uuid('operation_key')->nullable()->unique();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('submit_before')->nullable();
            $table->timestampsTz();
            $table->unique(['policy_id', 'scope_key', 'window_key'], 'payout_proposal_window');
        });
        Schema::connection('landlord')->create('payout_reconciliation_checkpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('account_fingerprint', 64);
            $table->string('scope_key', 100);
            $table->timestampTz('observed_at');
            $table->string('status', 20);
            $table->jsonb('evidence');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->index(['account_fingerprint', 'scope_key', 'observed_at'], 'payout_checkpoint_scope');
        });
        DB::connection('landlord')->unprepared(<<<'SQL'
            ALTER TABLE organisation_payout_policies ADD CONSTRAINT payout_policy_values CHECK (
              currency = 'GHS' AND category IN ('event','dues','giving','commerce') AND cadence IN ('daily','weekly')
              AND status IN ('pending','approved','paused') AND minimum_amount > 0 AND maximum_amount >= minimum_amount
              AND daily_limit >= maximum_amount AND rolling_limit >= maximum_amount AND reserve_amount >= 0
              AND (cadence <> 'weekly' OR weekday BETWEEN 0 AND 6)
              AND (approved_by IS NULL OR approved_by <> requested_by));
            CREATE OR REPLACE FUNCTION guard_payout_policy() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Retain payout policy history'; END IF;
              IF (to_jsonb(NEW) - ARRAY['status','approved_by','approved_at','active_after','updated_at'])
                 IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','approved_by','approved_at','active_after','updated_at']) THEN
                RAISE EXCEPTION 'Payout policy terms are immutable';
              END IF;
              IF OLD.approved_by IS NOT NULL AND (NEW.approved_by, NEW.approved_at, NEW.active_after)
                 IS DISTINCT FROM (OLD.approved_by, OLD.approved_at, OLD.active_after) THEN
                RAISE EXCEPTION 'Payout policy approval is immutable';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER payout_policy_immutable BEFORE UPDATE OR DELETE ON organisation_payout_policies FOR EACH ROW EXECUTE FUNCTION guard_payout_policy();
            CREATE OR REPLACE FUNCTION guard_payout_action() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Payout action evidence is immutable'; END $$;
            CREATE TRIGGER payout_action_immutable BEFORE UPDATE OR DELETE ON payout_policy_actions FOR EACH ROW EXECUTE FUNCTION guard_payout_action();
            CREATE TRIGGER payout_checkpoint_immutable BEFORE UPDATE OR DELETE ON payout_reconciliation_checkpoints FOR EACH ROW EXECUTE FUNCTION guard_payout_action();
            SQL);
    }

    public function down(): void
    {
        foreach (['payout_reconciliation_checkpoints', 'scheduled_payout_proposals', 'payout_policy_actions', 'organisation_payout_policies'] as $table) {
            Schema::connection('landlord')->dropIfExists($table);
        }
        DB::connection('landlord')->unprepared('DROP FUNCTION IF EXISTS guard_payout_policy(); DROP FUNCTION IF EXISTS guard_payout_action();');
    }
};
