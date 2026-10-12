<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Models\TenantCommissionCredit;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use App\Services\Finance\CommissionCredit;
use App\Services\Finance\LedgerService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

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
    $this->credit = app(CommissionCredit::class);

    config(['services.commission_split.account_number' => '0123456789']);
});

function creditGateway(): TenantPaymentGateway
{
    return TenantPaymentGateway::factory()->create([
        'tenant_id' => test()->tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_tenant',
        'is_active' => true,
        'meta' => ['commission_split' => ['subaccount_code' => 'ACCT_abc123', 'ok' => true, 'reason' => null]],
    ]);
}

function creditTicket(string $status = Event::STATUS_PUBLISHED): EventRegistration
{
    $event = Event::factory()->paid(10_000)->create([
        'tenant_id' => test()->tenant->id,
        'status' => $status,
        'platform_fee_percentage' => 5.0,
        'currency' => 'GHS',
    ]);

    return EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => test()->tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 10_000,
        'currency' => 'GHS',
    ]);
}

function fakeCreditPaystack(): void
{
    Http::fake([
        'api.paystack.co/subaccount/*' => Http::response(['status' => true, 'data' => ['active' => true, 'account_number' => '0123456789']]),
        'api.paystack.co/customer/CUS_1' => Http::response(['data' => ['email' => 'guest@example.com']]),
        'api.paystack.co/customer' => Http::response(['data' => ['customer_code' => 'CUS_1']]),
        'api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/abc']]),
    ]);
}

function creditCheckoutPayload(): ?array
{
    $sent = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/transaction/initialize'))->first();

    return $sent ? $sent[0]->data() : null;
}

function creditPay(EventRegistration $registration): void
{
    test()->post('http://'.test()->host."/e/{$registration->event->slug}/checkout/{$registration->id}/paystack", [], ['HTTP_HOST' => test()->host]);
}

function creditWebhook(EventRegistration $registration, array $metadata)
{
    $body = json_encode(['event' => 'charge.success', 'data' => [
        'reference' => 'ref_'.$registration->id,
        'amount' => 10_000,
        'fees' => 150,
        'currency' => 'GHS',
        'customer' => ['email' => 'guest@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        'metadata' => ['event_registration_id' => $registration->id, 'type' => 'event_ticket'] + $metadata,
    ]]);

    return test()->call('POST', '/webhooks/merchant/paystack/'.test()->tenant->id, [], [], [], [
        'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_tenant'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

function creditLedger(Event $event): array
{
    return collect(app(LedgerService::class)->getTrialBalance($event)['accounts'])
        ->mapWithKeys(fn (array $account): array => [$account['code'] => ['debits' => (int) $account['debits'], 'credits' => (int) $account['credits']]])
        ->all();
}

test('a Paystack own-gateway refund turns the commission owed back into credit, once', function () {
    $registration = creditTicket();
    $ledger = app(LedgerService::class);

    $ledger->recordOwnGatewayRefund($registration->event, $registration, 10_000, 500, 'ref-1', 150, 'paystack');
    $ledger->recordOwnGatewayRefund($registration->event, $registration, 10_000, 500, 'ref-1', 150, 'paystack');

    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(500);
});

test('a Stripe refund returns the fee itself so it creates no credit', function () {
    $registration = creditTicket();

    app(LedgerService::class)->recordOwnGatewayRefund($registration->event, $registration, 10_000, 500, 're-1', 320, 'stripe');

    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(0);
});

test('a refund where the platform keeps the commission creates no credit and reverses nothing', function () {
    $registration = creditTicket();

    app(LedgerService::class)->recordOwnGatewayRefund($registration->event, $registration, 10_000, 500, 'ref-2', 150, 'paystack', null, false);

    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(0)
        ->and(LedgerTransaction::where('event_id', $registration->event_id)->count())->toBe(0);
});

test('credit is kept per currency', function () {
    $this->credit->grant($this->tenant, 'GHS', 500);

    expect($this->credit->balance($this->tenant, 'USD'))->toBe(0)
        ->and($this->credit->consume($this->tenant, 'USD', 500))->toBe(0)
        ->and($this->credit->balance($this->tenant, 'GHS'))->toBe(500);
});

test('consuming takes only what is there', function () {
    $this->credit->grant($this->tenant, 'GHS', 300);

    expect($this->credit->consume($this->tenant, 'GHS', 500))->toBe(300)
        ->and($this->credit->consume($this->tenant, 'GHS', 500))->toBe(0)
        ->and($this->credit->balance($this->tenant, 'GHS'))->toBe(0);
});

test('checkout routes only the commission left after credit to the platform', function () {
    creditGateway();
    fakeCreditPaystack();
    $this->credit->grant($this->tenant, 'GHS', 300);

    creditPay(creditTicket());

    $payload = creditCheckoutPayload();
    expect($payload['transaction_charge'])->toBe(9_800)
        ->and($payload['metadata']['commission_credit_applied'])->toBe(300)
        ->and($this->credit->balance($this->tenant, 'GHS'))->toBe(300);
});

test('checkout needs no split at all when credit covers the whole commission', function () {
    creditGateway();
    fakeCreditPaystack();
    $this->credit->grant($this->tenant, 'GHS', 800);

    creditPay(creditTicket());

    $payload = creditCheckoutPayload();
    expect($payload)->not->toHaveKey('subaccount')
        ->and($payload['metadata']['commission_credit_applied'])->toBe(500);
});

test('the credit is drawn down when the payment is confirmed and the sale is booked against it', function () {
    creditGateway();
    $this->credit->grant($this->tenant, 'GHS', 300);
    $registration = creditTicket();

    creditWebhook($registration, ['commission_split' => 'ACCT_abc123', 'commission_credit_applied' => 300])->assertOk();

    $ledger = creditLedger($registration->event);
    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(0)
        ->and($ledger[LedgerAccount::CODE_GATEWAY_CLEARING]['debits'])->toBe(200)
        ->and($ledger[LedgerAccount::CODE_ORGANIZER_PAYABLE]['debits'])->toBe(300)
        ->and($ledger[LedgerAccount::CODE_PLATFORM_REVENUE]['credits'])->toBe(500)
        ->and(LedgerTransaction::where('event_id', $registration->event_id)->value('transaction_type'))->toBe(LedgerTransaction::TYPE_TICKET_SALE)
        ->and(app(LedgerService::class)->getTrialBalance($registration->event)['is_balanced'])->toBeTrue();
});

test('credit another sale spent first falls back to commission still owed', function () {
    creditGateway();
    $this->credit->grant($this->tenant, 'GHS', 100);
    $registration = creditTicket();

    creditWebhook($registration, ['commission_split' => 'ACCT_abc123', 'commission_credit_applied' => 300])->assertOk();

    $ledger = creditLedger($registration->event);
    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(0)
        ->and($ledger[LedgerAccount::CODE_GATEWAY_CLEARING]['debits'])->toBe(200)
        ->and($ledger[LedgerAccount::CODE_ORGANIZER_PAYABLE]['debits'])->toBe(300)
        ->and(LedgerTransaction::where('event_id', $registration->event_id)->value('transaction_type'))->toBe(LedgerTransaction::TYPE_COMMISSION_RECEIVABLE);
});

test('a redelivered webhook does not spend the credit twice', function () {
    creditGateway();
    $this->credit->grant($this->tenant, 'GHS', 600);
    $registration = creditTicket();

    creditWebhook($registration, ['commission_split' => 'ACCT_abc123', 'commission_credit_applied' => 300])->assertOk();
    creditWebhook($registration, ['commission_split' => 'ACCT_abc123', 'commission_credit_applied' => 300])->assertOk();

    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(300);
});

test('cancelling a confirmed Paystack ticket on an active event credits the commission back', function () {
    Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 'ref_c1']])]);
    creditGateway();
    $registration = creditTicket();
    $registration->update(['status' => EventRegistration::STATUS_CONFIRMED, 'payment_reference' => 'paid_ref_c1']);
    app(LedgerService::class)->recordOwnGatewayTicketSale($registration->event, $registration, 10_000, 500, 'paid_ref_c1', 150, 'paystack', 500);

    $this->actingAs($this->user)->postJson("http://{$this->host}/events/{$registration->event_id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $this->host])->assertOk();

    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(500);
});

