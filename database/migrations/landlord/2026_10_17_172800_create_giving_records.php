<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('landlord');
        $schema->create('giving_funds', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('slug', 120);
            $t->string('name', 160);
            $t->text('description')->nullable();
            $t->string('button_label', 80)->default('Contribute');
            $t->char('currency', 3)->default('GHS');
            $t->string('kind', 16)->default('open');
            $t->bigInteger('target')->nullable();
            $t->date('ends_on')->nullable();
            $t->boolean('close_on_target')->default(false);
            $t->string('state', 16)->default('draft');
            $t->string('visibility', 16)->default('private');
            $t->string('progress', 16)->default('hidden');
            $t->bigInteger('minimum_amount')->default(100);
            $t->foreignUuid('event_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->jsonb('audit')->default('[]');
            $t->timestamps();
            $t->unique(['tenant_id', 'slug']);
            $t->unique(['id', 'tenant_id']);
        });
        $schema->create('giving_gifts', function (Blueprint $t): void {
            $this->sourceColumns($t);
            $t->foreignUuid('member_id')->nullable()->constrained('members')->restrictOnDelete();
            foreach (['payer_name', 'payer_email', 'payer_phone'] as $field) {
                $t->text($field)->nullable();
            }
            $t->string('payer_email_hash', 64)->nullable()->index();
            $t->string('giver_key', 100)->nullable();
            $t->boolean('is_anonymous')->default(false);
            $t->bigInteger('amount');
            $t->char('currency', 3);
            $t->string('source', 24);
            $t->string('status', 16)->default('pending');
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('paid_at')->nullable();
            $t->timestampTz('collected_at')->nullable();
            $t->bigInteger('gateway_fee_amount')->default(0);
            $t->bigInteger('commission_amount')->default(0);
            $t->bigInteger('net_amount')->default(0);
            $t->uuid('submission_key');
            $t->unique(['tenant_id', 'submission_key']);
        });
        $schema->create('giving_contribution_links', function (Blueprint $t): void {
            $this->sourceColumns($t);
            $t->foreignUuid('event_contribution_id')->unique()->constrained('event_contributions')->restrictOnDelete();
            $t->string('origin', 24);
            $t->string('category', 16);
            $t->timestampTz('associated_at');
        });
        $schema->create('giving_payment_attempts', function (Blueprint $t): void {
            $this->sourceColumns($t);
            $this->typedSource($t);
            $t->uuid('submission_key');
            $t->bigInteger('amount');
            $t->char('currency', 3);
            $t->decimal('rate', 7, 2);
            $t->bigInteger('cap_amount')->nullable();
            $t->string('bearer', 20)->default('organizer');
            $t->string('terms_source', 24);
            $t->bigInteger('commission_amount');
            $t->string('provider', 20);
            $t->string('account_identity');
            $t->string('settlement_mode', 24);
            $t->jsonb('commission_settlement')->default('{}');
            $t->string('reference')->unique();
            $t->string('provider_session_id')->nullable();
            $t->string('provider_reference')->nullable();
            $t->text('checkout_url')->nullable();
            $t->string('receipt_token_hash', 64)->nullable()->unique();
            $t->timestampTz('receipt_expires_at')->nullable();
            $t->string('giver_key', 100)->nullable();
            $t->string('review_reason', 80)->nullable();
            $t->string('category', 16)->default('giving');
            $t->string('state', 24)->default('created');
            $t->timestampTz('confirmed_at')->nullable();
            $t->unique(['tenant_id', 'submission_key']);
            $t->unique(['provider', 'account_identity', 'provider_reference'], 'giving_attempt_provider_reference_unique');
        });
        $schema->create('giving_adjustments', function (Blueprint $t): void {
            $this->sourceColumns($t);
            $this->typedSource($t);
            $t->text('reason');
            $t->bigInteger('principal');
            $t->bigInteger('commission_reversal')->default(0);
            $t->bigInteger('gateway_credit')->default(0);
            $t->bigInteger('commission_returned')->default(0);
            $t->bigInteger('credit_restored')->default(0);
            $t->string('kind', 24);
            $t->string('status', 16)->default('requested');
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->uuid('operation_key');
            $t->string('provider_refund_reference')->nullable();
            $t->string('provider_refund_key', 64)->nullable()->unique();
            $t->timestampTz('confirmed_at')->nullable();
            $t->unique(['tenant_id', 'operation_key']);
        });
        $schema->create('giving_receipt_notices', function (Blueprint $t): void {
            $this->sourceColumns($t);
            $this->typedSource($t);
            $t->string('payment_key')->unique();
            $t->string('receipt_number')->unique();
            $t->text('snapshot');
            $t->text('destination')->nullable();
            $t->string('state', 24)->default('queued');
            $t->timestampTz('sent_at')->nullable();
        });
        $schema->create('giving_reminders', function (Blueprint $t): void {
            $this->sourceColumns($t);
            $t->text('email');
            $t->string('email_hash', 64);
            $t->timestampTz('consented_at');
            $t->string('cadence', 16)->default('monthly');
            $t->unsignedTinyInteger('anchor_day')->default(1);
            $t->string('local_time', 5)->default('09:00');
            $t->string('timezone');
            $t->timestampTz('next_due_at');
            $t->timestampTz('disabled_at')->nullable();
            $t->string('unsubscribe_hash', 64)->unique();
            $t->string('last_delivery_key')->nullable();
            $t->unique(['fund_id', 'email_hash']);
        });
        $schema->table('community_settings', function (Blueprint $t): void {
            $t->timestampTz('giving_event_cutover_at')->nullable();
        });
        $db = DB::connection('landlord');
        $db->statement("ALTER TABLE giving_funds ADD CONSTRAINT giving_funds_values CHECK (kind IN ('open','campaign') AND state IN ('draft','open','closed','archived') AND visibility IN ('public','unlisted','private') AND progress IN ('hidden','percentage','amounts') AND minimum_amount > 0 AND ((kind = 'open' AND target IS NULL) OR (kind = 'campaign' AND target > 0)))");
        $db->statement("ALTER TABLE giving_gifts ADD CONSTRAINT giving_gifts_values CHECK (amount > 0 AND gateway_fee_amount >= 0 AND commission_amount >= 0 AND net_amount >= 0 AND source IN ('online','recorded_cash','bulk_cash') AND status IN ('pending','completed','failed','refunded') AND (source <> 'bulk_cash' OR (member_id IS NULL AND giver_key IS NULL AND payer_email IS NULL AND payer_phone IS NULL AND payer_name IS NULL)))");
        foreach (['giving_payment_attempts', 'giving_adjustments', 'giving_receipt_notices'] as $table) {
            $db->statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_source_check CHECK ((gift_id IS NOT NULL)::int + (event_contribution_id IS NOT NULL)::int = 1)");
        }
        $db->statement('ALTER TABLE giving_adjustments ADD CONSTRAINT giving_adjustments_amounts CHECK (principal > 0 AND commission_reversal >= 0 AND gateway_credit >= 0)');
    }

    public function down(): void
    {
        foreach (['giving_gifts', 'giving_contribution_links', 'giving_payment_attempts', 'giving_adjustments', 'giving_receipt_notices', 'giving_reminders'] as $table) {
            if (DB::connection('landlord')->table($table)->exists()) {
                throw new RuntimeException('Giving history exists; use feature-off and a forward fix.');
            }
        }
        Schema::connection('landlord')->table('community_settings', fn (Blueprint $t) => $t->dropColumn('giving_event_cutover_at'));
        foreach (['giving_reminders', 'giving_receipt_notices', 'giving_adjustments', 'giving_payment_attempts', 'giving_contribution_links', 'giving_gifts', 'giving_funds'] as $table) {
            Schema::connection('landlord')->dropIfExists($table);
        }
    }

    private function sourceColumns(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
        $t->uuid('fund_id');
        $t->foreign(['fund_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('giving_funds')->restrictOnDelete();
        $t->timestamps();
    }

    private function typedSource(Blueprint $t): void
    {
        $t->foreignUuid('gift_id')->nullable()->constrained('giving_gifts')->restrictOnDelete();
        $t->foreignUuid('event_contribution_id')->nullable()->constrained('event_contributions')->restrictOnDelete();
    }
};
