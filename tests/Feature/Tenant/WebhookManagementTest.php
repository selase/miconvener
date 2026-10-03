<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Jobs\SendWebhookJob;
use App\Models\User;
use App\Models\WebhookCall;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookDispatcherService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('tenant admin can view outgoing webhooks page with stats and configured endpoints', function (): void {
    [$tenant, $user] = eventHost('webhook-test-tenant');
    $host = eventSubdomainHost('webhook-test-tenant');

    $endpoint = WebhookEndpoint::create([
        'tenant_id' => $tenant->id,
        'name' => 'Internal CRM Sync',
        'description' => 'Forward ticket sales to CRM',
        'url' => 'https://crm.example.com/webhooks',
        'secret' => 'whsec_testsecret123456789012345678',
        'events' => [WebhookEndpoint::EVENT_REGISTRATION_CONFIRMED],
        'is_active' => true,
    ]);

    WebhookCall::create([
        'tenant_id' => $tenant->id,
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => WebhookEndpoint::EVENT_REGISTRATION_CONFIRMED,
        'payload' => ['ticket_code' => 'TKT-1001'],
        'status' => 200,
        'duration_ms' => 125,
        'response' => '{"ok":true}',
    ]);

    $response = $this->actingAs($user)->get("http://{$host}/settings/webhooks", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Tenant/Settings/Webhooks/Index')
        ->has('endpoints', 1)
        ->where('endpoints.0.name', 'Internal CRM Sync')
        ->where('endpoints.0.url', 'https://crm.example.com/webhooks')
        ->where('stats.total_endpoints', 1)
        ->where('stats.active_endpoints', 1)
        ->where('stats.total_deliveries', 1)
        ->where('stats.success_rate', 100)
        ->where('stats.avg_duration_ms', 125)
        ->has('availableEvents')
    );
});

test('unauthorized user cannot view webhooks page', function (): void {
    [$tenant, $user] = eventHost('webhook-test-unauth');
    $host = eventSubdomainHost('webhook-test-unauth');

    // Create regular user without 'manage organization settings'
    $plainUser = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($plainUser->id);

    $response = $this->actingAs($plainUser)->get("http://{$host}/settings/webhooks", ['HTTP_HOST' => $host]);

    $response->assertForbidden();
});

