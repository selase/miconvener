<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\AutomatedNotificationMail;
use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\TenantAddon;
use App\Models\TenantNotificationSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * An organizer can send themselves a test of a notification before using it:
 * by email to their own address, and by SMS to a number they type in (user
 * accounts carry no phone number).
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
    config()->set('services.omnichannel', ['url' => 'https://messaging.test', 'token' => 'secret-token', 'sender_id' => 'MiConvener']);
    app()->forgetInstance(\App\Contracts\SmsGateway::class);
});

/**
 * @return array{0: \App\Models\User, 1: Event, 2: string}
 */
function testSendScenario(): array
{
    [$tenant, $user] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Accra Health Summit']);
    TenantNotificationSetting::forTenant($tenant->id)->update(['sms_enabled' => true]);
    TenantAddon::factory()->create([
        'tenant_id' => $tenant->id,
        'addon_type' => TenantAddon::TYPE_SMS_PACK,
        'quantity' => 5,
        'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
        'status' => TenantAddon::STATUS_ACTIVE,
    ]);

    return [$user, $event, eventSubdomainHost('acme')];
}

function postTestSend($test, $user, Event $event, string $host, array $data)
{
    return $test->actingAs($user)->postJson("http://{$host}/events/{$event->id}/notification-rules/test-send", array_merge([
        'subject' => 'Doors open',
        'body_template' => 'Hello {name}, see you at {event_name}. Ticket {ticket_code}.',
    ], $data), ['HTTP_HOST' => $host]);
}

test('a test SMS goes to the number typed in', function (): void {
    Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'camp-test']], 202)]);
    [$user, $event, $host] = testSendScenario();

    $response = postTestSend($this, $user, $event, $host, ['channels' => ['sms'], 'phone' => '+233 20 833 3151']);

    $response->assertOk()->assertJsonPath('results.sms.status', EventNotificationLog::STATUS_SENT);
    Http::assertSent(fn ($request): bool => $request->data()[0]['users'][0]['phone_number'] === '233208333151'
        && str_contains($request->data()[0]['message'], 'Accra Health Summit')
        && ! str_contains($request->data()[0]['message'], '{ticket_code}'));
});

test('an SMS test needs a phone number', function (): void {
    Http::fake();
    [$user, $event, $host] = testSendScenario();

    postTestSend($this, $user, $event, $host, ['channels' => ['sms']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');
    Http::assertNothingSent();
});

test('an SMS test refuses a number that cannot be texted', function (): void {
    Http::fake();
    [$user, $event, $host] = testSendScenario();

    postTestSend($this, $user, $event, $host, ['channels' => ['sms'], 'phone' => 'not a number'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');
    Http::assertNothingSent();
});

test('an email test still goes to the organizer, and needs no phone', function (): void {
    [$user, $event, $host] = testSendScenario();

    postTestSend($this, $user, $event, $host, ['channels' => ['email']])
        ->assertOk()
        ->assertJsonPath('results.email.status', EventNotificationLog::STATUS_SENT);

    Mail::assertSent(AutomatedNotificationMail::class, fn ($mail): bool => $mail->hasTo($user->email));
});
