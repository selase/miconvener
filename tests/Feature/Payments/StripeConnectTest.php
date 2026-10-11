<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Event;
use App\Models\EventContribution;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use App\Services\Payment\StripeConnect;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/**
 * Stands in for Stripe's API so a test can see exactly what the platform sent
 * (the account header, the application fee) and answer with canned objects.
 */
final class FakeStripeHttp implements ClientInterface
{
    /** @var array<int, array{method: string, url: string, headers: array<int, string>, params: array<string, mixed>}> */
    public array $requests = [];

    /** @param array<string, array<string, mixed>> $responses keyed by a URL fragment */
    public function __construct(private array $responses = []) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];

        foreach ($this->responses as $fragment => $body) {
            if (str_contains($absUrl, $fragment)) {
                return [json_encode($body), 200, []];
            }
        }

        return [json_encode(['error' => ['message' => "Unexpected Stripe call: {$absUrl}"]]), 404, []];
    }

    /** @return array{method: string, url: string, headers: array<int, string>, params: array<string, mixed>}|null */
    public function requestTo(string $fragment): ?array
    {
        foreach ($this->requests as $request) {
            if (str_contains($request['url'], $fragment)) {
                return $request;
            }
        }

        return null;
    }
}

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    config([
        'services.stripe.secret' => 'sk_test_platform',
        'services.stripe.connect.client_id' => 'ca_test_client',
        'services.stripe.connect.webhook_secret' => 'whsec_connect',
    ]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared', 'settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $this->tenant->features()->create(['feature_key' => 'paid_tickets', 'enabled' => true]);

    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->user->assignRole('Org Superadmin');
    $this->tenant->users()->attach($this->user->id);

    $this->host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');
});

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

function fakeStripe(array $responses = []): FakeStripeHttp
{
    $fake = new FakeStripeHttp($responses);
    ApiRequestor::setHttpClient($fake);

    return $fake;
}

function connectedStripe(string $account = 'acct_123'): TenantPaymentGateway
{
    return TenantPaymentGateway::factory()->create([
        'tenant_id' => test()->tenant->id,
        'provider' => 'stripe',
        'provider_account_id' => $account,
        'api_key_encrypted' => null,
        'public_key_encrypted' => null,
        'webhook_secret_encrypted' => null,
        'is_active' => true,
    ]);
}

function stripePendingTicket(float $feePercentage = 5.0): EventRegistration
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

