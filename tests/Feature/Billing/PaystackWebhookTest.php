<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function paystackSignature(string $payload): string
{
    return hash_hmac('sha512', $payload, config('services.paystack.secret_key', 'test_secret'));
}

function paystackWebhookHeaders(string $payload): array
{
    return ['X-Paystack-Signature' => paystackSignature($payload)];
}

beforeEach(function () {
    Config::set('services.paystack.secret_key', 'test_secret');
    Mail::fake();
    // We want the jobs to run synchronously for the test
    Queue::fake([App\Jobs\Billing\ProcessPaystackWebhookJob::class]);
});

// ── Signature verification ────────────────────────────────────────────────────

it('rejects paystack webhooks with invalid signature', function () {
    $payload = json_encode(['event' => 'charge.success', 'data' => []]);

    $this->call('POST', route('api.webhooks.paystack'), [], [], [], [
        'HTTP_X-Paystack-Signature' => 'invalid_signature',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertStatus(400);

    Queue::assertNotPushed(App\Jobs\Billing\ProcessPaystackWebhookJob::class);
});

it('accepts paystack webhooks with valid signature and dispatches job', function () {
    $payload = json_encode(['event' => 'charge.success', 'data' => []]);

    $this->call('POST', route('api.webhooks.paystack'), [], [], [], [
        'HTTP_X-Paystack-Signature' => paystackSignature($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertSuccessful();

    Queue::assertPushed(App\Jobs\Billing\ProcessPaystackWebhookJob::class);
});

// ── charge.success ────────────────────────────────────────────────────────────

it('charge.success provisions subscription and extends ends_at', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $tenant->update(['meta' => ['paystack_customer_code' => 'CUS_success123']]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'name' => 'default',
        'provider_id' => 'SUB_pro',
        'provider_status' => 'past_due',
        'ends_at' => now()->subDay(),
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'REF_test_'.uniqid(),
            'customer' => [
                'customer_code' => 'CUS_success123',
            ],
            'subscription' => [
                'subscription_code' => 'SUB_pro',
            ],
        ],
    ];

    // Call the sub-job directly — ProcessPaystackWebhookJob dispatches sub-jobs
    // which Queue::fake() intercepts, so we test the actual handler instead.
    $job = new App\Jobs\Billing\ProcessSuccessfulPaystackCharge($payload);
    $job->handle();

    $subscription->refresh();
    expect($subscription->provider_status)->toBe('active');
    expect($subscription->ends_at->isFuture())->toBeTrue();

    Mail::assertSent(App\Mail\Billing\PaymentSuccessReceipt::class);
});

// ── invoice.payment_failed ────────────────────────────────────────────────────

it('invoice.payment_failed sends dunning email but does not cancel', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $tenant->update(['meta' => ['paystack_customer_code' => 'CUS_fail123']]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'name' => 'default',
        'provider_id' => 'SUB_fail',
        'provider_status' => 'active',
        'ends_at' => now()->addDays(5),
    ]);

    $payload = [
        'event' => 'invoice.payment_failed',
        'data' => [
            'customer' => [
                'customer_code' => 'CUS_fail123',
            ],
        ],
    ];

    $job = new App\Jobs\Billing\ProcessFailedPaystackCharge($payload);
    $job->handle();

    $subscription->refresh();
    expect($subscription->provider_status)->toBe('active'); // status remains active

    Mail::assertSent(App\Mail\Billing\PaymentFailedDunning::class);
});

// ── subscription.disable ──────────────────────────────────────────────────────

it('subscription.disable marks canceled and locks features', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $tenant->update(['meta' => ['paystack_customer_code' => 'CUS_dis123']]);

    $tenant->features()->create(['feature_key' => 'premium', 'enabled' => true]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'name' => 'default',
        'provider_id' => 'SUB_cancel',
        'provider_status' => 'active',
        'ends_at' => now()->addDays(5),
    ]);

    $payload = [
        'event' => 'subscription.disable',
        'data' => [
            'subscription_code' => 'SUB_cancel',
        ],
    ];

    $job = new App\Jobs\Billing\ProcessDisabledPaystackSubscription($payload);
    $job->handle();

    $subscription->refresh();
    expect($subscription->provider_status)->toBe('canceled');
    expect($subscription->ends_at->isPast())->toBeTrue();

    expect($tenant->features()->where('enabled', true)->count())->toBe(0);

    Mail::assertSent(App\Mail\Billing\SubscriptionCancelled::class);
});
