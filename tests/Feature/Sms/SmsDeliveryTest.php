<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\TenantNotificationSetting;
use App\Services\Notifications\NotificationDeliveryClaimService;
use App\Services\Notifications\NotificationGatewayService;
use App\Services\Sms\SmsAllowance;
use Illuminate\Support\Facades\Http;

/**
 * An SMS notification is handed to the provider from the queued delivery job,
 * and a credit is spent only once the provider has accepted it.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    config()->set('services.omnichannel', [
        'url' => 'https://messaging.test',
        'token' => 'secret-token',
        'sender_id' => 'MiConvener',
    ]);
    app()->forgetInstance(\App\Contracts\SmsGateway::class);
});

/**
 * @return array{0: Tenant, 1: Event}
 */
function smsScenario(int $credits = 10): array
{
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    TenantNotificationSetting::forTenant($tenant->id)->update(['sms_enabled' => true]);

    if ($credits > 0) {
        TenantAddon::factory()->create([
            'tenant_id' => $tenant->id,
            'addon_type' => TenantAddon::TYPE_SMS_PACK,
            'quantity' => $credits,
            'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
            'status' => TenantAddon::STATUS_ACTIVE,
        ]);
    }

    return [$tenant, $event];
}

function claimSms(Event $event, string $key, string $body = 'Doors open at 9.'): EventNotificationLog
{
    return app(NotificationDeliveryClaimService::class)->claim(
        $event, null, 'reminder', $key, ['name' => 'Ama', 'phone' => '0241234567'], 'sms', ['subject' => 'Reminder', 'body' => $body],
    );
}

test('an accepted SMS is marked sent and spends one credit', function (): void {
    Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'camp-9']], 202)]);
    [$tenant, $event] = smsScenario(10);
    $delivery = claimSms($event, 'ok');

    $result = app(NotificationGatewayService::class)->deliver($delivery);

    expect($result['status'])->toBe(EventNotificationLog::STATUS_SENT)
        ->and($delivery->fresh()->sent_at)->not->toBeNull()
        ->and($delivery->fresh()->metadata['provider_reference'])->toBe('camp-9')
        ->and(app(SmsAllowance::class)->remaining($tenant))->toBe(9);
    Http::assertSent(fn ($request): bool => $request->data()[0]['users'][0]['phone_number'] === '233241234567');
});

test('a refused SMS fails and keeps the credit', function (): void {
    Http::fake(['messaging.test/*' => Http::response(['message' => 'Sender ID not found'], 404)]);
    [$tenant, $event] = smsScenario(10);
    $delivery = claimSms($event, 'refused');

    $result = app(NotificationGatewayService::class)->deliver($delivery);

    expect($result['status'])->toBe(EventNotificationLog::STATUS_FAILED)
        ->and($delivery->fresh()->metadata['error'])->toContain('Sender ID not found')
        ->and($delivery->fresh()->sent_at)->toBeNull()
        ->and(app(SmsAllowance::class)->remaining($tenant))->toBe(10);
});

test('with no credits left the SMS is suppressed without calling the provider', function (): void {
    Http::fake();
    [, $event] = smsScenario(0);
    $delivery = claimSms($event, 'empty');

    expect(app(NotificationGatewayService::class)->deliver($delivery)['status'])
        ->toBe(EventNotificationLog::STATUS_SUPPRESSED_QUOTA);
    Http::assertNothingSent();
});

test('SMS switched off by the organizer is suppressed', function (): void {
    Http::fake();
    [$tenant, $event] = smsScenario(10);
    TenantNotificationSetting::forTenant($tenant->id)->update(['sms_enabled' => false]);

    expect(app(NotificationGatewayService::class)->deliver(claimSms($event, 'off'))['status'])
        ->toBe(EventNotificationLog::STATUS_SUPPRESSED_QUOTA);
    Http::assertNothingSent();
});

test('without a configured provider the SMS fails honestly', function (): void {
    config()->set('services.omnichannel.token', null);
    app()->forgetInstance(\App\Contracts\SmsGateway::class);
    Http::fake();
    [$tenant, $event] = smsScenario(10);

    expect(app(NotificationGatewayService::class)->deliver(claimSms($event, 'unset'))['status'])
        ->toBe(EventNotificationLog::STATUS_FAILED)
        ->and(app(SmsAllowance::class)->remaining($tenant))->toBe(10);
    Http::assertNothingSent();
});

test('SMS text is plain, folded and cut to three segments', function (): void {
    expect(NotificationGatewayService::smsText("<p>Hi&nbsp;Ama,</p>\n\n<p>see   you</p>"))->toBe('Hi Ama, see you');

    $long = NotificationGatewayService::smsText(str_repeat('a', 600));
    expect(mb_strlen($long))->toBe(NotificationGatewayService::SMS_MAX_LENGTH)
        ->and($long)->toEndWith('…');
});
