<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Mail\Billing\CommissionSplitBrokenMail;
use App\Models\Event;
use App\Models\EventPayoutSchedule;
use App\Models\EventRegistration;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use App\Services\Finance\LedgerService;
use App\Services\Payment\CommissionSplitVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

const COMMISSION_ACCOUNT = '0123456789';

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared', 'settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $this->tenant->features()->create(['feature_key' => 'paid_tickets', 'enabled' => true]);

    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->user->assignRole('Org Superadmin');
    $this->tenant->users()->attach($this->user->id);

    $this->host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');

    config(['services.commission_split.account_number' => COMMISSION_ACCOUNT]);
});

function splitGateway(array $meta = ['commission_split' => ['subaccount_code' => 'ACCT_abc123', 'ok' => true, 'reason' => null]]): TenantPaymentGateway
{
    return TenantPaymentGateway::factory()->create([
        'tenant_id' => test()->tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_tenant',
        'is_active' => true,
        'meta' => $meta,
    ]);
}

function pendingTicket(float $feePercentage = 5.0): EventRegistration
{
    $event = Event::factory()->published()->paid(10_000)->create([
        'tenant_id' => test()->tenant->id,
        'platform_fee_percentage' => $feePercentage,
    ]);

    return EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => test()->tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 10_000,
    ]);
}

function fakePaystack(array $subaccount = ['active' => true, 'account_number' => COMMISSION_ACCOUNT], int $subaccountStatus = 200): void
{
    Http::fake([
        'api.paystack.co/subaccount/*' => Http::response(['status' => $subaccountStatus === 200, 'data' => $subaccount], $subaccountStatus),
        'api.paystack.co/customer/CUS_1' => Http::response(['data' => ['email' => 'guest@example.com']]),
        'api.paystack.co/customer' => Http::response(['data' => ['customer_code' => 'CUS_1']]),
        'api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/abc']]),
    ]);
}

function payWithPaystackAs(EventRegistration $registration)
{
    return test()->post(
        'http://'.test()->host."/e/{$registration->event->slug}/checkout/{$registration->id}/paystack",
        [],
        ['HTTP_HOST' => test()->host]
    );
}

function initializePayload(): ?array
{
    $sent = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/transaction/initialize'))->first();

    return $sent ? $sent[0]->data() : null;
}

test('a verified subaccount splits the charge so the commission reaches the platform', function () {
    splitGateway();
    fakePaystack();

    payWithPaystackAs(pendingTicket())->assertRedirect('https://checkout.paystack.com/abc');

    $payload = initializePayload();
    expect($payload['amount'])->toBe(10_000)
        ->and($payload['subaccount'])->toBe('ACCT_abc123')
        // Paystack's transaction_charge is what the main account (the tenant) keeps.
        ->and($payload['transaction_charge'])->toBe(9_500)
        ->and($payload['bearer'])->toBe('account');
});

test('a subaccount that settles somewhere else blocks the payment and is recorded as broken', function () {
    $gateway = splitGateway();
    fakePaystack(['active' => true, 'account_number' => '9999999999']);

    payWithPaystackAs(pendingTicket())
        ->assertRedirect()
        ->assertSessionHas('error', 'Online payment is unavailable for this event until the organizer fixes their payment setup.');

    expect(initializePayload())->toBeNull()
        ->and($gateway->fresh()->commissionSplitIsVerified())->toBeFalse();
});

test('a deactivated subaccount blocks the payment', function () {
    splitGateway();
    fakePaystack(['active' => false, 'account_number' => COMMISSION_ACCOUNT]);

    payWithPaystackAs(pendingTicket())->assertSessionHas('error');

    expect(initializePayload())->toBeNull();
});

test('a subaccount Paystack does not know blocks the payment', function () {
    splitGateway();
    fakePaystack([], 404);

    payWithPaystackAs(pendingTicket())->assertSessionHas('error');

    expect(initializePayload())->toBeNull();
});

test('an unreachable Paystack asks the attendee to retry without marking a good subaccount as broken', function () {
    $gateway = splitGateway();
    Http::fake(['api.paystack.co/subaccount/*' => Http::response('', 503)]);

    payWithPaystackAs(pendingTicket())
        ->assertSessionHas('error', 'Payment could not be started. Please try again in a moment.');

    expect($gateway->fresh()->commissionSplitIsVerified())->toBeTrue();
});

