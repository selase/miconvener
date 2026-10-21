<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Mail\Billing\GhanaPriceAdjusted;
use App\Mail\Billing\GhanaTopUpFailedAlert;
use App\Models\ExchangeRate;
use App\Models\Package;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Pricing\GhanaEligibility;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Paying the Ghana price with Ghana money proves the organisation is in Ghana.
 * Paying it with a foreign card does not: the difference to the USD price is
 * charged to the same card. Whichever of the callback and the webhook arrives
 * first settles it, once.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Cache::flush();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config(['services.paystack.currency' => 'GHS', 'services.payment.default' => 'paystack', 'services.paystack.metadata_source' => 'miconvener', 'services.paystack.secret_key' => 'sk_test']);
    Mail::fake();
    ExchangeRate::factory()->create(['rate' => 11.77, 'as_of' => now()->toDateString()]);

    $this->owner = User::factory()->create(['email' => 'owner@acme.test']);
    $this->tenant = setActiveTenantForTest($this->owner, ['package_id' => Package::query()->where('slug', 'free')->value('id'), 'meta' => ['paystack_id' => 'CUS_1']]);
    makeTenantOwner($this->owner, $this->tenant);

    $this->finance = User::factory()->create(['tenant_id' => null, 'email' => 'finance@miconvener.test']);
    setPermissionsTeamId(null);
    $this->finance->assignRole(Role::query()->where('name', 'Platform Finance')->whereNull('tenant_id')->firstOrFail());
    setPermissionsTeamId($this->tenant->id);

    /** A Ghana price of GHS 300 against a US$ 50 price: the case where Ghana is cheaper. */
    $this->pricing = fn (bool $proof = true, ?int $usd = 5000): array => ['display_currency' => 'GHS', 'display_amount_minor' => 30000, 'fx_rate' => null, 'fx_as_of' => null, 'fx_source' => null, 'proof_required' => $proof] + ($usd === null ? [] : ['usd_alternative_minor' => $usd]);
    $this->payment = fn (string $reference, array $origin, ?array $pricing = null): array => [
        'status' => 'success', 'reference' => $reference, 'transaction_id' => 1, 'amount' => 30000, 'currency' => 'GHS',
        'channel' => $origin['channel'], 'customer_email' => 'owner@acme.test',
        'authorization' => ['authorization_code' => 'AUTH_'.$reference, 'reusable' => true, 'channel' => $origin['channel'], 'country_code' => $origin['country_code'], 'brand' => 'visa', 'last4' => '4081'],
        'metadata' => ['type' => 'plan_subscription', 'plan_slug' => 'starter', 'interval' => 'month', 'tenant_id' => $this->tenant->id, 'pricing' => $pricing ?? ($this->pricing)()],
    ];
    $this->viaCallback = function (array $payment): void {
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('verifyTransaction')->andReturn($payment);
        $this->swap(PaymentGateway::class, $gateway);
        $this->actingAs($this->owner)->get(route('billing.callback', ['reference' => $payment['reference']]))->assertRedirect();
    };
    $this->viaWebhook = function (array $payment): void {
        $payload = json_encode(['event' => 'charge.success', 'data' => [
            'reference' => $payment['reference'], 'status' => 'success', 'amount' => $payment['amount'], 'currency' => $payment['currency'], 'channel' => $payment['channel'],
            'customer' => ['email' => 'owner@acme.test'], 'authorization' => $payment['authorization'],
            'metadata' => $payment['metadata'] + ['source' => 'miconvener'],
        ]]);
        $this->call('POST', route('webhooks.paystack'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, 'sk_test')], $payload)->assertOk();
    };
    $this->fakeTopUp = fn (string $status = 'success') => Http::fake([
        'api.paystack.co/transaction/charge_authorization' => fn ($request) => Http::response(['status' => true, 'data' => [
            'status' => $status, 'reference' => $request['reference'], 'amount' => $request['amount'], 'currency' => 'GHS', 'channel' => 'card', 'gateway_response' => $status === 'success' ? 'Approved' : 'Insufficient Funds',
            'authorization' => ['authorization_code' => $request['authorization_code'], 'reusable' => true, 'channel' => 'card', 'country_code' => 'NG'],
        ]]),
    ]);
});

test('a mobile money payment on the Ghana price makes the organisation Ghana eligible and records where it came from', function (): void {
    ($this->viaCallback)(($this->payment)('ref_momo', ['channel' => 'mobile_money', 'country_code' => 'GH']));

    $tenant = $this->tenant->fresh();
    expect($tenant->isGhanaEligible())->toBeTrue()
        ->and($tenant->pricing_region_evidence['source'])->toBe(GhanaEligibility::SOURCE_GHANA_PAYMENT)
        ->and($tenant->pricing_region_evidence['detail'])->toMatchArray(['channel' => 'mobile_money']);

    $transaction = Transaction::query()->where('provider_transaction_id', 'ref_momo')->firstOrFail();
    expect($transaction->meta['payment_origin'])->toEqual(['channel' => 'mobile_money', 'country_code' => 'GH'])
        ->and($transaction->meta['ghana_proof'])->toBe('proven');
});

test('a card issued in Ghana does too', function (): void {
    ($this->viaCallback)(($this->payment)('ref_gh_card', ['channel' => 'card', 'country_code' => 'GH']));

    expect($this->tenant->fresh()->isGhanaEligible())->toBeTrue();
    Http::assertNothingSent();
});

