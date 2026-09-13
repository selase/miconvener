<?php

declare(strict_types=1);

use App\Jobs\SendWebhookJob;
use App\Models\WebhookCall;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // WebhookCall uses BelongsToTenant, which puts it on the `tenant` connection.
    // A shared-isolation tenant's database is the landlord database. This test
    // once built its own webhook tables for that connection — with a tenant_id
    // column the real table lacked — and so never saw the job fail on insert.
    useLandlordAsTenantConnection();
});

it('creates a webhook call record and sends HTTP request', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $tenant = setActiveTenantForTest();

    $endpoint = WebhookEndpoint::factory()->create([
        'tenant_id' => $tenant->id,
        'url' => 'https://example.com/webhook',
        'secret' => 'test-secret-key',
        'events' => ['invoice.issued'],
        'is_active' => true,
    ]);

    $payload = ['invoice_id' => 'INV-001', 'amount' => '100.00'];

    (new SendWebhookJob($endpoint, 'invoice.issued', $payload))->handle();

    expect(WebhookCall::count())->toBe(1);

    $call = WebhookCall::first();
    expect($call->webhook_endpoint_id)->toBe($endpoint->id)
        ->and($call->event_name)->toBe('invoice.issued')
        ->and($call->payload)->toBe($payload)
        ->and($call->status)->toBe(200);

    Http::assertSentCount(1);
});

it('includes correct HMAC signature in headers', function () {
    Http::fake([
        '*' => Http::response([], 200),
    ]);

    $tenant = setActiveTenantForTest();

    $endpoint = WebhookEndpoint::factory()->create([
        'tenant_id' => $tenant->id,
        'url' => 'https://example.com/webhook',
        'secret' => 'my-webhook-secret',
        'events' => ['invoice.issued'],
        'is_active' => true,
    ]);

    $payload = ['invoice_id' => 'INV-002'];
    $expectedSignature = hash_hmac('sha256', json_encode($payload), 'my-webhook-secret');

    (new SendWebhookJob($endpoint, 'invoice.issued', $payload))->handle();

    Http::assertSent(function ($request) use ($expectedSignature) {
        return $request->hasHeader('X-Tenant-Signature', $expectedSignature)
            && $request->hasHeader('X-Tenant-Event', 'invoice.issued')
            && $request->hasHeader('Content-Type', 'application/json');
    });
});

it('records failure when endpoint is unreachable', function () {
    Http::fake([
        '*' => Http::response('Connection refused', 500),
    ]);

    $tenant = setActiveTenantForTest();

    $endpoint = WebhookEndpoint::factory()->create([
        'tenant_id' => $tenant->id,
        'url' => 'https://unreachable.example.com/webhook',
        'secret' => 'test-secret',
        'events' => ['invoice.issued'],
        'is_active' => true,
    ]);

    $payload = ['invoice_id' => 'INV-003'];

    (new SendWebhookJob($endpoint, 'invoice.issued', $payload))->handle();

    $call = WebhookCall::first();
    expect($call)->not->toBeNull()
        ->and($call->status)->toBe(500)
        ->and($call->response)->toBe('Connection refused');
});
