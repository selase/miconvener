<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;

/**
 * Paystack allows a single webhook URL per business account, but the platform
 * receives two unrelated streams on its own accounts: event ticket settlement
 * and SaaS subscription billing. Both platform URLs therefore reach one handler
 * that routes by payload, so the same URL serves either stream regardless of
 * whether they share one Paystack account or use two.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    config(['services.settlement.paystack.secret_key' => 'sk_settlement_key']);
    config(['services.paystack.secret_key' => 'sk_billing_key']);
    config(['services.paystack.metadata_source' => 'miconvener']);
});

function signedPost(string $uri, array $payload, string $secret)
{
    $body = json_encode($payload);

    return test()->call('POST', $uri, [], [], [], [
        'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

test('a subscription event is handled rather than silently discarded', function (): void {
    // This is the regression that mattered: the settlement endpoint answered 200
    // and did nothing for any non-settlement event, so pointing the account's one
    // webhook URL at it dropped every subscription event on the floor.
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);

    $paid = Package::query()->where('slug', 'growth')->firstOrFail();
    $free = Package::query()->where('slug', 'free')->firstOrFail();

    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'package_id' => $paid->id]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'owner@example.com']);
    $tenant->users()->attach($user->id);

    $payload = [
        'event' => 'subscription.disable',
        'data' => [
            'customer' => ['email' => 'owner@example.com'],
            'metadata' => ['source' => 'miconvener'],
        ],
    ];

    signedPost('/webhooks/settlement/paystack', $payload, 'sk_billing_key')->assertOk();

    expect((string) $tenant->fresh()->package_id)->toBe((string) $free->id);
});

test('both platform webhook urls reach the same handler', function (): void {
    $payload = ['event' => 'subscription.not_renew', 'data' => ['customer' => ['email' => 'nobody@example.com'], 'metadata' => ['source' => 'miconvener']]];

    signedPost('/webhooks/settlement/paystack', $payload, 'sk_billing_key')->assertOk();
    signedPost('/webhooks/paystack', $payload, 'sk_billing_key')->assertOk();
});

test('a settlement event signed with the billing key is rejected', function (): void {
    // The keys identify which account an event came from. A ticket charge that
    // did not come from the settlement account must not be trusted to move money.
    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_wrong_key',
            'amount' => 1000,
            'currency' => 'GHS',
            'metadata' => ['type' => 'event_ticket', 'source' => 'miconvener', 'tenant_id' => 'whatever', 'event_registration_id' => 'whatever'],
        ],
    ];

    signedPost('/webhooks/settlement/paystack', $payload, 'sk_billing_key')->assertStatus(400);
});

test('an unsigned or wrongly signed payload is rejected', function (): void {
    $payload = ['event' => 'subscription.disable', 'data' => ['metadata' => ['source' => 'miconvener']]];

    signedPost('/webhooks/settlement/paystack', $payload, 'sk_not_a_real_key')->assertStatus(400);

    $this->postJson('/webhooks/settlement/paystack', $payload)->assertStatus(400);
});

test('an event from another application on the shared account is ignored', function (): void {
    // The platform's Paystack account is shared with other applications, so this
    // one webhook URL receives their events. The billing handler resolves a tenant
    // by customer email, so without an ownership check another application's
    // payment from someone who also has an account here would provision them a
    // subscription they never bought.
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);

    $paid = Package::query()->where('slug', 'growth')->firstOrFail();
    $free = Package::query()->where('slug', 'free')->firstOrFail();

    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'package_id' => $paid->id]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'shared@example.com']);
    $tenant->users()->attach($user->id);

    $payload = [
        'event' => 'subscription.disable',
        'data' => [
            'customer' => ['email' => 'shared@example.com'],
            'metadata' => ['source' => 'some-other-app'],
        ],
    ];

    $response = signedPost('/webhooks/paystack', $payload, 'sk_billing_key');

    $response->assertOk();
    expect($response->json('status'))->toBe('ignored');

    // Untouched: the other application's event must not have downgraded them.
    expect((string) $tenant->fresh()->package_id)->toBe((string) $paid->id);
    expect((string) $tenant->fresh()->package_id)->not->toBe((string) $free->id);
});

test('an event carrying no source at all is ignored', function (): void {
    $payload = ['event' => 'subscription.disable', 'data' => ['customer' => ['email' => 'x@example.com']]];

    $response = signedPost('/webhooks/paystack', $payload, 'sk_billing_key');

    $response->assertOk();
    expect($response->json('status'))->toBe('ignored');
});

test('a transfer is accepted without a source marker', function (): void {
    // Transfers carry no metadata of ours, but the reference is one this platform
    // generated and an unmatched reference is already a no-op.
    $payload = ['event' => 'transfer.failed', 'data' => ['reference' => 'payout_not_ours', 'reason' => 'x']];

    $response = signedPost('/webhooks/settlement/paystack', $payload, 'sk_settlement_key');

    $response->assertOk();
    expect($response->json('status'))->toBe('success');
});

test('a failed subscription invoice payment does not cancel or downgrade the subscription', function () {
    /*
     * The removed /api/webhooks/paystack chain had the only test of this event,
     * which also sent a placeholder dunning email. The live handler only logs
     * it; this keeps the guarantee that a failed charge is not treated as the
     * end of the subscription — Paystack retries, and subscription.disable is
     * what ends it.
     */
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);

    $paid = Package::query()->where('slug', 'growth')->firstOrFail();
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'package_id' => $paid->id]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'owner@example.com']);
    $tenant->users()->attach($user->id);

    signedPost('/webhooks/settlement/paystack', [
        'event' => 'invoice.payment_failed',
        'data' => [
            'customer' => ['email' => 'owner@example.com'],
            'subscription' => ['subscription_code' => 'SUB_failed_1', 'status' => 'active'],
            'metadata' => ['source' => 'miconvener'],
        ],
    ], 'sk_billing_key')->assertOk();

    expect((string) $tenant->fresh()->package_id)->toBe((string) $paid->id);
});

