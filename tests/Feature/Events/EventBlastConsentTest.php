<?php

declare(strict_types=1);

use App\Jobs\Events\SendEventBlastJob;
use App\Mail\Events\EventBlastMail;
use App\Models\Event;
use App\Models\EventBlast;
use App\Models\EventNotificationLog;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Services\Billing\EmailAllowance;
use App\Services\Community\MessagingPreferences;
use App\Services\Sms\OmnichannelSmsGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    foreach (['email_credits', 'sms_credits'] as $key) {
        $this->tenant->features()->create(['feature_key' => $key, 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 10]]);
    }
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->registration = EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'email' => 'ama@example.test', 'phone' => '0241234567', 'status' => EventRegistration::STATUS_CONFIRMED]);
    $this->blast = EventBlast::query()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'subject' => 'News', 'body' => 'See you there.', 'audience' => 'all', 'status' => 'scheduled', 'send_sms' => true, 'recipients_count' => 1]);
    TenantNotificationSetting::forTenant($this->tenant->id)->update(['sms_enabled' => true]);
    Mail::fake();
    Http::preventStrayRequests();
    Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'blast']], 202)]);
    app()->instance(App\Contracts\SmsGateway::class, new OmnichannelSmsGateway('https://messaging.test', 'fake', 'Test'));
});

test('event blasts deduplicate addresses and a retry does not requeue or meter email twice', function () {
    EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'email' => 'AMA@example.test', 'phone' => '+233241234567', 'status' => EventRegistration::STATUS_CONFIRMED]);
    (new SendEventBlastJob($this->blast))->handle();
    (new SendEventBlastJob($this->blast->fresh()))->handle();
    Mail::assertQueued(EventBlastMail::class, 1);
    expect(app(EmailAllowance::class)->remaining($this->tenant))->toBe(9);
    expect(EventNotificationLog::query()->where('channel', 'sms')->count())->toBe(1);
});

test('an exact tenant/channel opt-out suppresses announcements without enabling Community', function () {
    app(MessagingPreferences::class)->disableMarketing($this->tenant, 'email', 'ama@example.test');
    app(MessagingPreferences::class)->disableMarketing($this->tenant, 'sms', '+233241234567');
    (new SendEventBlastJob($this->blast))->handle();
    Mail::assertNothingQueued();
    Http::assertNothingSent();
    expect($this->tenant->communityEnabled())->toBeFalse()->and(app(EmailAllowance::class)->remaining($this->tenant))->toBe(10);
});

test('queued event email rechecks an opt-out before actual transport', function () {
    $this->blast->update(['send_sms' => false]);
    (new SendEventBlastJob($this->blast))->handle();
    $queued = Mail::queued(EventBlastMail::class)->first();
    app(MessagingPreferences::class)->disableMarketing($this->tenant, 'email', 'ama@example.test');
    $queued->send(app(Illuminate\Contracts\Mail\Factory::class));
    expect(app(EmailAllowance::class)->remaining($this->tenant))->toBe(10);
});

test('event announcements carry working unsubscribe links while tracking stays present', function () {
    (new SendEventBlastJob($this->blast))->handle();
    Mail::assertQueued(EventBlastMail::class, function ($mail) {
        $html = $mail->render();

        return str_contains($html, 'Stop announcements') && str_contains($html, '/community/preferences/') && str_contains($html, '/blasts/') && str_contains($html, '/open.gif');
    });
    Http::assertSent(fn ($request) => str_contains($request->data()[0]['message'], '/community/preferences/'));
});

test('event announcements cannot bypass the shared monthly SMS cap', function () {
    App\Models\CommunitySettings::for($this->tenant)->fill(['sms_monthly_limit' => 0])->save();
    (new SendEventBlastJob($this->blast))->handle();
    Http::assertNothingSent();
    Mail::assertQueued(EventBlastMail::class, 1);
});

test('marketing opt-out does not suppress transactional event service mail', function () {
    app(MessagingPreferences::class)->disableMarketing($this->tenant, 'email', $this->registration->email);
    Mail::to($this->registration->email)->send(new App\Mail\Events\AutomatedNotificationMail(
        event: $this->event, recipientName: 'Ama', emailSubject: 'Payment notice', renderedBody: 'Your payment is confirmed.'
    ));
    Mail::assertQueued(App\Mail\Events\AutomatedNotificationMail::class, 1);
});