test('a tenant who has not entered a subaccount cannot take a commissioned payment', function () {
    splitGateway(meta: []);
    fakePaystack();

    payWithPaystackAs(pendingTicket())
        ->assertSessionHas('error', 'Online payment is unavailable for this event until the organizer finishes setting up payments.');

    expect(initializePayload())->toBeNull();
});

test('the split is not required until the platform names its commission account', function () {
    config(['services.commission_split.account_number' => null]);
    splitGateway(meta: []);
    fakePaystack();

    payWithPaystackAs(pendingTicket())->assertRedirect('https://checkout.paystack.com/abc');

    expect(initializePayload())->not->toHaveKey('subaccount');
});

test('a waived commission takes the payment without a split', function () {
    splitGateway(meta: []);
    fakePaystack();

    payWithPaystackAs(pendingTicket(feePercentage: 0.0))->assertRedirect('https://checkout.paystack.com/abc');

    expect(initializePayload())->not->toHaveKey('subaccount');
});

test('a platform-default tenant is charged on the platform account with no tenant subaccount', function () {
    $this->tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    config(['services.settlement.paystack.secret_key' => 'sk_platform']);
    fakePaystack();

    payWithPaystackAs(pendingTicket())->assertRedirect('https://checkout.paystack.com/abc');

    expect(initializePayload())->not->toHaveKey('subaccount');
});

test('saving a subaccount code verifies it and records the result', function () {
    fakePaystack();

    $this->actingAs($this->user)->post("http://{$this->host}/settings/payments", [
        'provider' => 'paystack',
        'api_key' => 'sk_test_tenant',
        'commission_subaccount_code' => 'ACCT_abc123',
    ], ['HTTP_HOST' => $this->host])->assertSessionHasNoErrors();

    $gateway = TenantPaymentGateway::where('tenant_id', $this->tenant->id)->firstOrFail();
    expect($gateway->commissionSubaccountCode())->toBe('ACCT_abc123')
        ->and($gateway->commissionSplitIsVerified())->toBeTrue();
});

test('a subaccount that does not settle to the platform is saved but reported with the reason', function () {
    fakePaystack(['active' => true, 'account_number' => '9999999999']);

    $this->actingAs($this->user)->post("http://{$this->host}/settings/payments", [
        'provider' => 'paystack',
        'api_key' => 'sk_test_tenant',
        'commission_subaccount_code' => 'ACCT_abc123',
    ], ['HTTP_HOST' => $this->host])
        ->assertSessionHasErrors(['commission_subaccount_code' => 'That subaccount does not settle to the MiConvener commission account.']);

    expect(TenantPaymentGateway::where('tenant_id', $this->tenant->id)->firstOrFail()->commissionSplitIsVerified())->toBeFalse();
});

test('a malformed subaccount code is rejected without calling Paystack', function () {
    Http::fake();

    $this->actingAs($this->user)->post("http://{$this->host}/settings/payments", [
        'provider' => 'paystack',
        'api_key' => 'sk_test_tenant',
        'commission_subaccount_code' => 'not a code',
    ], ['HTTP_HOST' => $this->host])->assertSessionHasErrors('commission_subaccount_code');

    Http::assertNothingSent();
});

test('the check-again action re-verifies the saved subaccount', function () {
    $gateway = splitGateway(['commission_split' => ['subaccount_code' => 'ACCT_abc123', 'ok' => false, 'reason' => 'old']]);
    fakePaystack();

    $this->actingAs($this->user)
        ->post("http://{$this->host}/settings/payments/paystack/verify-split", [], ['HTTP_HOST' => $this->host])
        ->assertSessionHas('success');

    expect($gateway->fresh()->commissionSplitIsVerified())->toBeTrue();
});

test('the nightly check catches a subaccount that was repointed', function () {
    $gateway = splitGateway();
    fakePaystack(['active' => true, 'account_number' => '9999999999']);

    Artisan::call('payments:verify-commission-splits');

    expect($gateway->fresh()->commissionSplitIsVerified())->toBeFalse()
        ->and($gateway->fresh()->commissionSplitFailure())->toBe('That subaccount does not settle to the MiConvener commission account.');
});

