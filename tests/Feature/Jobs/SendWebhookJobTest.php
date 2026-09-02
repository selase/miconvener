<?php

declare(strict_types=1);

use App\Jobs\SendWebhookJob;
use App\Models\WebhookCall;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Ensure the tenant connection has a valid driver.
    Config::set('database.connections.tenant', Config::get('database.connections.landlord'));

    // WebhookCall uses BelongsToTenant which sets the connection to 'tenant'.
    // We need the webhook tables on that connection.
    $schema = Illuminate\Support\Facades\Schema::connection('tenant');

    if (! $schema->hasTable('webhook_endpoints')) {
        $schema->create('webhook_endpoints', function ($table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->string('url');
            $table->text('secret');
            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    if (! $schema->hasTable('webhook_calls')) {
        $schema->create('webhook_calls', function ($table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable();
            $table->uuid('webhook_endpoint_id');
            $table->string('event_name');
            $table->json('payload')->nullable();
            $table->integer('status')->nullable();
            $table->text('response')->nullable();
            $table->text('exception')->nullable();
            $table->timestamps();
        });
    }
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