test('the removed /api/webhooks/paystack endpoint no longer exists', function () {
    // Paystack is configured to call /webhooks/settlement/paystack. The old
    // endpoint re-ran subscription renewal and reset AI token balances on any
    // successful charge, ticket sales included.
    $this->postJson('/api/webhooks/paystack', ['event' => 'charge.success', 'data' => []])->assertNotFound();
});

test('udworkspace charges are relayed byte-for-byte with the original signature', function (bool $stringMetadata): void {
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake(['https://udworkspace.com/webhooks/paystack' => \Illuminate\Support\Facades\Http::response(['status' => 'success'], 200, ['X-Webhook-Attempt' => '7d2abe71-6a53-44dc-b64a-29ea8511e357'])]);
    \Illuminate\Support\Facades\Log::spy();
    $metadata = ['application' => 'udworkspace', 'invoice_id' => 'existing-invoice', 'tenant_id' => 'existing-tenant', 'webhook_url' => 'https://evil.example/collect'];
    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'existing-payment', 'metadata' => $stringMetadata ? json_encode($metadata) : $metadata]], JSON_PRETTY_PRINT);
    $signature = hash_hmac('sha512', $body, 'sk_billing_key');
    $response = $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);
    $response->assertOk()->assertJsonPath('status', 'relayed');
    \Illuminate\Support\Facades\Http::assertSent(fn ($request): bool => $request->url() === 'https://udworkspace.com/webhooks/paystack' && $request->body() === $body && $request->hasHeader('x-paystack-signature', $signature));
    \Illuminate\Support\Facades\Http::assertSentCount(1);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(fn ($message, $context): bool => $message === 'UDWorkspace Paystack relay delivered'
        && $context['payload_sha256'] === hash('sha256', $body)
        && $context['attempt_id'] === $response->headers->get('X-Payment-Relay-Attempt')
        && $context['target_attempt_id'] === '7d2abe71-6a53-44dc-b64a-29ea8511e357'
        && array_diff(array_keys($context), ['attempt_id', 'payload_sha256', 'http_status', 'target_attempt_id']) === [])->once();
})->with([false, true]);

test('udworkspace forwarding failures return an error so Paystack retries', function (int $status): void {
    \Illuminate\Support\Facades\Http::fake(['https://udworkspace.com/webhooks/paystack' => \Illuminate\Support\Facades\Http::response([], $status)]);
    signedPost('/webhooks/settlement/paystack', ['event' => 'charge.success', 'data' => ['reference' => 'retry-payment', 'metadata' => ['application' => 'udworkspace']]], 'sk_billing_key')->assertStatus(502);
})->with([302, 400, 429, 500]);

test('udworkspace connection errors do not acknowledge delivery', function (): void {
    \Illuminate\Support\Facades\Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('secret payload must not be logged'));
    \Illuminate\Support\Facades\Log::spy();
    signedPost('/webhooks/settlement/paystack', ['event' => 'charge.success', 'data' => ['reference' => 'retry-payment', 'metadata' => ['application' => 'udworkspace']]], 'sk_billing_key')->assertStatus(502);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context): bool => $message === 'UDWorkspace Paystack relay failed' && ! str_contains(json_encode($context), 'secret payload'))->once();
});

test('an invalid signature never reaches the udworkspace relay', function (): void {
    \Illuminate\Support\Facades\Http::fake();
    signedPost('/webhooks/settlement/paystack', ['event' => 'charge.success', 'data' => ['metadata' => ['application' => 'udworkspace']]], 'wrong-key')->assertStatus(400);
    \Illuminate\Support\Facades\Http::assertNothingSent();
});

test('unrelated applications and unsupported udworkspace events are not forwarded', function (string $event, array $metadata): void {
    \Illuminate\Support\Facades\Http::fake();
    signedPost('/webhooks/settlement/paystack', ['event' => $event, 'data' => ['metadata' => $metadata]], 'sk_billing_key')->assertOk()->assertJsonPath('status', 'ignored');
    \Illuminate\Support\Facades\Http::assertNothingSent();
})->with([
    ['charge.success', ['application' => 'another-app']],
    ['refund.processed', ['application' => 'udworkspace']],
    ['charge.success', []],
]);

