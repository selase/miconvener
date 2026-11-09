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
        Schema::connection('landlord')->table('organisation_payout_policies', function (Blueprint $table): void {
            $table->string('destination_display', 250)->default('Destination requires review');
            $table->string('terms_fingerprint', 64)->default('unapproved');
            $table->string('requester_security_fingerprint', 64)->default('unapproved');
            $table->string('approver_security_fingerprint', 64)->nullable();
        });
        Schema::connection('landlord')->table('scheduled_payout_proposals', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->dropUnique('payout_proposal_window');
            $table->unique(['policy_id', 'scope_key', 'window_key', 'revision'], 'payout_proposal_revision');
        });
        DB::connection('landlord')->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_payout_policy() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Retain payout policy history'; END IF;
              IF (to_jsonb(NEW) - ARRAY['status','approved_by','approved_at','active_after','updated_at','approver_security_fingerprint'])
                 IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','approved_by','approved_at','active_after','updated_at','approver_security_fingerprint']) THEN
                RAISE EXCEPTION 'Payout policy terms are immutable';
              END IF;
              IF OLD.approved_by IS NOT NULL AND (NEW.approved_by, NEW.approved_at, NEW.active_after, NEW.approver_security_fingerprint)
                 IS DISTINCT FROM (OLD.approved_by, OLD.approved_at, OLD.active_after, OLD.approver_security_fingerprint) THEN
                RAISE EXCEPTION 'Payout policy approval is immutable';
              END IF;
              RETURN NEW;
            END $$;
            SQL);
        DB::connection('landlord')->statement("ALTER TABLE organisation_payout_policies ADD CONSTRAINT payout_policy_approval_required CHECK (status <> 'approved' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND active_after >= approved_at + INTERVAL '24 hours'))");
        DB::connection('landlord')->statement('ALTER TABLE scheduled_payout_proposals ADD CONSTRAINT payout_proposal_positive CHECK (amount > 0 AND revision > 0)');
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve payout policy and revision evidence. Roll back application code without dropping financial history.');
    }
};
