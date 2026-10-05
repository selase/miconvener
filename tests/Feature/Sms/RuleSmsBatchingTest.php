<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\EventNotificationRule;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\TenantNotificationSetting;
use App\Services\Notifications\AutomatedNotificationDispatcher;
use Illuminate\Support\Facades\Http;

/**
 * A personalised SMS reminder (each attendee's own name and ticket code)
 * reaches the provider as one request for the whole audience, not one per
 * person -- the provider allows ten requests a minute.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    config()->set('services.omnichannel', ['url' => 'https://messaging.test', 'token' => 'secret-token', 'sender_id' => 'MiConvener']);
    app()->forgetInstance(\App\Contracts\SmsGateway::class);
});

test('a personalised reminder rule sends one SMS request for its whole audience', function (): void {
    Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'reminder']], 202)]);
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    TenantNotificationSetting::forTenant($tenant->id)->update(['sms_enabled' => true]);
    TenantAddon::factory()->create([
        'tenant_id' => $tenant->id,
        'addon_type' => TenantAddon::TYPE_SMS_PACK,
        'quantity' => 10,
        'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
        'status' => TenantAddon::STATUS_ACTIVE,
    ]);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    foreach (['TKT-A1' => '0241111111', 'TKT-B2' => '0242222222', 'TKT-C3' => '0243333333'] as $code => $phone) {
        EventRegistration::factory()->create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'phone' => $phone,
            'ticket_code' => $code,
            'status' => EventRegistration::STATUS_CONFIRMED,
        ]);
    }

    $rule = EventNotificationRule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'name' => 'Day before',
        'target_role' => 'attendee',
        'target_audience' => 'all',
        'trigger_type' => 'scheduled_offset',
        'offset_direction' => 'before',
        'offset_amount' => 1,
        'offset_unit' => 'days',
        'channels' => ['sms'],
        'subject' => 'Tomorrow',
        'body_template' => 'See you tomorrow. Your ticket is {ticket_code}.',
        'is_active' => true,
    ]);

    $stats = app(AutomatedNotificationDispatcher::class)->dispatchBulkRule($rule, 'test-run');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => collect($request->data()[0]['users'])->pluck('placeholder.0.{{1}}')->sort()->values()->all() === [
        'See you tomorrow. Your ticket is TKT-A1.',
        'See you tomorrow. Your ticket is TKT-B2.',
        'See you tomorrow. Your ticket is TKT-C3.',
    ]);
    expect($stats['sent_count'])->toBe(3)
        ->and(EventNotificationLog::query()->where('channel', 'sms')->where('status', 'sent')->count())->toBe(3);
});