function paystackSuccessWebhook(EventRegistration $registration, array $overrides = [])
{
    $body = json_encode(['event' => 'charge.success', 'data' => array_merge([
        'reference' => 'ref_'.$registration->id,
        'amount' => 10_000,
        'fees' => 150,
        'currency' => 'GHS',
        'customer' => ['email' => 'guest@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        'metadata' => ['event_registration_id' => $registration->id, 'type' => 'event_ticket', 'commission_split' => 'ACCT_abc123'],
    ], $overrides)]);

    return test()->call('POST', '/webhooks/merchant/paystack/'.test()->tenant->id, [], [], [], [
        'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_tenant'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

test('checkout records the subaccount in the charge metadata so the sale can be booked as collected', function () {
    splitGateway();
    fakePaystack();

    payWithPaystackAs(pendingTicket());

    expect(initializePayload()['metadata']['commission_split'])->toBe('ACCT_abc123');
});

test('a split charge whose payload does not name the subaccount is logged as a warning, never rejected', function () {
    Log::spy();
    splitGateway();

    paystackSuccessWebhook(pendingTicket())->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'did not name the commission subaccount'))->once();
});

test('a split charge is accepted quietly when the payload names the subaccount in either shape', function () {
    Log::spy();
    splitGateway();

    paystackSuccessWebhook(pendingTicket(), ['subaccount' => ['subaccount_code' => 'ACCT_abc123']])->assertOk();
    paystackSuccessWebhook(pendingTicket(), ['split' => ['subaccounts' => [['subaccount' => 'ACCT_abc123', 'share' => 500]]]])->assertOk();

    Log::shouldNotHaveReceived('warning');
});

test('a charge that was never split raises no warning', function () {
    Log::spy();
    splitGateway();

    paystackSuccessWebhook(pendingTicket(), ['metadata' => ['event_registration_id' => pendingTicket()->id, 'type' => 'event_ticket']])->assertOk();

    Log::shouldNotHaveReceived('warning');
});

test('the payout schedule skips tenants who are paid by their own gateway', function () {
    $event = Event::factory()->create([
        'tenant_id' => $this->tenant->id,
        'currency' => 'GHS',
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDays(8),
    ]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $this->tenant->id, 'is_verified' => true]);

    EventPayoutSchedule::create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $event->id,
        'schedule_type' => 'post_event',
        'days_after_event' => 3,
        'holdback_percentage' => 10,
        'holdback_release_days' => 14,
        'minimum_payout_amount' => 1000,
        'auto_payout_enabled' => true,
        'preferred_account_id' => $account->id,
    ]);

    $registration = EventRegistration::factory()->create(['event_id' => $event->id, 'tenant_id' => $this->tenant->id]);
    app(LedgerService::class)->recordTicketSale($event, $registration, 10_000, 500, 'OWN-GW-1');

    Artisan::call('app:reconcile-payout-schedules');

    expect($event->payouts()->count())->toBe(0);
});

/**
 * @return array<string, array{debits: int, credits: int}>
 */
function ledgerByAccount(Event $event): array
{
    return collect(app(LedgerService::class)->getTrialBalance($event)['accounts'])
        ->mapWithKeys(fn (array $account): array => [$account['code'] => ['debits' => (int) $account['debits'], 'credits' => (int) $account['credits']]])
        ->all();
}

test('a collected commission books cash to the platform and nothing payable to the organizer', function () {
    splitGateway();
    $registration = pendingTicket();

    paystackSuccessWebhook($registration)->assertOk();

    $ledger = ledgerByAccount($registration->event);
    expect($ledger[LedgerAccount::CODE_GATEWAY_CLEARING]['debits'])->toBe(500)
        ->and($ledger[LedgerAccount::CODE_PLATFORM_REVENUE]['credits'])->toBe(500)
        ->and($ledger[LedgerAccount::CODE_ORGANIZER_PAYABLE]['credits'])->toBe(0)
        ->and(app(LedgerService::class)->getTrialBalance($registration->event)['is_balanced'])->toBeTrue();
});

test('a commission that was not collected is booked as owed by the organizer', function () {
    splitGateway();
    $registration = pendingTicket();

    paystackSuccessWebhook($registration, ['metadata' => ['event_registration_id' => $registration->id, 'type' => 'event_ticket']])->assertOk();

    expect(LedgerTransaction::where('event_id', $registration->event_id)->pluck('transaction_type')->all())->toBe([LedgerTransaction::TYPE_COMMISSION_RECEIVABLE]);

    $ledger = ledgerByAccount($registration->event);
    expect($ledger[LedgerAccount::CODE_ORGANIZER_PAYABLE]['debits'])->toBe(500)
        ->and($ledger[LedgerAccount::CODE_PLATFORM_REVENUE]['credits'])->toBe(500)
        ->and($ledger[LedgerAccount::CODE_GATEWAY_CLEARING]['debits'])->toBe(0);
});

