<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\SubscriptionProvisioningService;
use App\Services\Payment\PaystackGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Paystack reports one payment twice: the customer's browser returns to the
 * callback, and the webhook arrives on its own. Paystack's verify endpoint names
 * the payment by a numeric id, the webhook by its reference string. The callback
 * used to key fulfilment on the id and the webhook on the reference, so neither
 * recognised the other's record and one payment was fulfilled twice.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config([
        'services.paystack.secret_key' => 'sk_one_account',
        'services.settlement.paystack.secret_key' => 'sk_one_account',
        'services.paystack.metadata_source' => 'miconvener',
    ]);
    Mail::fake();
    app()->instance(PaymentGateway::class, new PaystackGateway(['secret_key' => 'sk_one_account']));
});

/**
 * @return array{0: Tenant, 1: User, 2: string}
 */
function referenceTenant(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared', 'llm_topup_balance' => 0]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'owner@example.com']);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');

    return [$tenant, $user, 'acme.'.mb_ltrim((string) config('session.domain'), '.')];
}

/**
 * @param  array<string, mixed>  $metadata
 * @return array<string, mixed>
 */
function paystackCharge(array $metadata): array
{
    return [
        'id' => 4242424242,
        'reference' => 'ref_same_payment',
        'status' => 'success',
        'amount' => 9900,
        'currency' => 'GHS',
        'channel' => 'card',
        'paid_at' => '2026-09-15T10:00:00Z',
        'authorization' => ['last4' => '4081', 'brand' => 'visa'],
        'customer' => ['email' => 'owner@example.com'],
        'metadata' => $metadata + ['source' => 'miconvener'],
    ];
}

/**
 * @param  array<string, mixed>  $metadata
 */
function reportPaymentBothWays(User $user, string $host, array $metadata): void
{
    $charge = paystackCharge($metadata);
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => $charge])]);

    test()->actingAs($user)->get("http://{$host}/billing/callback?reference=ref_same_payment", ['HTTP_HOST' => $host])->assertRedirect();

    $body = json_encode(['event' => 'charge.success', 'data' => $charge]);
    test()->call('POST', '/webhooks/paystack', [], [], [], [
        'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_one_account'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();
}

test('a token pack reported by both the browser and the webhook is credited once', function (): void {
    [$tenant, $user, $host] = referenceTenant();

    reportPaymentBothWays($user, $host, ['type' => 'llm_token_purchase', 'pack_key' => 'starter', 'tenant_id' => $tenant->id]);

    expect((int) $tenant->fresh()->llm_topup_balance)->toBe(500000)
        ->and(Transaction::query()->where('tenant_id', $tenant->id)->pluck('provider_transaction_id')->all())->toBe(['ref_same_payment']);
});

test('a subscription reported by both the browser and the webhook is recorded once', function (): void {
    [$tenant, $user, $host] = referenceTenant();

    reportPaymentBothWays($user, $host, ['type' => 'plan_subscription', 'plan_slug' => 'growth', 'interval' => 'month', 'tenant_id' => $tenant->id]);

    expect(Transaction::query()->where('tenant_id', $tenant->id)->pluck('provider_transaction_id')->all())->toBe(['ref_same_payment'])
        ->and(Subscription::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and($tenant->fresh()->package?->slug)->toBe('growth');
});

test('provisioning the same payment twice changes nothing the second time', function (): void {
    [$tenant] = referenceTenant();
    $growth = Package::query()->where('slug', 'growth')->firstOrFail();
    $dto = [
        'package_id' => $growth->id,
        'provider' => 'paystack',
        'provider_subscription_id' => 'ps_ref_x',
        'provider_plan_id' => 'growth_month',
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
        'amount_paid' => 9900,
        'currency' => 'ghs',
        'transaction_id' => 'ref_x',
    ];

    $service = app(SubscriptionProvisioningService::class);

    expect($service->provision($tenant, $dto))->toBeTrue()
        ->and($service->provision($tenant, $dto))->toBeFalse()
        ->and(Transaction::query()->where('provider_transaction_id', 'ref_x')->count())->toBe(1);
});

test('the database refuses a second transaction with the same provider reference', function (): void {
    [$tenant] = referenceTenant();
    $row = ['tenant_id' => $tenant->id, 'provider' => 'paystack', 'provider_transaction_id' => 'ref_dupe', 'amount' => 100, 'currency' => 'ghs', 'status' => 'success', 'type' => 'charge'];

    Transaction::query()->create($row);

    expect(fn () => Transaction::query()->create($row))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
