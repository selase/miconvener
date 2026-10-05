<?php

declare(strict_types=1);

use App\Checks\MessageVolumeCheck;
use App\Checks\SmsDeliveryFailuresCheck;
use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\Tenant;

/**
 * Sending problems surface as health alerts, which email the platform team:
 * one organisation sending far more than usual in an hour (a runaway loop or
 * misuse), and SMS that the provider keeps refusing (a broken token or an
 * unapproved sender ID), which would otherwise only show as failed rows.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
});

function sendingLogs(Event $event, string $channel, string $status, int $count, ?string $error = null, int $minutesAgo = 5): void
{
    for ($i = 0; $i < $count; $i++) {
        EventNotificationLog::query()->create([
            'tenant_id' => $event->tenant_id,
            'event_id' => $event->id,
            'channel' => $channel,
            'status' => $status,
            'recipient_name' => 'Guest',
            'recipient_phone' => '0241234567',
            'recipient_email' => 'guest@example.com',
            'subject' => 'Note',
            'message' => 'Hello',
            'cost_billed' => 0,
            'metadata' => $error ? ['error' => $error] : [],
        ])->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
    }
}

test('normal sending raises no volume alert', function (): void {
    $event = Event::factory()->create(['tenant_id' => Tenant::factory()->create(['isolation_mode' => 'shared'])->id]);
    sendingLogs($event, 'sms', 'sent', 5);

    expect(MessageVolumeCheck::new()->run()->status->value)->toBe('ok');
});

test('an organisation sending far more SMS than the hourly ceiling raises an alert naming it', function (): void {
    config()->set('health.message_alerts.sms_per_hour', 10);
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'name' => 'Loopy Events']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    sendingLogs($event, 'sms', 'sent', 11);

    $result = MessageVolumeCheck::new()->run();

    expect($result->status->value)->toBe('failed')
        ->and($result->notificationMessage)->toContain('Loopy Events')
        ->and($result->notificationMessage)->toContain('11 SMS');
});

test('sending older than an hour does not count toward the volume alert', function (): void {
    config()->set('health.message_alerts.sms_per_hour', 10);
    $event = Event::factory()->create(['tenant_id' => Tenant::factory()->create(['isolation_mode' => 'shared'])->id]);
    sendingLogs($event, 'sms', 'sent', 20, minutesAgo: 90);

    expect(MessageVolumeCheck::new()->run()->status->value)->toBe('ok');
});

test('SMS mostly refused by the provider raises an alert with the latest reason', function (): void {
    $event = Event::factory()->create(['tenant_id' => Tenant::factory()->create(['isolation_mode' => 'shared'])->id]);
    sendingLogs($event, 'sms', 'sent', 2);
    sendingLogs($event, 'sms', 'failed', 6, 'SMS provider refused (404): Sender ID not found');

    $result = SmsDeliveryFailuresCheck::new()->run();

    expect($result->status->value)->toBe('failed')
        ->and($result->notificationMessage)->toContain('6 of 8')
        ->and($result->notificationMessage)->toContain('Sender ID not found');
});

test('a few failed SMS among many sent raise no alert', function (): void {
    $event = Event::factory()->create(['tenant_id' => Tenant::factory()->create(['isolation_mode' => 'shared'])->id]);
    sendingLogs($event, 'sms', 'sent', 40);
    sendingLogs($event, 'sms', 'failed', 3, 'Not a usable phone number.');

    expect(SmsDeliveryFailuresCheck::new()->run()->status->value)->toBe('ok');
});

test('both checks are registered with the health system', function (): void {
    $names = collect(Spatie\Health\Facades\Health::registeredChecks())->map(fn ($check) => $check::class);

    expect($names)->toContain(MessageVolumeCheck::class)
        ->and($names)->toContain(SmsDeliveryFailuresCheck::class);
});
