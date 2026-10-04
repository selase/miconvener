<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Jobs\Notifications\SendEventNotificationDeliveryJob;
use App\Jobs\Notifications\SendSmsBatchJob;
use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\TenantNotificationSetting;
use App\Services\Notifications\NotificationDeliveryClaimService;
use App\Services\Notifications\NotificationGatewayService;
use App\Services\Sms\OmnichannelSmsGateway;
use App\Services\Sms\SmsAllowance;
use App\Services\Sms\SmsMessage;
use Illuminate\Support\Facades\Http;

/**
 * Omnichannel accepts ten send requests a minute per account. Identical
 * texts therefore travel together, and a "too many requests" answer holds
 * the message for a retry instead of failing it.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    config()->set('services.omnichannel', ['url' => 'https://messaging.test', 'token' => 'secret-token', 'sender_id' => 'MiConvener']);
    app()->forgetInstance(\App\Contracts\SmsGateway::class);
});

/**
 * @return array{0: Tenant, 1: Event}
 */
function throttleScenario(int $credits = 10): array
{
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    TenantNotificationSetting::forTenant($tenant->id)->update(['sms_enabled' => true]);
    TenantAddon::factory()->create([
        'tenant_id' => $tenant->id,
        'addon_type' => TenantAddon::TYPE_SMS_PACK,
        'quantity' => $credits,
        'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
        'status' => TenantAddon::STATUS_ACTIVE,
    ]);

    return [$tenant, $event];
}

function claimThrottledSms(Event $event, string $key, string $phone = '0241234567', string $body = 'Doors open at 9.'): EventNotificationLog
{
    return app(NotificationDeliveryClaimService::class)->claim(
        $event, null, 'announcement', $key, ['name' => 'Ama', 'phone' => $phone], 'sms', ['subject' => 'Note', 'body' => $body],
    );
}

test('a rate-limited request is reported as throttled with the wait the provider asked for', function (): void {
    Http::fake(['messaging.test/*' => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '42'])]);

    $result = app(OmnichannelSmsGateway::class)->send([new SmsMessage('0241234567', 'Hi', 'r')])['r'];

    expect($result->accepted)->toBeFalse()
        ->and($result->retryAfter)->toBe(42);
});

test('a throttled SMS stays pending, spends nothing, and its job waits and retries', function (): void {
    Http::fake(['messaging.test/*' => Http::response([], 429, ['Retry-After' => '30'])]);
    [$tenant, $event] = throttleScenario();
    $delivery = claimThrottledSms($event, 'throttled');

    $job = (new SendEventNotificationDeliveryJob($delivery->id, $tenant->id))->withFakeQueueInteractions();
    $job->handle(app(NotificationGatewayService::class));

    $job->assertReleased(30);
    expect($delivery->fresh()->status)->toBe(EventNotificationLog::STATUS_PENDING)
        ->and(app(SmsAllowance::class)->remaining($tenant))->toBe(10);
});

test('a throttled SMS goes out on the retry', function (): void {
    Http::fake(['messaging.test/*' => Http::sequence()
        ->push([], 429, ['Retry-After' => '30'])
        ->push(['data' => ['campaign_id' => 'later']], 202)]);
    [$tenant, $event] = throttleScenario();
    $delivery = claimThrottledSms($event, 'retry');
    $gateway = app(NotificationGatewayService::class);

    $gateway->deliver($delivery);
    $gateway->deliver($delivery->fresh());

    expect($delivery->fresh()->status)->toBe(EventNotificationLog::STATUS_SENT)
        ->and(app(SmsAllowance::class)->remaining($tenant))->toBe(9);
});

test('a batch of identical texts is one provider request', function (): void {
    Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'batch']], 202)]);
    [$tenant, $event] = throttleScenario();
    $ids = collect(['0241111111', '0242222222', '0243333333'])
        ->map(fn (string $phone, int $i): string => claimThrottledSms($event, "b{$i}", $phone)->id)
        ->all();

    (new SendSmsBatchJob($tenant->id, $ids))->handle(app(NotificationGatewayService::class));

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => count($request->data()[0]['users']) === 3);
    expect(EventNotificationLog::query()->whereIn('id', $ids)->where('status', 'sent')->count())->toBe(3)
        ->and(app(SmsAllowance::class)->remaining($tenant))->toBe(7);
});

test('a batch never puts different texts in one request', function (): void {
    Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'x']], 202)]);
    [$tenant, $event] = throttleScenario();
    $ids = [
        claimThrottledSms($event, 'p1', '0241111111', 'Ticket TKT-1')->id,
        claimThrottledSms($event, 'p2', '0242222222', 'Ticket TKT-2')->id,
    ];

    (new SendSmsBatchJob($tenant->id, $ids))->handle(app(NotificationGatewayService::class));

    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request): bool => count($request->data()[0]['users']) > 1);
});

test('a batch larger than the credits left sends what is covered and suppresses the rest', function (): void {
    Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'part']], 202)]);
    [$tenant, $event] = throttleScenario(credits: 2);
    $ids = collect(['0241111111', '0242222222', '0243333333'])
        ->map(fn (string $phone, int $i): string => claimThrottledSms($event, "c{$i}", $phone)->id)
        ->all();

    (new SendSmsBatchJob($tenant->id, $ids))->handle(app(NotificationGatewayService::class));

    expect(EventNotificationLog::query()->whereIn('id', $ids)->where('status', 'sent')->count())->toBe(2)
        ->and(EventNotificationLog::query()->whereIn('id', $ids)->where('status', 'suppressed_quota')->count())->toBe(1)
        ->and(app(SmsAllowance::class)->remaining($tenant))->toBe(0);
});

test('a throttled batch is released and nothing in it is spent', function (): void {
    Http::fake(['messaging.test/*' => Http::response([], 429, ['Retry-After' => '45'])]);
    [$tenant, $event] = throttleScenario();
    $ids = [claimThrottledSms($event, 't1', '0241111111')->id, claimThrottledSms($event, 't2', '0242222222')->id];

    $job = (new SendSmsBatchJob($tenant->id, $ids))->withFakeQueueInteractions();
    $job->handle(app(NotificationGatewayService::class));

    $job->assertReleased(45);
    expect(EventNotificationLog::query()->whereIn('id', $ids)->where('status', 'pending')->count())->toBe(2)
        ->and(app(SmsAllowance::class)->remaining($tenant))->toBe(10);
});
