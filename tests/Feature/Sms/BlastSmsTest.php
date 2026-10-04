<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Jobs\Events\SendEventBlastJob;
use App\Models\Event;
use App\Models\EventBlast;
use App\Models\EventNotificationLog;
use App\Models\EventRegistration;
use App\Models\TenantAddon;
use App\Models\TenantNotificationSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * An announcement can also go out by SMS, paid for with SMS credits, to the
 * attendees in its audience who gave a phone number.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config()->set('services.omnichannel', ['url' => 'https://messaging.test', 'token' => 'secret-token', 'sender_id' => 'MiConvener']);
    app()->forgetInstance(\App\Contracts\SmsGateway::class);
});

/**
 * @return array{0: \App\Models\Tenant, 1: \App\Models\User, 2: Event, 3: string}
 */
function blastSmsScenario(int $credits, bool $smsOn = true): array
{
    [$tenant, $user] = eventHost('acme');
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    TenantNotificationSetting::forTenant($tenant->id)->update(['sms_enabled' => $smsOn]);

    if ($credits > 0) {
        TenantAddon::factory()->create([
            'tenant_id' => $tenant->id,
            'addon_type' => TenantAddon::TYPE_SMS_PACK,
            'quantity' => $credits,
            'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
            'status' => TenantAddon::STATUS_ACTIVE,
        ]);
    }

    foreach (['0241111111', '0242222222', null] as $phone) {
        EventRegistration::factory()->create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'phone' => $phone,
            'status' => EventRegistration::STATUS_CONFIRMED,
        ]);
    }

    return [$tenant, $user, $event, eventSubdomainHost('acme')];
}

test('an announcement with SMS is refused while SMS is switched off', function (): void {
    Bus::fake();
    [, $user, $event, $host] = blastSmsScenario(10, smsOn: false);

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/blasts", [
        'subject' => 'Room change', 'body' => 'Hall B.', 'audience' => 'all', 'send_sms' => true,
    ], ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors('send_sms');

    Bus::assertNotDispatched(SendEventBlastJob::class);
});

test('an announcement with SMS is refused when credits do not cover the phones in the audience', function (): void {
    Bus::fake();
    [, $user, $event, $host] = blastSmsScenario(1);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/blasts", [
        'subject' => 'Room change', 'body' => 'Hall B.', 'audience' => 'all', 'send_sms' => true,
    ], ['HTTP_HOST' => $host]);

    $response->assertUnprocessable();
    expect($response->json('message'))->toContain('needs 2 SMS credit(s)')->toContain('1 left');
});

test('the blast texts each attendee with a phone once, and reports it', function (): void {
    Mail::fake();
    Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'camp-b']], 202)]);
    [$tenant, $user, $event, $host] = blastSmsScenario(10);

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/blasts", [
        'subject' => 'Room change', 'body' => '<p>Hall B.</p>', 'audience' => 'all', 'send_sms' => true,
    ], ['HTTP_HOST' => $host])->assertOk();

    $blast = EventBlast::query()->sole();
    expect($blast->send_sms)->toBeTrue();
    (new SendEventBlastJob($blast))->handle();

    expect(EventNotificationLog::query()->where('channel', 'sms')->where('status', 'sent')->count())->toBe(2);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->data()[0]['message'] === 'Room change: Hall B.');

    $index = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/blasts", ['HTTP_HOST' => $host]);
    $index->assertJsonPath('blasts.0.sms_sent_count', 2)
        ->assertJsonPath('sms.enabled', true)
        ->assertJsonPath('sms.remaining', 8);
});

test('a blast without SMS sends no texts', function (): void {
    Mail::fake();
    Http::fake();
    [$tenant, , $event] = blastSmsScenario(10);
    $blast = $event->blasts()->create([
        'tenant_id' => $tenant->id, 'subject' => 'Hi', 'body' => 'Hello', 'audience' => 'all',
        'recipients_count' => 3, 'status' => EventBlast::STATUS_SCHEDULED,
    ]);

    (new SendEventBlastJob($blast))->handle();

    Http::assertNothingSent();
    expect(EventNotificationLog::query()->count())->toBe(0);
});
