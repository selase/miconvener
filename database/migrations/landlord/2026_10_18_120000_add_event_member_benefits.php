<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->boolean('members_only')->default(false);
            $table->bigInteger('member_ticket_price')->nullable();
        });
        Schema::connection('landlord')->table('event_ticket_types', function (Blueprint $table): void {
            $table->bigInteger('member_price')->nullable();
        });
        $schema = Schema::connection('landlord');
        foreach (['events', 'event_registrations', 'members'] as $name) {
            $schema->table($name, fn (Blueprint $table) => $table->unique(['tenant_id', 'id'], $name.'_benefit_tenant_id'));
        }
        $schema->create('event_member_benefit_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->uuid('registration_id');
            $table->uuid('member_id');
            $table->jsonb('identity');
            $table->jsonb('pricing');
            $table->bigInteger('amount');
            $table->bigInteger('platform_fee_amount');
            $table->bigInteger('charged_amount');
            $table->char('currency', 3);
            $table->string('cart_fingerprint', 64);
            $table->string('state', 24)->default('quoted');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'benefit_quotes_tenant_id');
            $table->foreign(['tenant_id', 'event_id'])->references(['tenant_id', 'id'])->on('events')->restrictOnDelete();
            $table->foreign(['tenant_id', 'registration_id'])->references(['tenant_id', 'id'])->on('event_registrations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('members')->restrictOnDelete();
            $table->index(['tenant_id', 'registration_id', 'created_at'], 'benefit_quote_registration');
        });
        $schema->create('event_member_benefit_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->uuid('quote_id');
            $table->uuid('registration_id');
            $table->string('provider', 24);
            $table->string('account_key');
            $table->string('gateway_id')->nullable();
            $table->string('settlement_mode', 32);
            $table->string('reference')->unique();
            $table->string('provider_session_id')->nullable()->unique();
            $table->string('provider_reference')->nullable()->unique();
            $table->bigInteger('amount');
            $table->bigInteger('charged_amount');
            $table->bigInteger('platform_fee_amount');
            $table->bigInteger('gateway_fee_amount')->nullable();
            $table->char('currency', 3);
            $table->jsonb('commission_settlement')->nullable();
            $table->text('checkout_url')->nullable();
            $table->string('state', 24)->default('preparing');
            $table->string('review_reason')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('fulfilled_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'quote_id'])->references(['tenant_id', 'id'])->on('event_member_benefit_quotes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'registration_id'])->references(['tenant_id', 'id'])->on('event_registrations')->restrictOnDelete();
            $table->index(['tenant_id', 'state', 'created_at'], 'benefit_attempt_review');
        });
        $schema->table('event_registrations', function (Blueprint $table): void {
            $table->boolean('member_benefit_required')->default(false);
            $table->uuid('member_benefit_quote_id')->nullable();
            $table->jsonb('member_benefit_identity')->nullable();
            $table->foreign(['tenant_id', 'member_benefit_quote_id'])->references(['tenant_id', 'id'])->on('event_member_benefit_quotes')->restrictOnDelete();
        });
        $connection = $schema->getConnection();
        $connection->statement("CREATE UNIQUE INDEX benefit_one_unresolved_registration ON event_member_benefit_attempts (registration_id) WHERE state NOT IN ('failed', 'refunded')");
        $connection->statement("CREATE UNIQUE INDEX benefit_one_unresolved_quote ON event_member_benefit_attempts (quote_id) WHERE state NOT IN ('failed', 'refunded')");
        foreach (['event_member_benefit_quotes', 'event_member_benefit_attempts'] as $name) {
            $connection->statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_amounts CHECK (amount >= 0 AND platform_fee_amount >= 0 AND charged_amount >= 0)");
        }

    }

    public function down(): void
    {
        $schema = Schema::connection('landlord');
        if ($schema->getConnection()->table('event_member_benefit_quotes')->exists() || $schema->getConnection()->table('event_member_benefit_attempts')->exists()) {
            throw new RuntimeException('Retained member-benefit records require compatible readers; disable issuance instead of rolling back.');
        }
        $schema->table('event_registrations', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'member_benefit_quote_id']);
            $table->dropColumn(['member_benefit_required', 'member_benefit_quote_id', 'member_benefit_identity']);
        });
        $schema->drop('event_member_benefit_attempts');
        $schema->drop('event_member_benefit_quotes');
        foreach (['events', 'event_registrations', 'members'] as $name) {
            $schema->table($name, fn (Blueprint $table) => $table->dropUnique($name.'_benefit_tenant_id'));
        }
        Schema::connection('landlord')->table('event_ticket_types', fn (Blueprint $table) => $table->dropColumn('member_price'));
        Schema::connection('landlord')->table('events', fn (Blueprint $table) => $table->dropColumn(['members_only', 'member_ticket_price']));
    }
};