function stripeConnectWebhook(array $event, string $secret = 'whsec_connect')
{
    $body = json_encode($event);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return test()->call('POST', '/webhooks/stripe/connect', [], [], [], [
        'HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}",
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

function completedSessionEvent(EventRegistration $registration, string $account = 'acct_123', string $paymentStatus = 'paid'): array
{
    return [
        'id' => 'evt_1',
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'account' => $account,
        'data' => ['object' => [
            'id' => 'cs_test_1',
            'object' => 'checkout.session',
            'payment_status' => $paymentStatus,
            'payment_intent' => 'pi_test_1',
            'amount_total' => 10_000,
            'currency' => 'ghs',
            'customer_details' => ['email' => 'guest@example.com', 'name' => 'Guest Person'],
            'metadata' => ['event_registration_id' => $registration->id, 'tenant_id' => $registration->tenant_id, 'type' => 'event_ticket'],
        ]],
    ];
}

test('connecting sends the tenant to Stripe with a sealed single-use state', function () {
    $response = $this->actingAs($this->user)
        ->get("http://{$this->host}/settings/payments/stripe/connect", ['HTTP_HOST' => $this->host]);

    $response->assertRedirectContains('https://connect.stripe.com/oauth/authorize');
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query['client_id'])->toBe('ca_test_client')
        ->and($query['redirect_uri'])->toBe(route('stripe.connect.callback'))
        ->and($query['state'])->not->toContain($this->tenant->id);
});

test('connecting is unavailable until the platform has set up Stripe Connect', function () {
    config(['services.stripe.connect.client_id' => null]);

    $this->actingAs($this->user)
        ->get("http://{$this->host}/settings/payments/stripe/connect", ['HTTP_HOST' => $this->host])
        ->assertSessionHas('error');
});

test('the callback links the connected account and returns the tenant to their settings', function () {
    $fake = fakeStripe(['oauth/token' => ['stripe_user_id' => 'acct_123', 'livemode' => false, 'scope' => 'read_write']]);
    $state = connectState();

    $this->get(route('stripe.connect.callback', ['code' => 'ac_code', 'state' => $state]))
        ->assertRedirect($this->tenant->url('/settings/payments').'?stripe=connected');

    $gateway = TenantPaymentGateway::where('tenant_id', $this->tenant->id)->where('provider', 'stripe')->firstOrFail();
    expect($gateway->provider_account_id)->toBe('acct_123')
        ->and($gateway->api_key_encrypted)->toBeNull()
        ->and($gateway->is_active)->toBeTrue()
        ->and($fake->requestTo('oauth/token')['params']['code'])->toBe('ac_code');
});

test('a forged or reused state links nothing', function () {
    fakeStripe(['oauth/token' => ['stripe_user_id' => 'acct_123', 'livemode' => false]]);

    $this->get(route('stripe.connect.callback', ['code' => 'ac_code', 'state' => 'forged']))->assertRedirect(route('home'));

    $state = connectState();
    $this->get(route('stripe.connect.callback', ['code' => 'ac_code', 'state' => $state]));
    TenantPaymentGateway::query()->delete();
    $this->get(route('stripe.connect.callback', ['code' => 'ac_code', 'state' => $state]))->assertRedirect(route('home'));

    expect(TenantPaymentGateway::count())->toBe(0);
});

test('a tenant who declines on Stripe is sent back without a link', function () {
    $this->get(route('stripe.connect.callback', ['error' => 'access_denied', 'state' => connectState()]))
        ->assertRedirect($this->tenant->url('/settings/payments').'?stripe=declined');

    expect(TenantPaymentGateway::count())->toBe(0);
});

function connectState(): string
{
    $url = app(StripeConnect::class)->authorizationUrl(test()->tenant, test()->user);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['state'];
}

test('disconnecting revokes access on Stripe and forgets the account', function () {
    $fake = fakeStripe(['oauth/deauthorize' => ['stripe_user_id' => 'acct_123']]);
    connectedStripe();

    $this->actingAs($this->user)
        ->delete("http://{$this->host}/settings/payments/stripe", [], ['HTTP_HOST' => $this->host])
        ->assertSessionHas('success', 'Stripe disconnected.');

    expect(TenantPaymentGateway::count())->toBe(0)
        ->and($fake->requestTo('oauth/deauthorize')['params']['stripe_user_id'])->toBe('acct_123');
});

test('the settings page reports the connection without exposing anything secret', function () {
    connectedStripe();

    $this->actingAs($this->user)
        ->get("http://{$this->host}/settings/payments", ['HTTP_HOST' => $this->host])
        ->assertInertia(fn ($page) => $page
            ->where('stripe.configured', true)
            ->where('stripe.connected', true)
            ->where('stripe.account_id', 'acct_123'));
});

test('paying by Stripe creates a direct charge on the connected account carrying the commission as an application fee', function () {
    $fake = fakeStripe(['checkout/sessions' => ['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']]);
    connectedStripe();
    $registration = stripePendingTicket();

    $this->post("http://{$this->host}/e/{$registration->event->slug}/checkout/{$registration->id}/stripe", [], ['HTTP_HOST' => $this->host])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

    $request = $fake->requestTo('checkout/sessions');
    expect($request['headers'])->toContain('Stripe-Account: acct_123')
        ->and($request['params']['line_items'][0]['price_data']['unit_amount'])->toBe(10_000)
        ->and($request['params']['payment_intent_data']['application_fee_amount'])->toBe(500)
        ->and($request['params']['metadata']['event_registration_id'])->toBe($registration->id)
        ->and($request['params']['customer_email'])->toBe('guest@example.com');
});

test('a waived commission is charged with no application fee', function () {
    $fake = fakeStripe(['checkout/sessions' => ['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']]);
    connectedStripe();
    $registration = stripePendingTicket(feePercentage: 0.0);

    $this->post("http://{$this->host}/e/{$registration->event->slug}/checkout/{$registration->id}/stripe", [], ['HTTP_HOST' => $this->host])
        ->assertRedirect();

    expect($fake->requestTo('checkout/sessions')['params']['payment_intent_data'])->not->toHaveKey('application_fee_amount');
});

test('paying by Stripe fails cleanly when the tenant has not connected', function () {
    $registration = stripePendingTicket();

    $this->post("http://{$this->host}/e/{$registration->event->slug}/checkout/{$registration->id}/stripe", [], ['HTTP_HOST' => $this->host])
        ->assertSessionHas('error', 'Online payment via Stripe is currently unavailable for this event.');
});

test('a platform-default tenant is not offered Stripe', function () {
    connectedStripe();
    $this->tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    $registration = stripePendingTicket();

    $this->post("http://{$this->host}/e/{$registration->event->slug}/checkout/{$registration->id}/stripe", [], ['HTTP_HOST' => $this->host])
        ->assertSessionHas('error');
});

test('a completed Stripe session confirms the registration and books the sale once', function () {
    Mail::fake();
    fakeStripe(['payment_intents/pi_test_1' => [
        'id' => 'pi_test_1',
        'object' => 'payment_intent',
        'latest_charge' => ['id' => 'ch_1', 'object' => 'charge', 'balance_transaction' => ['id' => 'txn_1', 'object' => 'balance_transaction', 'fee' => 320, 'currency' => 'ghs']],
    ]]);
    connectedStripe();
    $registration = stripePendingTicket();
    $event = completedSessionEvent($registration);

    stripeConnectWebhook($event)->assertOk();
    stripeConnectWebhook($event)->assertOk();

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_CONFIRMED)
        ->and($registration->payment_reference)->toBe('pi_test_1')
        ->and($registration->gateway_fee_amount)->toBe(320);

    $entries = EventLedgerEntry::where('tenant_id', $this->tenant->id)->where('type', EventLedgerEntry::TYPE_CHARGE)->get();
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->commission_amount)->toBe(500)
        ->and(MerchantTransaction::where('tenant_id', $this->tenant->id)->where('provider', 'stripe')->count())->toBe(1);
});