test('refunds for a cancelled event follow the platform setting on returning commission', function (bool $returned, int $expected) {
    config(['services.commission_split.return_on_cancelled_event' => $returned]);
    Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 'ref_c2']])]);
    creditGateway();
    $registration = creditTicket(Event::STATUS_CANCELLED);
    $registration->update(['status' => EventRegistration::STATUS_CONFIRMED, 'payment_reference' => 'paid_ref_c2']);
    app(LedgerService::class)->recordOwnGatewayTicketSale($registration->event, $registration, 10_000, 500, 'paid_ref_c2', 150, 'paystack', 500);

    $this->actingAs($this->user)->postJson("http://{$this->host}/events/{$registration->event_id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $this->host])->assertOk();

    expect($this->credit->balance($this->tenant, 'GHS'))->toBe($expected);
})->with([
    'returned as credit by default' => [true, 500],
    'kept by the platform' => [false, 0],
]);

test('paying out the credit clears the balance and books the cash leaving', function () {
    $this->credit->grant($this->tenant, 'GHS', 500);

    Artisan::call('payments:commission-credits', ['tenant_slug' => 'acme', '--settle' => '200']);
    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(300);

    Artisan::call('payments:commission-credits', ['tenant_slug' => 'acme', '--settle' => 'all']);
    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(0)
        ->and(LedgerTransaction::where('tenant_id', $this->tenant->id)->where('transaction_type', LedgerTransaction::TYPE_PAYOUT)->count())->toBe(2);
});

test('settling more than the balance records only what was owed', function () {
    $this->credit->grant($this->tenant, 'GHS', 100);

    Artisan::call('payments:commission-credits', ['tenant_slug' => 'acme', '--settle' => '500']);

    expect($this->credit->balance($this->tenant, 'GHS'))->toBe(0)
        ->and(TenantCommissionCredit::where('tenant_id', $this->tenant->id)->value('amount'))->toBe(0);
});

test('the command lists organizers owed credit', function () {
    $this->credit->grant($this->tenant, 'GHS', 500);

    Artisan::call('payments:commission-credits');

    expect(Artisan::output())->toContain('acme')->toContain('500');
});

test('the payments page shows the organizer their credit', function () {
    $this->credit->grant($this->tenant, 'GHS', 1250);

    $this->actingAs($this->user)
        ->get("http://{$this->host}/settings/payments", ['HTTP_HOST' => $this->host])
        ->assertInertia(fn ($page) => $page->where('commissionCredit', [['currency' => 'GHS', 'amount' => 1250]]));
});
