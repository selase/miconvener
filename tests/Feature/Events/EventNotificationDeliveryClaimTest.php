<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Jobs\Middleware\TenantAwareJob;
use App\Jobs\Notifications\SendEventNotificationDeliveryJob;
use App\Mail\Events\AutomatedNotificationMail;
use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Services\Notifications\NotificationDeliveryClaimService;
use App\Services\Notifications\NotificationGatewayService;
use Illuminate\Mail\Mailer;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;

test('a tenant can claim one delivery for a channel and dedupe key', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $attributes = [
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'channel' => EventNotificationLog::CHANNEL_EMAIL,
        'status' => EventNotificationLog::STATUS_PENDING,
        'notification_type' => 'rule.on_registration',
        'dedupe_key' => 'registration:rule-1:registration-1:email',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect(DB::connection('landlord')->table('event_notification_logs')->insertOrIgnore($attributes))->toBe(1);

    $attributes['id'] = (string) Str::uuid7();

    expect(DB::connection('landlord')->table('event_notification_logs')->insertOrIgnore($attributes))->toBe(0)
        ->and(EventNotificationLog::withoutGlobalScopes()->count())->toBe(1);
});

test('legacy logs without a dedupe key remain valid and delivery fields are cast', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $first = EventNotificationLog::query()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'channel' => EventNotificationLog::CHANNEL_EMAIL,
        'status' => EventNotificationLog::STATUS_PENDING,
        'notification_type' => 'legacy-compatible',
        'dedupe_key' => null,
        'attempts' => 2,
        'last_attempted_at' => now(),
    ]);

    EventNotificationLog::query()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'channel' => EventNotificationLog::CHANNEL_EMAIL,
        'status' => EventNotificationLog::STATUS_SKIPPED,
        'notification_type' => 'legacy-compatible',
        'dedupe_key' => null,
    ]);

    expect(EventNotificationLog::withoutGlobalScopes()->count())->toBe(2)
        ->and($first->attempts)->toBeInt()->toBe(2)
        ->and($first->last_attempted_at)->toBeInstanceOf(\Carbon\CarbonInterface::class);
});

test('claiming is atomic while distinct notification keys remain independent', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $service = app(NotificationDeliveryClaimService::class);
    $recipient = ['name' => 'Jane Doe', 'email' => ' Jane@Example.COM '];
    $payload = ['subject' => 'Assigned', 'body' => 'You have a task.'];

    $first = $service->claim($event, null, 'task.assigned', 'task:1:jane@example.com', $recipient, 'email', $payload);
    $duplicate = $service->claim($event, null, 'task.assigned', 'task:1:jane@example.com', $recipient, 'email', $payload);
    $different = $service->claim($event, null, 'certificate.ready', 'certificate:1:jane@example.com', $recipient, 'email', $payload);

    expect($first)->toBeInstanceOf(EventNotificationLog::class)
        ->and($first->recipient_email)->toBe('jane@example.com')
        ->and($first->metadata)->toMatchArray(['action_url' => null, 'action_label' => null])
        ->and($duplicate)->toBeNull()
        ->and($different)->toBeInstanceOf(EventNotificationLog::class)
        ->and(EventNotificationLog::withoutGlobalScopes()->count())->toBe(2);
});

test('dispatch waits for the surrounding transaction to commit', function (): void {
    Queue::fake();
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $service = app(NotificationDeliveryClaimService::class);
    $delivery = $service->claim($event, null, 'task.assigned', 'task:2:user@example.com', ['email' => 'user@example.com'], 'email', [
        'subject' => 'Assigned',
        'body' => 'You have a task.',
    ]);

    DB::connection('landlord')->beginTransaction();
    $service->dispatch($delivery);
    DB::connection('landlord')->rollBack();

    Queue::assertNothingPushed();
});