test('tenant admin can store a new webhook endpoint with generated secret', function (): void {
    [$tenant, $user] = eventHost('webhook-create-tenant');
    $host = eventSubdomainHost('webhook-create-tenant');

    $response = $this->actingAs($user)->post("http://{$host}/settings/webhooks", [
        'name' => 'Slack Alerts',
        'description' => 'Post to #events channel',
        'url' => 'https://hooks.slack.com/services/T00/B00/X00',
        'events' => [WebhookEndpoint::EVENT_TICKET_CHECKED_IN, WebhookEndpoint::EVENT_CONTRIBUTION_RECEIVED],
        'is_active' => true,
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    $response->assertSessionHas('new_secret');

    $endpoint = WebhookEndpoint::where('tenant_id', $tenant->id)->first();
    expect($endpoint)->not->toBeNull();
    expect($endpoint->name)->toBe('Slack Alerts');
    expect($endpoint->url)->toBe('https://hooks.slack.com/services/T00/B00/X00');
    expect($endpoint->events)->toBe([WebhookEndpoint::EVENT_TICKET_CHECKED_IN, WebhookEndpoint::EVENT_CONTRIBUTION_RECEIVED]);
    expect($endpoint->is_active)->toBeTrue();
    expect($endpoint->secret)->toStartWith('whsec_');
});

test('webhook store validates required inputs and url structure', function (): void {
    [$tenant, $user] = eventHost('webhook-val-tenant');
    $host = eventSubdomainHost('webhook-val-tenant');

    $response = $this->actingAs($user)->post("http://{$host}/settings/webhooks", [
        'name' => '',
        'url' => 'ftp://invalid-protocol.com',
        'events' => [],
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasErrors(['name', 'url', 'events']);
});

test('tenant admin can update an existing webhook endpoint', function (): void {
    [$tenant, $user] = eventHost('webhook-update-tenant');
    $host = eventSubdomainHost('webhook-update-tenant');

    $endpoint = WebhookEndpoint::create([
        'tenant_id' => $tenant->id,
        'name' => 'Initial Name',
        'url' => 'https://initial.example.com',
        'secret' => 'whsec_oldsecret',
        'events' => ['*'],
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->put("http://{$host}/settings/webhooks/{$endpoint->id}", [
        'name' => 'Updated Name',
        'description' => 'Updated description',
        'url' => 'https://updated.example.com',
        'events' => [WebhookEndpoint::EVENT_REGISTRATION_CREATED],
        'is_active' => false,
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $endpoint->refresh();
    expect($endpoint->name)->toBe('Updated Name');
    expect($endpoint->url)->toBe('https://updated.example.com');
    expect($endpoint->events)->toBe([WebhookEndpoint::EVENT_REGISTRATION_CREATED]);
    expect($endpoint->is_active)->toBeFalse();
});

test('tenant cannot update endpoint belonging to another tenant', function (): void {
    [$tenantA, $userA] = eventHost('webhook-iso-a');
    [$tenantB, $userB] = eventHost('webhook-iso-b');
    $hostA = eventSubdomainHost('webhook-iso-a');

    $endpointB = WebhookEndpoint::create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Endpoint',
        'url' => 'https://b.example.com',
        'secret' => 'whsec_b',
        'events' => ['*'],
        'is_active' => true,
    ]);

    $response = $this->actingAs($userA)->put("http://{$hostA}/settings/webhooks/{$endpointB->id}", [
        'name' => 'Hacked Name',
        'url' => 'https://hacked.com',
        'events' => ['*'],
        'is_active' => true,
    ], ['HTTP_HOST' => $hostA]);

    $response->assertNotFound();
    expect($endpointB->fresh()->name)->toBe('Tenant B Endpoint');
});

test('tenant admin can delete an endpoint and associated delivery logs', function (): void {
    [$tenant, $user] = eventHost('webhook-del-tenant');
    $host = eventSubdomainHost('webhook-del-tenant');

    $endpoint = WebhookEndpoint::create([
        'tenant_id' => $tenant->id,
        'name' => 'To Delete',
        'url' => 'https://delete.example.com',
        'secret' => 'whsec_del',
        'events' => ['*'],
        'is_active' => true,
    ]);

    WebhookCall::create([
        'tenant_id' => $tenant->id,
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'ping',
        'payload' => [],
        'status' => 200,
    ]);

    $response = $this->actingAs($user)->delete("http://{$host}/settings/webhooks/{$endpoint->id}", [], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    expect(WebhookEndpoint::find($endpoint->id))->toBeNull();
    expect(WebhookCall::where('webhook_endpoint_id', $endpoint->id)->count())->toBe(0);
});

test('tenant cannot delete endpoint belonging to another tenant', function (): void {
    [$tenantA, $userA] = eventHost('webhook-iso-del-a');
    [$tenantB, $userB] = eventHost('webhook-iso-del-b');
    $hostA = eventSubdomainHost('webhook-iso-del-a');

    $endpointB = WebhookEndpoint::create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Protected',
        'url' => 'https://protected.example.com',
        'secret' => 'whsec_b_del',
        'events' => ['*'],
        'is_active' => true,
    ]);

    $response = $this->actingAs($userA)->delete("http://{$hostA}/settings/webhooks/{$endpointB->id}", [], ['HTTP_HOST' => $hostA]);

    $response->assertNotFound();
    expect(WebhookEndpoint::withoutGlobalScopes()->find($endpointB->id))->not->toBeNull();
});

test('tenant admin can rotate the signing secret', function (): void {
    [$tenant, $user] = eventHost('webhook-rot-tenant');
    $host = eventSubdomainHost('webhook-rot-tenant');

    $endpoint = WebhookEndpoint::create([
        'tenant_id' => $tenant->id,
        'name' => 'Rotating Key',
        'url' => 'https://rotate.example.com',
        'secret' => 'whsec_initial123',
        'events' => ['*'],
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->post("http://{$host}/settings/webhooks/{$endpoint->id}/rotate-secret", [], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas('new_secret');
    $endpoint->refresh();
    expect($endpoint->secret)->not->toBe('whsec_initial123');
    expect($endpoint->secret)->toStartWith('whsec_');
});

test('tenant cannot rotate secret for endpoint belonging to another tenant', function (): void {
    [$tenantA, $userA] = eventHost('webhook-iso-rot-a');
    [$tenantB, $userB] = eventHost('webhook-iso-rot-b');
    $hostA = eventSubdomainHost('webhook-iso-rot-a');

    $endpointB = WebhookEndpoint::create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Secret',
        'url' => 'https://b-secret.example.com',
        'secret' => 'whsec_b_unrotated',
        'events' => ['*'],
        'is_active' => true,
    ]);

    $response = $this->actingAs($userA)->post("http://{$hostA}/settings/webhooks/{$endpointB->id}/rotate-secret", [], ['HTTP_HOST' => $hostA]);

    $response->assertNotFound();
    expect(WebhookEndpoint::withoutGlobalScopes()->find($endpointB->id)->secret)->toBe('whsec_b_unrotated');
});

test('test ping dispatches a live test call and records metrics', function (): void {
    Http::fake([
        'https://ping.example.com' => Http::response(['received' => true], 200),
    ]);

    [$tenant, $user] = eventHost('webhook-ping-tenant');
    $host = eventSubdomainHost('webhook-ping-tenant');

    $endpoint = WebhookEndpoint::create([
        'tenant_id' => $tenant->id,
        'name' => 'Ping Endpoint',
        'url' => 'https://ping.example.com',
        'secret' => 'whsec_pingtest',
        'events' => ['*'],
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->post("http://{$host}/settings/webhooks/{$endpoint->id}/test", [], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $call = WebhookCall::where('webhook_endpoint_id', $endpoint->id)->first();
    expect($call)->not->toBeNull();
    expect($call->event_name)->toBe(WebhookEndpoint::EVENT_PING);
    expect($call->status)->toBe(200);
    expect($call->duration_ms)->toBeGreaterThan(0);
    expect($call->isSuccessful())->toBeTrue();
});

test('tenant admin can retrieve paginated delivery calls as json', function (): void {
    [$tenant, $user] = eventHost('webhook-calls-tenant');
    $host = eventSubdomainHost('webhook-calls-tenant');

    $endpoint = WebhookEndpoint::create([
        'tenant_id' => $tenant->id,
        'name' => 'Logs Endpoint',
        'url' => 'https://logs.example.com',
        'secret' => 'whsec_logs',
        'events' => ['*'],
        'is_active' => true,
    ]);

    WebhookCall::create([
        'tenant_id' => $tenant->id,
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => WebhookEndpoint::EVENT_REGISTRATION_CONFIRMED,
        'payload' => ['ticket' => 'XYZ-1'],
        'status' => 200,
        'duration_ms' => 45,
        'response' => '{"ack":true}',
    ]);

    $response = $this->actingAs($user)->get("http://{$host}/settings/webhooks/{$endpoint->id}/calls", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $data = $response->json();
    expect($data['data'])->toHaveCount(1);
    expect($data['data'][0]['event_name'])->toBe(WebhookEndpoint::EVENT_REGISTRATION_CONFIRMED);
    expect($data['data'][0]['status'])->toBe(200);
    expect($data['data'][0]['duration_ms'])->toBe(45);
    expect($data['data'][0]['is_successful'])->toBeTrue();
});

test('tenant admin can retry a delivery call', function (): void {
    Queue::fake();

    [$tenant, $user] = eventHost('webhook-retry-tenant');
    $host = eventSubdomainHost('webhook-retry-tenant');

    $endpoint = WebhookEndpoint::create([
        'tenant_id' => $tenant->id,
        'name' => 'Retry Endpoint',
        'url' => 'https://retry.example.com',
        'secret' => 'whsec_retry',
        'events' => ['*'],
        'is_active' => true,
    ]);

    $call = WebhookCall::create([
        'tenant_id' => $tenant->id,
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => WebhookEndpoint::EVENT_OFFLINE_PAYMENT_SUBMITTED,
        'payload' => ['registration_id' => 'reg_123'],
        'status' => 500,
        'duration_ms' => 10,
        'exception' => 'Connection timed out',
    ]);

    $response = $this->actingAs($user)->post("http://{$host}/settings/webhooks/calls/{$call->id}/retry", [], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    Queue::assertPushed(SendWebhookJob::class, function ($job) use ($endpoint, $call) {
        return $job->endpoint->id === $endpoint->id
            && $job->existingCallId === $call->id
            && $job->event === WebhookEndpoint::EVENT_OFFLINE_PAYMENT_SUBMITTED;
    });
});

test('webhook dispatcher filters endpoints by subscription topic and active state', function (): void {
    Queue::fake();

    [$tenant, $user] = eventHost('webhook-filter-tenant');

    // Endpoint 1: Active, subscribed to EVENT_TICKET_CHECKED_IN
    $ep1 = WebhookEndpoint::create([
        'tenant_id' => $tenant->id,
        'name' => 'Checkin Sub',
        'url' => 'https://e1.example.com',
        'secret' => 'whsec_1',
        'events' => [WebhookEndpoint::EVENT_TICKET_CHECKED_IN],
        'is_active' => true,
    ]);

    // Endpoint 2: Active, subscribed to ALL (*)
    $ep2 = WebhookEndpoint::create([
        'tenant_id' => $tenant->id,
        'name' => 'All Sub',
        'url' => 'https://e2.example.com',
        'secret' => 'whsec_2',
        'events' => ['*'],
        'is_active' => true,
    ]);

    // Endpoint 3: Active, subscribed to different event
    $ep3 = WebhookEndpoint::create([
        'tenant_id' => $tenant->id,
        'name' => 'Other Sub',
        'url' => 'https://e3.example.com',
        'secret' => 'whsec_3',
        'events' => [WebhookEndpoint::EVENT_INVOICE_ISSUED],
        'is_active' => true,
    ]);

    // Endpoint 4: Inactive, subscribed to all
    $ep4 = WebhookEndpoint::create([
        'tenant_id' => $tenant->id,
        'name' => 'Inactive Sub',
        'url' => 'https://e4.example.com',
        'secret' => 'whsec_4',
        'events' => ['*'],
        'is_active' => false,
    ]);

    $dispatcher = app(WebhookDispatcherService::class);
    $count = $dispatcher->dispatch($tenant, WebhookEndpoint::EVENT_TICKET_CHECKED_IN, ['checked_in' => true]);

    expect($count)->toBe(2);

    Queue::assertPushed(SendWebhookJob::class, 2);
    Queue::assertPushed(SendWebhookJob::class, fn ($job) => $job->endpoint->id === $ep1->id);
    Queue::assertPushed(SendWebhookJob::class, fn ($job) => $job->endpoint->id === $ep2->id);
    Queue::assertNotPushed(SendWebhookJob::class, fn ($job) => $job->endpoint->id === $ep3->id);
    Queue::assertNotPushed(SendWebhookJob::class, fn ($job) => $job->endpoint->id === $ep4->id);
});
