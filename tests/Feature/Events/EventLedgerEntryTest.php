<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('a new tenant defaults to platform_default settlement mode', function () {
    $tenant = Tenant::factory()->create();

    expect($tenant->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT);
    expect($tenant->isPlatformDefaultSettlement())->toBeTrue();
});

test('a tenant can be switched to own_gateway settlement mode', function () {
    $tenant = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);

    expect($tenant->isPlatformDefaultSettlement())->toBeFalse();
});

test('an event ledger entry stores gross, fee, commission and net amounts', function () {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $entry = EventLedgerEntry::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'type' => EventLedgerEntry::TYPE_CHARGE,
        'registration_id' => $registration->id,
        'gross_amount' => 10_000,
        'gateway_fee_amount' => 150,
        'commission_amount' => 500,
        'net_amount' => 9_350,
        'currency' => 'GHS',
        'provider' => 'paystack',
        'provider_reference' => 'ref_ledger_test_1',
    ]);

    expect($entry->fresh())
        ->type->toBe(EventLedgerEntry::TYPE_CHARGE)
        ->gross_amount->toBe(10_000)
        ->gateway_fee_amount->toBe(150)
        ->commission_amount->toBe(500)
        ->net_amount->toBe(9_350);
    expect($entry->event->id)->toBe($event->id);
    expect($entry->registration->id)->toBe($registration->id);
});

test('an event payout can be moved through processing and failed statuses with a reference and reason', function () {
    $tenant = Tenant::factory()->create();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = \App\Models\TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = \App\Models\EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
    ]);

    $payout->update(['status' => \App\Models\EventPayout::STATUS_PROCESSING, 'provider_reference' => 'TRF_test_1']);
    expect($payout->fresh()->status)->toBe('processing');

    $payout->update(['status' => \App\Models\EventPayout::STATUS_FAILED, 'failure_reason' => 'Insufficient balance']);
    expect($payout->fresh())->status->toBe('failed')->failure_reason->toBe('Insufficient balance');
});

test('a tenant payout account can store a bank code, recipient code and resolved account name', function () {
    $tenant = Tenant::factory()->create();
    $account = \App\Models\TenantPayoutAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'bank_code' => '058',
        'recipient_code' => 'RCP_test_1',
        'resolved_account_name' => 'Kwame Asante',
    ]);

    expect($account->fresh())
        ->bank_code->toBe('058')
        ->recipient_code->toBe('RCP_test_1')
        ->resolved_account_name->toBe('Kwame Asante');
});