test('a foreign card is topped up to the USD price on the same authorization, and the organisation is settled in USD', function (): void {
    ($this->fakeTopUp)();

    ($this->viaCallback)(($this->payment)('ref_foreign', ['channel' => 'card', 'country_code' => 'NG']));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'charge_authorization')
        && $request['amount'] === 28850 && $request['authorization_code'] === 'AUTH_ref_foreign' && $request['reference'] === 'topup-ref_foreign' && $request['currency'] === 'GHS'
        && $request['email'] === 'owner@acme.test' && $request['metadata']['type'] === 'ghana_price_topup');

    $tenant = $this->tenant->fresh();
    expect($tenant->isGhanaEligible())->toBeFalse()->and($tenant->pricing_region)->toBe('INTL');

    $topUp = Transaction::query()->where('provider_transaction_id', 'topup-ref_foreign')->firstOrFail();
    expect($topUp->amount)->toEqual(28850)->and($topUp->meta['type'])->toBe('ghana_price_topup')->and($topUp->meta['original_reference'])->toBe('ref_foreign');
    expect(Transaction::query()->where('provider_transaction_id', 'ref_foreign')->firstOrFail()->meta['ghana_proof'])->toBe('topped_up');
    Mail::assertQueued(GhanaPriceAdjusted::class, fn (GhanaPriceAdjusted $mail): bool => $mail->hasTo('owner@acme.test') && $mail->outcome === 'topped_up');
});

test('a failed top-up keeps the paid period, moves the next to USD and tells the platform', function (): void {
    ($this->fakeTopUp)('failed');

    ($this->viaCallback)(($this->payment)('ref_declined', ['channel' => 'card', 'country_code' => 'NG']));

    $tenant = $this->tenant->fresh();
    expect($tenant->pricing_region)->toBe('INTL')
        ->and(Subscription::query()->where('tenant_id', $this->tenant->id)->latest('id')->first()->billing_currency)->toBe('USD')
        ->and($tenant->package?->slug)->toBe('starter')
        ->and(Transaction::query()->where('provider_transaction_id', 'ref_declined')->firstOrFail()->meta['ghana_proof'])->toBe('top_up_failed')
        ->and(Transaction::query()->where('provider_transaction_id', 'topup-ref_declined')->exists())->toBeFalse();
    Mail::assertQueued(GhanaPriceAdjusted::class, fn (GhanaPriceAdjusted $mail): bool => $mail->outcome === 'top_up_failed');
    Mail::assertQueued(GhanaTopUpFailedAlert::class, fn (GhanaTopUpFailedAlert $mail): bool => $mail->hasTo('finance@miconvener.test'));
});

test('a foreign card that already paid at least the USD price is settled without a top-up', function (): void {
    ($this->fakeTopUp)();

    ($this->viaCallback)(($this->payment)('ref_over', ['channel' => 'card', 'country_code' => 'NG'], ($this->pricing)(true, 1900)));

    Http::assertNothingSent();
    expect($this->tenant->fresh()->pricing_region)->toBe('INTL')
        ->and(Transaction::query()->where('provider_transaction_id', 'ref_over')->firstOrFail()->meta['ghana_proof'])->toBe('paid_in_full');
});

test('with no USD price to compare to, nothing is granted or charged and it is left for a person', function (): void {
    ($this->fakeTopUp)();

    ($this->viaCallback)(($this->payment)('ref_nousd', ['channel' => 'card', 'country_code' => 'NG'], ($this->pricing)(true, null)));

    Http::assertNothingSent();
    expect($this->tenant->fresh()->pricing_region)->toBeNull()
        ->and(Transaction::query()->where('provider_transaction_id', 'ref_nousd')->firstOrFail()->meta['ghana_proof'])->toBe('unresolved');
    Mail::assertQueued(GhanaTopUpFailedAlert::class);
});

test('the webhook and the callback settle once, whichever comes first', function (): void {
    ($this->fakeTopUp)();
    $payment = ($this->payment)('ref_both', ['channel' => 'card', 'country_code' => 'NG']);

    ($this->viaWebhook)($payment);
    ($this->viaCallback)($payment);
    ($this->viaWebhook)($payment);

    Http::assertSentCount(1);
    expect(Transaction::query()->where('provider_transaction_id', 'topup-ref_both')->count())->toBe(1)
        ->and(Transaction::query()->where('provider_transaction_id', 'ref_both')->count())->toBe(1);
    Mail::assertQueued(GhanaPriceAdjusted::class, 1);
});

test('the webhook alone proves a Ghana mobile money payment when the browser never returns', function (): void {
    ($this->viaWebhook)(($this->payment)('ref_hook_momo', ['channel' => 'mobile_money', 'country_code' => 'GH']));

    expect($this->tenant->fresh()->isGhanaEligible())->toBeTrue();
});

test('no proof is asked of a payment that did not need it, but where it came from is still recorded', function (): void {
    ($this->fakeTopUp)();

    ($this->viaCallback)(($this->payment)('ref_plain', ['channel' => 'card', 'country_code' => 'NG'], ($this->pricing)(false)));

    Http::assertNothingSent();
    $transaction = Transaction::query()->where('provider_transaction_id', 'ref_plain')->firstOrFail();
    expect($this->tenant->fresh()->pricing_region)->toBeNull()
        ->and($transaction->meta['payment_origin'])->toEqual(['channel' => 'card', 'country_code' => 'NG'])
        ->and($transaction->meta)->not->toHaveKey('ghana_proof');
});

test('an eligible organisation is never changed by a later payment', function (): void {
    app(GhanaEligibility::class)->grant($this->tenant, GhanaEligibility::SOURCE_SUPERADMIN, ['reason' => 'Known'], $this->finance);

    ($this->viaCallback)(($this->payment)('ref_later', ['channel' => 'card', 'country_code' => 'NG'], ($this->pricing)(false)));

    expect($this->tenant->fresh()->pricing_region)->toBe('GH')->and($this->tenant->fresh()->pricing_region_evidence['source'])->toBe(GhanaEligibility::SOURCE_SUPERADMIN);
});