test('the delivery job carries tenant context and prevents overlapping attempts', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $job = new SendEventNotificationDeliveryJob((string) Str::uuid7(), $tenant->id);

    expect($job->tenantId)->toBe($tenant->id)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 120, 300])
        ->and($job->middleware()[0])->toBeInstanceOf(TenantAwareJob::class)
        ->and($job->middleware()[1])->toBeInstanceOf(WithoutOverlapping::class);
});

test('successful claimed email delivery is terminal and metered once', function (): void {
    Mail::fake();
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $service = app(NotificationDeliveryClaimService::class);
    $delivery = $service->claim($event, null, 'task.assigned', 'task:3:user@example.com', ['email' => 'user@example.com'], 'email', [
        'subject' => 'Assigned',
        'body' => 'You have a task.',
    ]);
    $gateway = app(NotificationGatewayService::class);

    expect($gateway->deliver($delivery)['status'])->toBe(EventNotificationLog::STATUS_SENT)
        ->and($gateway->deliver($delivery)['status'])->toBe(EventNotificationLog::STATUS_SENT);

    $delivery->refresh();
    $usage = TenantNotificationSetting::forTenant($tenant->id)->email_used_this_month;

    expect($delivery->status)->toBe(EventNotificationLog::STATUS_SENT)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->sent_at)->not->toBeNull()
        ->and($usage)->toBe(1);
    Mail::assertSent(AutomatedNotificationMail::class, 1);
});

test('failed claimed email delivery records failure and refunds its credit', function (): void {
    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldReceive('to')->once()->andReturnSelf();
    $mailer->shouldReceive('sendNow')->once()->andThrow(new RuntimeException('SMTP unavailable'));
    Mail::swap($mailer);

    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $delivery = app(NotificationDeliveryClaimService::class)->claim(
        $event,
        null,
        'task.assigned',
        'task:4:user@example.com',
        ['email' => 'user@example.com'],
        'email',
        ['subject' => 'Assigned', 'body' => 'You have a task.'],
    );

    $result = app(NotificationGatewayService::class)->deliver($delivery);
    $delivery->refresh();

    expect($result['status'])->toBe(EventNotificationLog::STATUS_FAILED)
        ->and($delivery->status)->toBe(EventNotificationLog::STATUS_FAILED)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->cost_billed)->toBe(0)
        ->and($delivery->metadata)->toMatchArray(['error' => 'SMTP unavailable'])
        ->and(TenantNotificationSetting::forTenant($tenant->id)->email_used_this_month)->toBe(0);
});

test('claimed delivery truthfully records missing contact quota suppression and staged channels', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $claims = app(NotificationDeliveryClaimService::class);
    $gateway = app(NotificationGatewayService::class);
    $payload = ['subject' => 'Reminder', 'body' => 'Doors open soon.'];

    $missing = $claims->claim($event, null, 'reminder', 'missing-email', [], 'email', $payload);
    expect($gateway->deliver($missing)['status'])->toBe(EventNotificationLog::STATUS_SKIPPED)
        ->and($missing->fresh()->cost_billed)->toBe(0);

    TenantNotificationSetting::forTenant($tenant->id)->update([
        'email_monthly_limit' => 0,
        'overage_billing_enabled' => false,
    ]);
    $quota = $claims->claim($event, null, 'reminder', 'quota-email', ['email' => 'quota@example.com'], 'email', $payload);
    expect($gateway->deliver($quota)['status'])->toBe(EventNotificationLog::STATUS_SUPPRESSED_QUOTA)
        ->and($quota->fresh()->cost_billed)->toBe(0);

    TenantNotificationSetting::forTenant($tenant->id)->update(['whatsapp_enabled' => true, 'whatsapp_cost_rate' => 25]);
    $staged = $claims->claim($event, null, 'reminder', 'staged-whatsapp', ['phone' => '+233 20 000 0000'], 'whatsapp', $payload);
    expect($gateway->deliver($staged)['status'])->toBe(EventNotificationLog::STATUS_STAGED)
        ->and($staged->fresh()->cost_billed)->toBe(0)
        ->and($staged->fresh()->metadata)->toMatchArray(['would_bill' => 25]);
});