test('a queued SMS rechecks the selected registration contact before transport', function () {
    Illuminate\Support\Facades\Queue::fake([App\Jobs\Notifications\SendSmsBatchJob::class]);
    (new SendEventBlastJob($this->blast))->handle();
    $log = EventNotificationLog::query()->where('channel', 'sms')->firstOrFail();
    $this->registration->update(['phone' => '0249999999']);
    (new App\Jobs\Notifications\SendSmsBatchJob($this->tenant->id, [$log->id]))->handle(app(App\Services\Notifications\NotificationGatewayService::class));
    Http::assertNothingSent();
});

test('an event email explicitly cancelled by the mail pipeline releases its reservation', function () {
    $this->blast->update(['send_sms' => false]);
    (new SendEventBlastJob($this->blast))->handle();
    $mail = Mail::queued(EventBlastMail::class)->first();
    app(App\Services\Community\EventAnnouncementDelivery::class)->sendQueuedEmail($mail, fn () => null);
    expect(app(EmailAllowance::class)->remaining($this->tenant))->toBe(10);
});

test('missing legacy email entitlements stay compatible without bypassing explicit withdrawals', function () {
    $this->tenant->features()->where('feature_key', 'email_credits')->delete();
    $allowance = app(EmailAllowance::class);
    expect($allowance->reserve($this->tenant, 1))->toBeNull();
    $receipt = $allowance->reserve($this->tenant, 1, allowMissingLegacyFeature: true);
    expect($receipt)->not->toBeNull();
    $allowance->refund($this->tenant, $receipt->id);
    $this->tenant->features()->create(['feature_key' => 'email_credits', 'enabled' => false]);
    expect($allowance->reserve($this->tenant, 1, allowMissingLegacyFeature: true))->toBeNull();
});

test('a legacy queued SMS still resolves its original registration safely', function () {
    $row = app(App\Services\Notifications\NotificationDeliveryClaimService::class)->claim(
        $this->event, null, 'announcement', 'blast-sms:'.$this->blast->id.':'.$this->registration->id,
        ['name' => 'Ama', 'email' => $this->registration->email, 'phone' => $this->registration->phone], 'sms',
        ['subject' => 'News', 'body' => 'See you.'], $this->blast
    );
    (new App\Jobs\Notifications\SendSmsBatchJob($this->tenant->id, [$row->id]))->handle(app(App\Services\Notifications\NotificationGatewayService::class));
    Http::assertSentCount(1);
});

test('a throttled event blast retries with one final SMS debit', function () {
    Illuminate\Support\Facades\Queue::fake([App\Jobs\Notifications\SendSmsBatchJob::class]);
    (new SendEventBlastJob($this->blast))->handle();
    $row = EventNotificationLog::query()->where('channel', 'sms')->firstOrFail();
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['messaging.test/*' => Http::sequence()->push([], 429, ['Retry-After' => '42'])->push(['data' => ['campaign_id' => 'retried']], 202)]);
    $gateway = app(App\Services\Notifications\NotificationGatewayService::class);
    $result = $gateway->deliverSmsBatch(collect([$row]));
    expect($result['retry_after'])->toBe(42)->and($row->fresh()->status)->toBe(EventNotificationLog::STATUS_PENDING)
        ->and(app(App\Services\Sms\SmsAllowance::class)->remaining($this->tenant))->toBe(10);
    $gateway->deliverSmsBatch(collect([$row->fresh()]));
    expect($row->fresh()->status)->toBe(EventNotificationLog::STATUS_SENT)->and(app(App\Services\Sms\SmsAllowance::class)->remaining($this->tenant))->toBe(9);
    Http::assertSentCount(2);
});

test('event cancellation while a batch is prepared stops all its unsubmitted SMS', function () {
    Illuminate\Support\Facades\Queue::fake([App\Jobs\Notifications\SendSmsBatchJob::class]);
    EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'phone' => '+233241234568', 'status' => EventRegistration::STATUS_CONFIRMED]);
    (new SendEventBlastJob($this->blast))->handle();
    $claims = 0;
    EventNotificationLog::updated(function ($row) use (&$claims) {
        if ($row->wasChanged('status') && $row->status === EventNotificationLog::STATUS_STAGED && ++$claims === 2) {
            $this->blast->update(['status' => EventBlast::STATUS_CANCELLED]);
        }
    });
    app(App\Services\Notifications\NotificationGatewayService::class)->deliverSmsBatch(EventNotificationLog::query()->where('channel', 'sms')->get());
    Http::assertNothingSent();
    expect(app(App\Services\Sms\SmsAllowance::class)->remaining($this->tenant))->toBe(10);
});