test('refunding a Paystack own-gateway sale records the commission as owed back to the organizer', function () {
    $registration = pendingTicket();
    $ledgerService = app(LedgerService::class);

    $ledgerService->recordOwnGatewayTicketSale($registration->event, $registration, 10_000, 500, 'pay-1', 150, 'paystack', 500);
    $ledgerService->recordOwnGatewayRefund($registration->event, $registration, 10_000, 500, 'ref-1', 150, 'paystack');

    $ledger = ledgerByAccount($registration->event);
    expect($ledger[LedgerAccount::CODE_PLATFORM_REVENUE])->toBe(['debits' => 500, 'credits' => 500])
        ->and($ledger[LedgerAccount::CODE_ORGANIZER_PAYABLE]['credits'])->toBe(500)
        ->and($ledgerService->getTrialBalance($registration->event)['is_balanced'])->toBeTrue();
});

test('refunding a Stripe own-gateway sale takes the returned application fee out of the platform balance', function () {
    $registration = pendingTicket();
    $ledgerService = app(LedgerService::class);

    $ledgerService->recordOwnGatewayTicketSale($registration->event, $registration, 10_000, 500, 'pi_1', 320, 'stripe', 500);
    $ledgerService->recordOwnGatewayRefund($registration->event, $registration, 10_000, 500, 're_1', 320, 'stripe');

    $ledger = ledgerByAccount($registration->event);
    expect($ledger[LedgerAccount::CODE_GATEWAY_CLEARING])->toBe(['debits' => 500, 'credits' => 500])
        ->and($ledger[LedgerAccount::CODE_ORGANIZER_PAYABLE]['credits'])->toBe(0);
});

test('the organizer is told once when a working subaccount stops checking out', function () {
    Mail::fake();
    $gateway = splitGateway();
    fakePaystack(['active' => true, 'account_number' => '9999999999']);

    $verifier = app(CommissionSplitVerifier::class);
    $verifier->recheck($gateway->fresh());
    $verifier->recheck($gateway->fresh());

    Mail::assertQueued(CommissionSplitBrokenMail::class, 1);
});

test('the payments page tells the organizer what to enter and whether it checks out', function () {
    splitGateway();

    $this->actingAs($this->user)
        ->get("http://{$this->host}/settings/payments", ['HTTP_HOST' => $this->host])
        ->assertInertia(fn ($page) => $page
            ->where('commissionSplit.enforced', true)
            ->where('commissionSplit.destination.account_number', COMMISSION_ACCOUNT)
            ->where('gateways.paystack.commission_subaccount_code', 'ACCT_abc123')
            ->where('gateways.paystack.commission_verified', true)
            ->where('gateways.paystack.commission_problem', null));
});

test('a Paystack contribution on the organizer\'s own account is split with the platform', function () {
    config(['services.contributions.platform_fee_percentage' => 2]);
    splitGateway();
    fakePaystack();
    $event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'allow_contributions' => true]);

    $this->post("http://{$this->host}/e/{$event->slug}/contribute", [
        'contributor_name' => 'Ama',
        'contributor_email' => 'ama@example.com',
        'amount' => 100,
    ], ['HTTP_HOST' => $this->host])->assertRedirect('https://checkout.paystack.com/abc');

    $payload = initializePayload();
    expect($payload['subaccount'])->toBe('ACCT_abc123')
        ->and($payload['transaction_charge'])->toBe(9_800)
        ->and($payload['metadata']['commission_split'])->toBe('ACCT_abc123');
});

test('the public event page offers only the payment methods that will work', function () {
    splitGateway();
    $event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'allow_contributions' => true]);

    $this->get("http://{$this->host}/e/{$event->slug}", ['HTTP_HOST' => $this->host])
        ->assertInertia(fn ($page) => $page->where('event.contribution_providers', ['paystack']));

    $this->tenant->paymentGateways()->delete();

    $this->get("http://{$this->host}/e/{$event->slug}", ['HTTP_HOST' => $this->host])
        ->assertInertia(fn ($page) => $page->where('event.contribution_providers', []));
});
