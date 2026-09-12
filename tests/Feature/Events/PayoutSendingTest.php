<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
});

function payoutSendingHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('sending a scheduled payout creates a recipient, initiates a transfer, and moves status to processing', function () {
    Http::fake([
        'api.paystack.co/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_test_1']]),
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_test_1', 'status' => 'pending']]),
    ]);

    [$tenant, $user] = payoutSendingHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'bank_code' => '030']);
    $payout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'payout_account_id' => $account->id, 'amount' => 5_000]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    $payout->refresh();
    expect($payout->status)->toBe(EventPayout::STATUS_PROCESSING);
    expect($payout->provider_reference)->not->toBeNull();

    $account->refresh();
    expect($account->recipient_code)->toBe('RCP_test_1');
});

test('resending a failed payout uses a fresh reference, not a replay of the failed one', function () {
    Http::fake([
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_test_2', 'status' => 'pending']]),
    ]);

    [$tenant, $user] = payoutSendingHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'bank_code' => '030', 'recipient_code' => 'RCP_cached_1']);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 5_000,
        'status' => EventPayout::STATUS_FAILED,
        'provider_reference' => 'old_failed_reference',
        'failure_reason' => 'Insufficient balance',
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/transfer') && $request['reference'] !== 'old_failed_reference');
    expect($payout->fresh()->provider_reference)->not->toBe('old_failed_reference');
});

test('a payout already processing cannot be sent again and fires no second transfer call', function () {
    Http::preventStrayRequests();

    [$tenant, $user] = payoutSendingHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'bank_code' => '030', 'recipient_code' => 'RCP_cached_2']);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 5_000,
        'status' => EventPayout::STATUS_PROCESSING,
        'provider_reference' => 'in_flight_reference',
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host]);

    $response->assertStatus(422);
    Http::assertNothingSent();
    expect($payout->fresh())->status->toBe(EventPayout::STATUS_PROCESSING)->provider_reference->toBe('in_flight_reference');
});

test('a transfer uses the events own currency, not a hardcoded GHS', function () {
    Http::fake([
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_usd', 'status' => 'pending']]),
    ]);

    [$tenant, $user] = payoutSendingHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'USD']);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'bank_code' => '030', 'recipient_code' => 'RCP_cached_usd']);
    $payout = EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'payout_account_id' => $account->id, 'amount' => 5_000]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/transfer') && $request['currency'] === 'USD');
});

test('a processing payout cannot be manually moved back to scheduled or to paid', function () {
    [$tenant, $user] = payoutSendingHost();
    $tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'status' => EventPayout::STATUS_PROCESSING,
        'provider_reference' => 'in_flight_ref_2',
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}", ['status' => 'scheduled'], ['HTTP_HOST' => $host])
        ->assertStatus(422);
    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}", ['status' => 'paid'], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect($payout->fresh())->status->toBe(EventPayout::STATUS_PROCESSING)->paid_at->toBeNull();
});

test('a platform_default tenant can record a payout settled outside the platform', function () {
    // Automated transfers are unavailable while the provider account requires a
    // one-time code per transfer, so recording a payout made by hand is the
    // operating path, not a loophole. It still may not overwrite a live transfer.
    [$tenant, $user] = payoutSendingHost();
    expect($tenant->isPlatformDefaultSettlement())->toBeTrue();

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'status' => EventPayout::STATUS_SCHEDULED,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}", ['status' => 'paid'], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($payout->fresh())->status->toBe(EventPayout::STATUS_PAID)->paid_at->not->toBeNull();

    // The payout has to reach the ledger too, or the settlement statement lists
    // no line item for money that has demonstrably left.
    $entries = $payout->fresh()->ledgerEntries()->where('type', EventLedgerEntry::TYPE_PAYOUT)->get();
    expect($entries)->toHaveCount(1);
    expect($entries->first()->gross_amount)->toBe($payout->amount);
    expect($entries->first()->provider)->toBe('manual');
});