test('a session that is not paid yet confirms nothing', function () {
    fakeStripe();
    connectedStripe();
    $registration = stripePendingTicket();

    stripeConnectWebhook(completedSessionEvent($registration, paymentStatus: 'unpaid'))->assertOk();

    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);
});

test('a webhook with a bad signature is rejected', function () {
    connectedStripe();
    $registration = stripePendingTicket();

    stripeConnectWebhook(completedSessionEvent($registration), secret: 'whsec_wrong')->assertStatus(400);

    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);
});

test('an event from an account we are not connected to is acknowledged and ignored', function () {
    fakeStripe();
    connectedStripe('acct_123');
    $registration = stripePendingTicket();

    stripeConnectWebhook(completedSessionEvent($registration, account: 'acct_stranger'))->assertOk()->assertJson(['status' => 'ignored']);

    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);
});

test('revoking the platform from the Stripe dashboard disconnects the tenant', function () {
    connectedStripe();

    stripeConnectWebhook(['id' => 'evt_2', 'object' => 'event', 'type' => 'account.application.deauthorized', 'account' => 'acct_123', 'data' => ['object' => ['id' => 'ca_test_client', 'object' => 'application']]])->assertOk();

    expect(TenantPaymentGateway::count())->toBe(0);
});

test('refunding a Stripe charge acts on the connected account and returns the commission too', function () {
    $fake = fakeStripe(['refunds' => ['id' => 're_1', 'object' => 'refund']]);
    $row = connectedStripe();

    $refundId = app(StripeConnect::class)->gatewayFor($row)->refund('pi_test_1');

    $request = $fake->requestTo('refunds');
    expect($refundId)->toBe('re_1')
        ->and($request['headers'])->toContain('Stripe-Account: acct_123')
        ->and($request['params']['payment_intent'])->toBe('pi_test_1')
        ->and($request['params']['refund_application_fee'])->toBe('true');
});

