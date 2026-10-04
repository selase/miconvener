<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Services\Notifications\NotificationGatewayService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/**
 * Nothing is billed for a message that did not leave the system: a WhatsApp
 * message is only staged (there is no WhatsApp gateway yet), and an SMS spends
 * a credit only once the provider has accepted it.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function gatewayScenario(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    // Fields must match $fillable exactly -- Model::shouldBeStrict() is on, so
    // an invented column throws rather than being ignored. There is no
    // email_enabled column: email is always on and bounded by its monthly limit.
    $settings = TenantNotificationSetting::create([
        'tenant_id' => $tenant->id,
        'sms_enabled' => true,
        'whatsapp_enabled' => true,
        'email_monthly_limit' => 100,
        'email_used_this_month' => 0,
        'sms_cost_rate' => 500,
        'whatsapp_cost_rate' => 300,
        'email_overage_rate' => 0,
        'overage_billing_enabled' => false,
        'anti_abuse_cooldown_minutes' => 0,
        'month_reset_at' => now()->startOfMonth(),
    ]);

    return [$tenant, $event, $settings];
}

test('an sms the provider does not accept is not billed', function () {
    [$tenant, $event] = gatewayScenario('bill-sms');
    \App\Models\TenantAddon::factory()->create([
        'tenant_id' => $tenant->id,
        'addon_type' => \App\Models\TenantAddon::TYPE_SMS_PACK,
        'quantity' => 5,
        'billing_interval' => \App\Models\TenantAddon::INTERVAL_ONE_OFF,
        'status' => \App\Models\TenantAddon::STATUS_ACTIVE,
    ]);

    app(NotificationGatewayService::class)->dispatch(
        $event,
        null,
        ['name' => 'Ama Mensah', 'email' => null, 'phone' => '233200000000'],
        ['sms'],
        ['subject' => 'Doors open', 'body' => 'See you at 9.'],
    );

    $log = EventNotificationLog::where('event_id', $event->id)->firstOrFail();

    // No provider is configured under test, so nothing was accepted.
    expect($log->status)->toBe(EventNotificationLog::STATUS_FAILED);
    expect((int) $log->cost_billed)->toBe(0);
    expect($log->sent_at)->toBeNull();
    expect(app(\App\Services\Sms\SmsAllowance::class)->remaining($tenant))->toBe(5);
});

test('a staged message does not claim it was sent', function () {
    [$tenant, $event] = gatewayScenario('bill-sentat');

    app(NotificationGatewayService::class)->dispatch(
        $event,
        null,
        ['name' => 'Kwame Asare', 'email' => null, 'phone' => '233200000001'],
        ['whatsapp'],
        ['subject' => 'Reminder', 'body' => 'Tomorrow.'],
    );

    $log = EventNotificationLog::where('event_id', $event->id)->firstOrFail();

    expect($log->status)->toBe(EventNotificationLog::STATUS_STAGED);
    expect($log->sent_at)->toBeNull();
});

test('separate staged messages to one recipient are not treated as duplicates', function () {
    [$tenant, $event, $settings] = gatewayScenario('bill-cooldown');
    $settings->update(['anti_abuse_cooldown_minutes' => 60]);

    $recipient = ['name' => 'Ama Mensah', 'email' => null, 'phone' => '233200000002'];
    $payload = ['subject' => 'Doors open', 'body' => 'See you at 9.'];

    app(NotificationGatewayService::class)->dispatch($event, null, $recipient, ['whatsapp'], $payload);
    $second = app(NotificationGatewayService::class)->dispatch($event, null, $recipient, ['whatsapp'], $payload);

    expect($second['whatsapp']['status'])->toBe(EventNotificationLog::STATUS_STAGED);
    expect(EventNotificationLog::where('event_id', $event->id)->count())->toBe(2);
});

test('a failed email does not consume the monthly quota', function () {
    [$tenant, $event, $settings] = gatewayScenario('bill-refund');

    // Force the send to throw by pointing the mailer at a transport that cannot
    // exist, which is what a misconfigured SMTP looks like from here.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

    app(NotificationGatewayService::class)->dispatch(
        $event,
        null,
        ['name' => 'Ama Mensah', 'email' => 'ama@example.com', 'phone' => null],
        ['email'],
        ['subject' => 'Doors open', 'body' => 'See you at 9.'],
    );

    $log = EventNotificationLog::where('event_id', $event->id)->firstOrFail();
    expect($log->status)->toBe(EventNotificationLog::STATUS_FAILED);

    // The allowance is the tenant's to spend on delivered mail. A bounce must
    // not burn it.
    expect((int) $settings->fresh()->email_used_this_month)->toBe(0);
});

test('a queued mailable still refunds quota when delivery fails', function () {
    [$tenant, $event, $settings] = gatewayScenario('bill-queued');

    // Production runs a queue; the test env pins sync. Faking the queue
    // reproduces the real shape: if the mailable were merely enqueued, the
    // send would "succeed", the row would claim sent_at, and the quota would
    // never come back.
    Queue::fake();
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

    app(NotificationGatewayService::class)->dispatch(
        $event,
        null,
        ['name' => 'Ama Mensah', 'email' => 'ama@example.com', 'phone' => null],
        ['email'],
        ['subject' => 'Doors open', 'body' => 'See you at 9.'],
    );

    $log = EventNotificationLog::where('event_id', $event->id)->firstOrFail();

    expect($log->status)->toBe(EventNotificationLog::STATUS_FAILED);
    expect($log->sent_at)->toBeNull();
    expect((int) $settings->fresh()->email_used_this_month)->toBe(0);
});

test('refunding a channel never drives the counter below zero', function () {
    [$tenant, $event, $settings] = gatewayScenario('bill-floor');

    $settings->refundSend('email');
    $settings->refundSend('email');

    expect((int) $settings->fresh()->email_used_this_month)->toBe(0);
});