test('recording a payout paid twice does not double the ledger', function () {
    [$tenant, $user] = payoutSendingHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'status' => EventPayout::STATUS_SCHEDULED,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";
    $url = "http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}";

    $this->actingAs($user)->patchJson($url, ['status' => 'paid'], ['HTTP_HOST' => $host])->assertOk();
    $this->actingAs($user)->patchJson($url, ['status' => 'paid'], ['HTTP_HOST' => $host])->assertOk();

    expect($payout->fresh()->ledgerEntries()->where('type', EventLedgerEntry::TYPE_PAYOUT)->count())->toBe(1);
    expect(LedgerTransaction::where('event_id', $event->id)->count())->toBe(1);
});

test('a transfer.success webhook flips the payout to paid and writes one payout ledger row, idempotently', function () {
    [$tenant] = payoutSendingHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'USD']);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 5_000,
        'status' => EventPayout::STATUS_PROCESSING,
        'provider_reference' => 'transfer_ref_success_1',
    ]);

    $payload = ['event' => 'transfer.success', 'data' => ['reference' => 'transfer_ref_success_1', 'transfer_code' => 'TRF_x']];
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, 'sk_settlement_test_123');

    $webhook = fn () => $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);

    $webhook()->assertOk();
    $webhook()->assertOk(); // delivered twice — must stay idempotent

    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PAID);
    expect(EventLedgerEntry::where('payout_id', $payout->id)->where('type', EventLedgerEntry::TYPE_PAYOUT)->count())->toBe(1);

    $ledgerEntry = EventLedgerEntry::where('payout_id', $payout->id)->where('type', EventLedgerEntry::TYPE_PAYOUT)->firstOrFail();
    expect($ledgerEntry->currency)->toBe('USD');

    // The double-entry side of the same disbursement: this is what actually
    // settles the organizer's payable in the accounting ledger, distinct from
    // the single-row summary entry asserted above.
    expect(LedgerTransaction::where('event_id', $event->id)->where('transaction_type', LedgerTransaction::TYPE_PAYOUT)->count())->toBe(1);

    $organizerPayable = LedgerAccount::where('event_id', $event->id)->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)->firstOrFail();
    $debited = (int) $organizerPayable->entries()->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount');
    expect($debited)->toBe(5_000);
});

test('a transfer.failed webhook flips the payout to failed with no ledger row', function () {
    [$tenant] = payoutSendingHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 5_000,
        'status' => EventPayout::STATUS_PROCESSING,
        'provider_reference' => 'transfer_ref_failed_1',
    ]);

    $payload = ['event' => 'transfer.failed', 'data' => ['reference' => 'transfer_ref_failed_1', 'reason' => 'Insufficient balance in platform account']];
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, 'sk_settlement_test_123');

    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    expect($payout->fresh())->status->toBe(EventPayout::STATUS_FAILED)->failure_reason->toBe('Insufficient balance in platform account');
    expect(EventLedgerEntry::where('payout_id', $payout->id)->count())->toBe(0);
});

test('a transfer parked awaiting an OTP is recorded as failed, not as sent', function () {
    // Paystack accepts the request and answers with status "otp" when the platform
    // account still requires a one-time code per transfer. No money moves and no
    // webhook follows, so treating it as processing would tell the organizer they
    // had been paid and then freeze the record beyond manual correction.
    Http::fake([
        'api.paystack.co/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_otp_1']]),
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_otp_1', 'status' => 'otp']]),
    ]);

    [$tenant, $user] = payoutSendingHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'bank_code' => '030']);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 5_000,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host]);

    $response->assertStatus(502);

    $payout->refresh();
    expect($payout->status)->toBe(EventPayout::STATUS_FAILED);
    expect($payout->failure_reason)->toContain('one-time code');

    // Failed payouts stay re-sendable, so the organizer can retry once the
    // provider account is configured.
    expect(in_array($payout->status, [EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_FAILED], true))->toBeTrue();
});
