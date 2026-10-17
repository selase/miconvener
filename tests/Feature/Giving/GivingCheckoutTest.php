<?php

declare(strict_types=1);

use App\Enum\CommercialStreamEnum;
use App\Models\GivingPaymentAttempt;
use App\Models\Tenant;
use App\Services\Finance\CommercialTerms;
use App\Services\Giving\GivingCheckout;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\GivingScenario;

test('Giving no-package commission receives the Free two percent default without changing legacy contributions', function () {
    $tenant = Tenant::factory()->create(['package_id' => null]);
    expect(app(CommercialTerms::class)->for($tenant, CommercialStreamEnum::Giving)->rate)->toBe(2.0)
        ->and(app(CommercialTerms::class)->for($tenant, CommercialStreamEnum::Contributions)->rate)->toBe(1.0);
});

test('checkout freezes terms and identity without creating members and reuses a submitted operation', function () {
    [$tenant, , $fund, $attempt] = GivingScenario::online();
    expect((float) $attempt->rate)->toBe(1.0)->and($attempt->commission_amount)->toBe(100)
        ->and($attempt->state)->toBe('open')->and($attempt->settlement_mode)->toBe(Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT)
        ->and(App\Models\Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
    $url = app(GivingCheckout::class)->start($fund, ['amount_minor' => 10000, 'currency' => 'GHS', 'payer_email' => 'giver@example.test', 'provider' => 'paystack'], app('session.store'), $attempt->submission_key);
    expect($url)->toBe('https://checkout.paystack.com/giving-test')->and(GivingPaymentAttempt::query()->count())->toBe(1);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transaction/initialize') && $request['amount'] === 10000 && $request['reference'] === $attempt->reference && $request['metadata']['giving_attempt_id'] === $attempt->id);
});

test('online readiness and valid fund scope are required independently of configured credentials', function () {
    [, , $fund] = GivingScenario::make(['visibility' => 'public']);
    config(['services.settlement.paystack.secret_key' => 'sk_test_giving']);
    expect(fn () => app(GivingCheckout::class)->start($fund, ['amount_minor' => 10000, 'currency' => 'GHS'], app('session.store'), (string) Str::uuid()))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('a provider initialization timeout is retained and retry never blindly creates another charge', function () {
    [$tenant, , $fund, $old] = GivingScenario::online();
    Http::fake(['*' => fn () => throw new Illuminate\Http\Client\ConnectionException('Synthetic timeout')]);
    $key = (string) Str::uuid();
    expect(fn () => app(GivingCheckout::class)->start($fund, ['amount_minor' => 10000, 'currency' => 'GHS', 'provider' => 'paystack'], app('session.store'), $key))->toThrow(ValidationException::class);
    $attempt = GivingPaymentAttempt::withoutGlobalScopes()->where('submission_key', $key)->firstOrFail();
    expect($attempt->state)->toBe('needs_review')->and($attempt->gift->status)->toBe('pending');
    expect(fn () => app(GivingCheckout::class)->start($fund, ['amount_minor' => 10000, 'currency' => 'GHS', 'provider' => 'paystack'], app('session.store'), $key))->toThrow(ValidationException::class);
    expect(GivingPaymentAttempt::withoutGlobalScopes()->where('submission_key', $key)->count())->toBe(1);
});

test('exact decimal amounts reject excess precision negatives exponent notation and oversized input', function (string $input) {
    expect(fn () => App\Services\Giving\GivingAmounts::minor($input))->toThrow(ValidationException::class);
})->with(['0.001', '-1', '1e3', 'NaN', '1000000000000000000000', '1,000.00']);

test('exact decimal conversion keeps integer minor units', function () {
    expect(App\Services\Giving\GivingAmounts::minor('100.01'))->toBe(10001)
        ->and(App\Services\Giving\GivingAmounts::minor('0.10'))->toBe(10);
});

test('callback verification stays on its original account after the organisation changes settlement', function () {
    [$tenant, , , $attempt] = GivingScenario::online();
    $tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    App\Models\TenantPaymentGateway::factory()->create(['tenant_id' => $tenant->id, 'provider' => 'paystack', 'api_key_encrypted' => 'sk_test_different_account', 'is_active' => true]);
    $data = GivingScenario::verified($attempt);
    $data['id'] = 123;
    Http::fake(['*transaction/verify/*' => Http::response(['status' => true, 'data' => $data])]);
    $verified = app(App\Services\Giving\GivingGateways::class)->verify($attempt);
    expect($verified['account_identity'])->toBe($attempt->account_identity);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/transaction/verify/') && $request->hasHeader('Authorization', 'Bearer sk_test_giving'));
    config(['services.settlement.paystack.secret_key' => 'sk_test_rotated']);
    expect(fn () => app(App\Services\Giving\GivingGateways::class)->verify($attempt))->toThrow(ValidationException::class);
});

test('Giving checkout records a negotiated cap as well as the frozen percentage', function () {
    [$tenant, , $fund] = GivingScenario::online();
    App\Models\CommercialTermsOverride::query()->create(['tenant_id' => $tenant->id, 'stream' => 'giving', 'rate' => 3, 'cap_amount' => 50, 'reason' => 'Approved cap']);
    $key = (string) Str::uuid();
    app(GivingCheckout::class)->start($fund, ['amount_minor' => 10000, 'currency' => 'GHS', 'provider' => 'paystack'], app('session.store'), $key);
    $attempt = GivingPaymentAttempt::withoutGlobalScopes()->where('submission_key', $key)->sole();
    expect((float) $attempt->rate)->toBe(3.0)->and($attempt->cap_amount)->toBe(50)->and($attempt->commission_amount)->toBe(50);
});

test('public checkout accepts exact money and keeps the result private to its receipt capability', function () {
    [$tenant, , $fund] = GivingScenario::online();
    $host = eventSubdomainHost($tenant->slug);
    $this->withServerVariables(['HTTP_HOST' => $host]);
    $key = (string) Str::uuid();
    $this->postJson('http://'.$host.'/give/'.$fund->slug.'/checkout', ['amount' => '100.00', 'currency' => 'GHS', 'provider' => 'paystack', 'payer_email' => 'giver@example.test', 'submission_key' => $key])->assertOk()->assertJsonPath('checkout_url', 'https://checkout.paystack.com/giving-test');
    $attempt = GivingPaymentAttempt::withoutGlobalScopes()->where('submission_key', $key)->sole();
    $this->getJson('http://'.$host.'/giving/payments/'.$attempt->reference)->assertOk()->assertJsonPath('status', 'open')->assertHeader('Cache-Control', 'no-store, private');
    $this->flushSession();
    $this->getJson('http://'.$host.'/giving/payments/'.$attempt->reference)->assertNotFound();
});
