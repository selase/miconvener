<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Services\Notifications\NotificationGatewayService;
use Illuminate\Support\Facades\Artisan;

/**
 * The gateway takes payment at the moment it decides to send, not at the moment
 * something is delivered. For SMS and WhatsApp nothing is delivered at all --
 * there is no gateway yet, only a staged log row -- so every one of those was a
 * real charge for a message that never left the system.
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

test('a staged sms is not billed', function () {
    [$tenant, $event] = gatewayScenario('bill-sms');

    app(NotificationGatewayService::class)->dispatch(
        $event,
        null,
        ['name' => 'Ama Mensah', 'email' => null, 'phone' => '233200000000'],
        ['sms'],
        ['subject' => 'Doors open', 'body' => 'See you at 9.'],
    );

    $log = EventNotificationLog::where('event_id', $event->id)->firstOrFail();

    expect($log->status)->toBe(EventNotificationLog::STATUS_STAGED);
    expect((int) $log->cost_billed)->toBe(0);
    // The rate survives for when a real gateway is wired in.
    expect((int) ($log->metadata['would_bill'] ?? -1))->toBe(500);
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

test('the anti-abuse cooldown still suppresses a staged repeat', function () {
    [$tenant, $event, $settings] = gatewayScenario('bill-cooldown');
    $settings->update(['anti_abuse_cooldown_minutes' => 60]);

    $recipient = ['name' => 'Ama Mensah', 'email' => null, 'phone' => '233200000002'];
    $payload = ['subject' => 'Doors open', 'body' => 'See you at 9.'];

    app(NotificationGatewayService::class)->dispatch($event, null, $recipient, ['sms'], $payload);
    $second = app(NotificationGatewayService::class)->dispatch($event, null, $recipient, ['sms'], $payload);

    // Nulling sent_at must not disable the cooldown: it now measures when we
    // last tried, which is what it always meant.
    expect($second['sms']['status'])->toBe('rate_limited');
    expect(EventNotificationLog::where('event_id', $event->id)->count())->toBe(1);
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

test('refunding a channel never drives the counter below zero', function () {
    [$tenant, $event, $settings] = gatewayScenario('bill-floor');

    $settings->refundSend('email');
    $settings->refundSend('email');

    expect((int) $settings->fresh()->email_used_this_month)->toBe(0);
});