test('a Stripe sale whose application fee was taken books the commission as cash received', function () {
    Mail::fake();
    fakeStripe(['payment_intents/pi_test_1' => ['id' => 'pi_test_1', 'object' => 'payment_intent', 'latest_charge' => null]]);
    connectedStripe();
    $registration = stripePendingTicket();
    $event = completedSessionEvent($registration);
    $event['data']['object']['metadata']['commission_collected'] = '500';

    stripeConnectWebhook($event)->assertOk();

    $ledger = collect(app(\App\Services\Finance\LedgerService::class)->getTrialBalance($registration->event)['accounts'])->keyBy('code');
    expect((int) $ledger[\App\Models\LedgerAccount::CODE_GATEWAY_CLEARING]['debits'])->toBe(500)
        ->and((int) $ledger[\App\Models\LedgerAccount::CODE_ORGANIZER_PAYABLE]['credits'])->toBe(0);
});

test('checkout tells the webhook how much commission it collected', function () {
    $fake = fakeStripe(['checkout/sessions' => ['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']]);
    connectedStripe();
    $registration = stripePendingTicket();

    $this->post("http://{$this->host}/e/{$registration->event->slug}/checkout/{$registration->id}/stripe", [], ['HTTP_HOST' => $this->host]);

    expect($fake->requestTo('checkout/sessions')['params']['metadata']['commission_collected'])->toBe('500');
});

test('a Stripe contribution is a direct charge with the contribution commission as the application fee', function () {
    config(['services.contributions.platform_fee_percentage' => 2]);
    $fake = fakeStripe(['checkout/sessions' => ['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']]);
    connectedStripe();
    $event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'allow_contributions' => true]);

    $this->post("http://{$this->host}/e/{$event->slug}/contribute", [
        'contributor_name' => 'Ama',
        'contributor_email' => 'ama@example.com',
        'amount' => 100,
        'provider' => 'stripe',
    ], ['HTTP_HOST' => $this->host])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

    $request = $fake->requestTo('checkout/sessions');
    expect($request['headers'])->toContain('Stripe-Account: acct_123')
        ->and($request['params']['payment_intent_data']['application_fee_amount'])->toBe(200)
        ->and($request['params']['metadata']['type'])->toBe('event_contribution');
});

test('a paid Stripe contribution session completes the contribution and books it on the own-gateway ledger', function () {
    fakeStripe(['payment_intents/pi_test_9' => ['id' => 'pi_test_9', 'object' => 'payment_intent', 'latest_charge' => null]]);
    connectedStripe();
    $event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'allow_contributions' => true, 'currency' => 'GHS']);
    $contribution = EventContribution::create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama',
        'amount' => 10_000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_PENDING_PAYMENT,
        'payment_reference' => 'CONTRIB-X',
        'is_approved' => true,
    ]);

    stripeConnectWebhook(['id' => 'evt_9', 'object' => 'event', 'type' => 'checkout.session.completed', 'account' => 'acct_123', 'data' => ['object' => [
        'id' => 'cs_9', 'object' => 'checkout.session', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_9',
        'amount_total' => 10_000, 'currency' => 'ghs',
        'metadata' => ['type' => 'event_contribution', 'event_contribution_id' => $contribution->id, 'commission_collected' => '100'],
    ]]])->assertOk();

    $ledger = collect(app(\App\Services\Finance\LedgerService::class)->getTrialBalance($event)['accounts'])->keyBy('code');
    expect($contribution->fresh()->status)->toBe(EventContribution::STATUS_COMPLETED)
        ->and((int) $ledger[\App\Models\LedgerAccount::CODE_GATEWAY_CLEARING]['debits'])->toBe(100)
        ->and((int) $ledger[\App\Models\LedgerAccount::CODE_ORGANIZER_PAYABLE]['credits'])->toBe(0);
});

test('credit the platform owes the organizer reduces the application fee on a Stripe ticket', function () {
    $fake = fakeStripe(['checkout/sessions' => ['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']]);
    connectedStripe();
    app(\App\Services\Finance\CommissionCredit::class)->grant($this->tenant, 'GHS', 300);
    $registration = stripePendingTicket();

    $this->post("http://{$this->host}/e/{$registration->event->slug}/checkout/{$registration->id}/stripe", [], ['HTTP_HOST' => $this->host]);

    $request = $fake->requestTo('checkout/sessions');
    expect($request['params']['payment_intent_data']['application_fee_amount'])->toBe(200)
        ->and($request['params']['metadata']['commission_collected'])->toBe('200')
        ->and($request['params']['metadata']['commission_credit_applied'])->toBe('300');
});
