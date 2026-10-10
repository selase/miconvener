<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sms\OmnichannelSmsGateway;
use App\Services\Sms\SmsDeliverySync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

/**
 * "Sent" only ever meant the provider accepted the text. mNotify has no
 * delivery webhook (their support, 2026-10-05), so MiConvener asks
 * Omnichannel what became of each text: soon after sending, then less often
 * for two days. The outcome is recorded on the log; credits are not returned,
 * because the provider charges for the attempt.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    config()->set('services.omnichannel', [
        'url' => 'https://messaging.test',
        'token' => 'secret-token',
        'sender_id' => 'MiConvener',
    ]);
    $this->tenant = Tenant::factory()->create(['name' => 'Accra Summit', 'isolation_mode' => 'shared']);
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function sentSms(Tenant $tenant, Event $event, string $phone, string $reference = 'camp-1', array $attributes = []): EventNotificationLog
{
    return EventNotificationLog::withoutGlobalScopes()->create($attributes + [
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'notification_type' => 'reminder',
        'dedupe_key' => uniqid('k', true),
        'channel' => EventNotificationLog::CHANNEL_SMS,
        'status' => EventNotificationLog::STATUS_SENT,
        'recipient_phone' => $phone,
        'message' => 'Doors open at 8.',
        'metadata' => ['provider_reference' => $reference],
        'sent_at' => now(),
    ]);
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function fakeReport(array $rows, int $status = 200): void
{
    Http::fake(['messaging.test/api/v1/report/sms/*' => Http::response(['success' => true, 'data' => ['report' => $rows]], $status)]);
}

test('a delivered text is recorded as delivered, and stays "sent" for billing', function (): void {
    $log = sentSms($this->tenant, $this->event, '+233241234567');
    $sentAt = $log->updated_at;
    fakeReport([['recipient' => '233241234567', 'status' => 'Delivered', 'gateway_status' => 'DELIVERED']]);

    $this->travel(5)->minutes();
    $this->artisan('sms:sync-delivery')->assertSuccessful();

    $log->refresh();

    expect($log->metadata['delivery'])->toBe('delivered')
        ->and($log->status)->toBe(EventNotificationLog::STATUS_SENT)
        // The failure alert reads updated_at as "when it was sent".
        ->and($log->updated_at->equalTo($sentAt))->toBeTrue();
    Http::assertSent(fn ($request): bool => $request->url() === 'https://messaging.test/api/v1/report/sms/camp-1'
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

test('a rejected text is recorded as not delivered, with the provider\'s reason', function (): void {
    $log = sentSms($this->tenant, $this->event, '0241234567');
    fakeReport([['recipient' => '233241234567', 'status' => 'Undelivered', 'gateway_status' => 'REJECTED']]);

    $this->travel(5)->minutes();
    $this->artisan('sms:sync-delivery')->assertSuccessful();

    expect($log->fresh()->metadata['delivery'])->toBe('undelivered')
        ->and($log->fresh()->metadata['delivery_detail'])->toBe('rejected');
});

test('one report settles every recipient sent under the same reference', function (): void {
    $ama = sentSms($this->tenant, $this->event, '+233241234567', 'batch-1');
    $kofi = sentSms($this->tenant, $this->event, '+233201234567', 'batch-1');
    fakeReport([
        ['recipient' => '233241234567', 'status' => 'Delivered', 'gateway_status' => 'DELIVERED'],
        ['recipient' => '233201234567', 'status' => 'Undelivered', 'gateway_status' => 'FAILED'],
    ]);

    $this->travel(5)->minutes();
    $this->artisan('sms:sync-delivery')->assertSuccessful();

    Http::assertSentCount(1);
    expect($ama->fresh()->metadata['delivery'])->toBe('delivered')
        ->and($kofi->fresh()->metadata['delivery'])->toBe('undelivered');
});

test('a text still on its way is asked about again later, on the schedule', function (): void {
    $log = sentSms($this->tenant, $this->event, '+233241234567');
    Http::fake(['messaging.test/api/v1/report/sms/*' => Http::sequence()
        ->push(['data' => ['report' => [['recipient' => '233241234567', 'status' => 'Pending', 'gateway_status' => null]]]])
        ->push(['data' => ['report' => [['recipient' => '233241234567', 'status' => 'Delivered', 'gateway_status' => 'DELIVERED']]]])]);

    // Too soon: nothing is asked in the first five minutes.
    $this->travel(1)->minutes();
    $this->artisan('sms:sync-delivery')->assertSuccessful();
    Http::assertNothingSent();

    $this->travel(4)->minutes();
    $this->artisan('sms:sync-delivery')->assertSuccessful();
    expect($log->fresh()->metadata)->not->toHaveKey('delivery');

    // A minute later nothing new is due, so the provider is not asked again.
    $this->travel(1)->minutes();
    $this->artisan('sms:sync-delivery')->assertSuccessful();
    Http::assertSentCount(1);

    // The next point on the schedule is thirty minutes after sending.
    $this->travel(25)->minutes();
    $this->artisan('sms:sync-delivery')->assertSuccessful();
    Http::assertSentCount(2);
    expect($log->fresh()->metadata['delivery'])->toBe('delivered');
});

test('an Omnichannel that cannot yet tell pending from failed is not believed about failures', function (): void {
    // Before its PR #208, Omnichannel reported a text it had no news of as
    // "Undelivered" and sent no gateway_status field.
    $log = sentSms($this->tenant, $this->event, '+233241234567');
    fakeReport([['recipient' => '233241234567', 'status' => 'Undelivered']]);

    $this->travel(5)->minutes();
    $this->artisan('sms:sync-delivery')->assertSuccessful();

    expect($log->fresh()->metadata)->not->toHaveKey('delivery');
});

test('when the provider is busy or failing nothing is recorded and it is tried again', function (int $status): void {
    $log = sentSms($this->tenant, $this->event, '+233241234567');
    fakeReport([], $status);

    $this->travel(5)->minutes();
    $this->artisan('sms:sync-delivery')->assertSuccessful();

    expect($log->fresh()->metadata)->toBe(['provider_reference' => 'camp-1']);

    $this->travel(1)->minutes();
    $this->artisan('sms:sync-delivery')->assertSuccessful();
    Http::assertSentCount(2);
})->with([429, 500]);

test('texts older than two days, refused texts and emails are left alone', function (): void {
    sentSms($this->tenant, $this->event, '+233241234567', 'old', ['sent_at' => now()->subDays(4)]);
    sentSms($this->tenant, $this->event, '+233241234568', 'refused', ['status' => EventNotificationLog::STATUS_FAILED]);
    sentSms($this->tenant, $this->event, '+233241234569', 'mail', ['channel' => EventNotificationLog::CHANNEL_EMAIL]);
    Http::fake();

    $this->travel(5)->minutes();
    $this->artisan('sms:sync-delivery')->assertSuccessful();

    Http::assertNothingSent();
});

test('one run asks about a bounded number of sends, oldest first', function (): void {
    foreach (range(1, SmsDeliverySync::REFERENCES_PER_RUN + 5) as $i) {
        sentSms($this->tenant, $this->event, '+2332412345'.mb_str_pad((string) $i, 2, '0', STR_PAD_LEFT), "camp-{$i}");
    }
    fakeReport([]);

    $this->travel(5)->minutes();
    $this->artisan('sms:sync-delivery')->assertSuccessful();

    Http::assertSentCount(SmsDeliverySync::REFERENCES_PER_RUN);
});

test('the gateway reads a report into delivered, undelivered and pending by number', function (): void {
    fakeReport([
        ['recipient' => '+233241234567', 'status' => 'Delivered', 'gateway_status' => 'DELIVERED'],
        ['recipient' => '0201234567', 'status' => 'Undelivered', 'gateway_status' => 'REJECTED'],
        ['recipient' => '233501234567', 'status' => 'Pending', 'gateway_status' => null],
    ]);

    expect(app(OmnichannelSmsGateway::class)->deliveryReport('camp-1'))->toBe([
        '233241234567' => ['status' => 'delivered', 'detail' => 'delivered'],
        '233201234567' => ['status' => 'undelivered', 'detail' => 'rejected'],
        '233501234567' => ['status' => 'pending', 'detail' => null],
    ]);
});

test('support sees the real outcome in Message delivery', function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $superadmin = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $superadmin->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());

    sentSms($this->tenant, $this->event, '+233241234567', 'a', ['metadata' => ['provider_reference' => 'a', 'delivery' => 'delivered']]);
    sentSms($this->tenant, $this->event, '+233241234567', 'b', ['metadata' => ['provider_reference' => 'b', 'delivery' => 'undelivered', 'delivery_detail' => 'rejected']]);
    sentSms($this->tenant, $this->event, '+233241234567', 'c');

    $this->actingAs($superadmin)
        ->get(route('admin.messages.index', ['q' => '024 123 4567']))
        ->assertOk()
        ->assertSee('delivered')
        ->assertSee('failed to deliver')
        ->assertSee('The provider reported: rejected')
        ->assertSee('accepted, delivery not yet known');
});