test('a disabled udworkspace relay asks Paystack to retry rather than dropping the charge', function (): void {
    config(['services.paystack.udworkspace_relay_enabled' => false]);
    \Illuminate\Support\Facades\Http::fake();
    signedPost('/webhooks/settlement/paystack', ['event' => 'charge.success', 'data' => ['metadata' => ['application' => 'udworkspace']]], 'sk_billing_key')->assertStatus(503);
    \Illuminate\Support\Facades\Http::assertNothingSent();
});

test('platform webhook bodies and signatures are excluded from request tracing', function (): void {
    expect(config('telescope.ignore_paths'))->toContain('webhooks/settlement/paystack', 'webhooks/paystack');
});

test('relay requests stay out of Telescope even with client recording enabled', function (string $uri, int $status): void {
    $telescope = \Laravel\Telescope\Telescope::class;
    $previousFilters = $telescope::$filterUsing;
    $telescope::$filterUsing = [];
    $telescope::$entriesQueue = [];
    (new \Laravel\Telescope\Watchers\ClientRequestWatcher)->register(app());
    $telescope::startRecording(false);
    \Illuminate\Support\Facades\Http::fake(['https://example.com/trace' => \Illuminate\Support\Facades\Http::response([], 200), 'https://udworkspace.com/webhooks/paystack' => \Illuminate\Support\Facades\Http::response([], $status, ['X-Webhook-Attempt' => '7d2abe71-6a53-44dc-b64a-29ea8511e357'])]);
    try {
        \Illuminate\Support\Facades\Http::get('https://example.com/trace');
        expect($telescope::$entriesQueue)->toHaveCount(1);
        $telescope::$entriesQueue = [];
        \Illuminate\Support\Facades\Log::spy();
        signedPost($uri, ['event' => 'charge.success', 'data' => ['customer' => ['email' => 'private@example.com'], 'metadata' => ['application' => 'udworkspace']]], 'sk_billing_key')->assertStatus($status === 200 ? 200 : 502);
        expect($telescope::$entriesQueue)->toBeEmpty();
        \Illuminate\Support\Facades\Log::shouldHaveReceived($status === 200 ? 'info' : 'warning')->withArgs(fn ($message, $context): bool => $context['target_attempt_id'] === '7d2abe71-6a53-44dc-b64a-29ea8511e357')->once();
    } finally {
        $telescope::stopRecording();
        $telescope::$entriesQueue = [];
        $telescope::$filterUsing = $previousFilters;
    }
})->with([
    ['/webhooks/settlement/paystack', 200],
    ['/webhooks/settlement/paystack', 500],
    ['/webhooks/paystack', 200],
    ['/webhooks/paystack', 500],
]);

test('Telescope rejects recording either platform payment webhook URI', function (): void {
    $approval = new ReflectionMethod(\Laravel\Telescope\Telescope::class, 'requestIsToApprovedUri');
    foreach (['/webhooks/settlement/paystack', '/webhooks/paystack'] as $uri) {
        expect($approval->invoke(null, \Illuminate\Http\Request::create($uri, 'POST')))->toBeFalse();
    }
});

test('the relay delivers when production excludes development-only Telescope', function (): void {
    $code = <<<'PHP'
    $loader = require 'vendor/autoload.php';
    $loader->unregister();
    spl_autoload_register(static function (string $class) use ($loader): void {
        if (!str_starts_with($class, 'Laravel\\Telescope\\')) {
            $loader->loadClass($class);
        }
    });
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['services.paystack.secret_key' => 'relay-test-key', 'services.paystack.udworkspace_relay_enabled' => true]);
    Illuminate\Support\Facades\Http::fake(['https://udworkspace.com/webhooks/paystack' => Illuminate\Support\Facades\Http::response([], 200)]);
    $body = json_encode(['event' => 'charge.success', 'data' => ['metadata' => ['application' => 'udworkspace']]]);
    $request = Illuminate\Http\Request::create('/webhooks/settlement/paystack', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    $request->headers->set('x-paystack-signature', hash_hmac('sha512', $body, 'relay-test-key'));
    $response = app(App\Http\Controllers\Public\SettlementWebhookController::class)->handle($request, app(App\Http\Controllers\Billing\WebhookController::class), app(App\Services\Billing\SubscriptionProvisioningService::class));
    if (class_exists(Laravel\Telescope\Telescope::class) || $response->getStatusCode() !== 200 || $response->getData(true)['status'] !== 'relayed') {
        throw new RuntimeException('Relay did not deliver without Telescope');
    }
    Illuminate\Support\Facades\Http::assertSentCount(1);
    PHP;
    $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false']);
    $process->setTimeout(30)->mustRun();
    expect($process->getExitCode())->toBe(0);
});
