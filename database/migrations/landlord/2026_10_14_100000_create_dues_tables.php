<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membership dues: the classes an organisation charges, the members who
     * pay them and one charge per member per period. Payouts also gain a
     * currency, because dues are not tied to an event that carries one.
     */
    public function up(): void
    {
        Schema::connection('landlord')->create('dues_classes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3)->default('GHS');
            $t->string('period', 16);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });

        Schema::connection('landlord')->create('members', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('dues_class_id')->constrained('dues_classes')->restrictOnDelete();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone', 32)->nullable();
            $t->unsignedBigInteger('amount_override')->nullable();
            $t->string('status', 16)->default('active');
            $t->date('joined_on')->nullable();
            $t->date('exited_on')->nullable();
            $t->string('pay_token', 40)->unique();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
            $t->unique(['tenant_id', 'phone']);
        });

        Schema::connection('landlord')->create('dues_charges', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('member_id')->constrained('members')->cascadeOnDelete();
            $t->foreignUuid('dues_class_id')->constrained('dues_classes')->restrictOnDelete();
            $t->date('period_start');
            $t->date('period_end');
            $t->date('due_on');
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3);
            $t->string('status', 16)->default('open');
            $t->timestamp('paid_at')->nullable();
            $t->string('payment_method', 16)->nullable();
            $t->string('payment_reference')->nullable()->index();
            $t->unsignedBigInteger('charged_amount')->nullable();
            $t->unsignedBigInteger('gateway_fee_amount')->nullable();
            $t->unsignedBigInteger('commission_amount')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['member_id', 'period_start']);
            $t->index(['tenant_id', 'status', 'due_on']);
        });

        Schema::connection('landlord')->table('event_payouts', function (Blueprint $t): void {
            $t->string('currency', 3)->default('GHS');
        });

        DB::connection('landlord')->statement(
            'UPDATE event_payouts SET currency = events.currency FROM events WHERE events.id = event_payouts.event_id AND events.currency IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_payouts', function (Blueprint $t): void {
            $t->dropColumn('currency');
        });

        Schema::connection('landlord')->dropIfExists('dues_charges');
        Schema::connection('landlord')->dropIfExists('members');
        Schema::connection('landlord')->dropIfExists('dues_classes');
    }
};
