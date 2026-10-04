<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\TenantNotificationSetting;
use App\Services\Notifications\EventRuleTriggerService;
use App\Services\Sms\TicketSms;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * A confirmed attendee with a phone number gets their ticket by SMS as well
 * as by email -- once -- when the organizer has switched SMS on.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Mail::fake();
    config()->set('services.omnichannel', ['url' => 'https://messaging.test', 'token' => 'secret-token', 'sender_id' => 'MiConvener']);
    app()->forgetInstance(\App\Contracts\SmsGateway::class);
    Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'camp-t']], 202)]);
});

/**
 * @param  array<string, mixed>  $registration
 */
function ticketScenario(bool $smsOn = true, array $registration = []): EventRegistration
{
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'name' => 'Accra Health Summit']);
    TenantNotificationSetting::forTenant($tenant->id)->update(['sms_enabled' => $smsOn]);
    TenantAddon::factory()->create([
        'tenant_id' => $tenant->id,
        'addon_type' => TenantAddon::TYPE_SMS_PACK,
        'quantity' => 10,
        'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
        'status' => TenantAddon::STATUS_ACTIVE,
    ]);

    return EventRegistration::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'phone' => '0241234567',
        'ticket_code' => 'TKT-SMS-'.fake()->unique()->numerify('####'),
        'status' => EventRegistration::STATUS_CONFIRMED,
    ], $registration));
}

test('a confirmed ticket is texted once, however often it is confirmed', function (): void {
    $registration = ticketScenario();
    $triggers = app(EventRuleTriggerService::class);

    $triggers->registrationCompleted($registration);
    $triggers->registrationCompleted($registration);

    $log = EventNotificationLog::query()->where('channel', 'sms')->sole();
    expect($log->status)->toBe(EventNotificationLog::STATUS_SENT)
        ->and($log->notification_type)->toBe(TicketSms::NOTIFICATION_TYPE)
        ->and($log->message)->toContain('Accra Health Summit')
        ->and($log->message)->toContain((string) $registration->ticket_code)
        ->and($log->message)->toContain('/registrations/'.$registration->id);
    Http::assertSentCount(1);
});

test('no ticket SMS when the organizer has SMS switched off', function (): void {
    app(TicketSms::class)->send(ticketScenario(smsOn: false));

    expect(EventNotificationLog::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('no ticket SMS without a usable phone number', function (): void {
    app(TicketSms::class)->send(ticketScenario(registration: ['phone' => null]));
    app(TicketSms::class)->send(ticketScenario(registration: ['phone' => 'n/a']));

    expect(EventNotificationLog::query()->count())->toBe(0);
});

test('no ticket SMS for a registration that is not confirmed', function (): void {
    app(TicketSms::class)->send(ticketScenario(registration: ['status' => EventRegistration::STATUS_PENDING_PAYMENT, 'ticket_code' => null]));

    expect(EventNotificationLog::query()->count())->toBe(0);
});
