<?php

declare(strict_types=1);

use App\Contracts\SmsGateway;
use App\Jobs\SendDuesReminders;
use App\Mail\DuesReminder;
use App\Models\CommunitySettings;
use App\Models\DuesCharge;
use App\Models\DuesReminder as Reminder;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\TenantAddon;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Services\Dues\DuesReminders;
use App\Services\Sms\OmnichannelSmsGateway;
use App\Services\Sms\SmsAllowance;
use App\Services\Tenancy\FeatureMeteringService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Cache::flush();
    [$this->tenant, $this->owner] = eventHost('reminders');
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Africa/Accra'));
    $this->type = MembershipType::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id, 'name' => 'Ama Mensah', 'email' => 'ama@example.test', 'phone' => '0241234567']);
    $this->charge = DuesCharge::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'membership_type_id' => $this->type->id, 'due_on' => '2026-10-08']);
    TenantNotificationSetting::forTenant($this->tenant->id)->update(['sms_enabled' => true]);
    $this->pack = TenantAddon::factory()->create(['tenant_id' => $this->tenant->id, 'addon_type' => TenantAddon::TYPE_SMS_PACK, 'quantity' => 10, 'billing_interval' => TenantAddon::INTERVAL_ONE_OFF, 'status' => TenantAddon::STATUS_ACTIVE]);
    Mail::fake();
    Http::preventStrayRequests();
    config(['services.omnichannel' => ['url' => 'https://messaging.test', 'token' => 'fake-token', 'sender_id' => 'MiConvener']]);
    app()->instance(SmsGateway::class, OmnichannelSmsGateway::fromConfig());
    Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'reminder']], 202)]);
    $this->actingAs($this->owner)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('reminders')]);
});

function remindToday(object $test): array
{
    return app(DuesReminders::class)->sendDue($test->tenant, CarbonImmutable::now('Africa/Accra')->startOfDay());
}

function reminderSettings(object $test, array $values): void
{
    CommunitySettings::for($test->tenant)->fill($values)->save();
}

test('automatic and manual reminders share one daily reservation per channel', function () {
    expect(remindToday($this))->toBe(['sent' => 2, 'skipped' => 0]);
    expect(app(DuesReminders::class)->remindArrears($this->tenant, (int) $this->owner->id))->toBe(['sent' => 0, 'skipped' => 2]);
    expect(Reminder::query()->count())->toBe(2)->and($this->pack->fresh()->meta['remaining'])->toBe(9);
    Mail::assertSent(DuesReminder::class, 1);
    Http::assertSentCount(1);
});

test('monthly quarterly and yearly offsets select their configured step', function (string $period, string $due, string $step) {
    $this->type->update(['period' => $period]);
    $this->charge->update(['due_on' => $due]);
    remindToday($this);
    expect(Reminder::query()->where('channel', 'email')->sole()->step)->toBe($step);
})->with(['monthly', 'quarterly', 'yearly'])->with([
    ['2026-10-22', 'before'], ['2026-10-15', 'on_day'], ['2026-10-08', 'after_7'], ['2026-09-15', 'after_30'],
]);

test('digest handles daily and weekly charges and chosen week start', function (string $period) {
    $this->type->update(['period' => $period]);
    reminderSettings($this, ['frequent_digest' => 'weekly', 'week_starts_on' => 1]);
    expect(remindToday($this)['sent'])->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00:00'));
    expect(remindToday($this)['sent'])->toBe(2)->and(remindToday($this)['sent'])->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
    reminderSettings($this, ['frequent_digest' => 'daily']);
    expect(remindToday($this)['sent'])->toBe(2);
})->with(['daily', 'weekly']);

test('email lists all open charges and SMS preserves currency totals link and oldest date', function () {
    $other = DuesCharge::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'membership_type_id' => $this->type->id, 'period_start' => '2026-09-01', 'due_on' => '2026-09-01', 'currency' => 'USD', 'amount' => 700]);
    remindToday($this);
    Mail::assertSent(DuesReminder::class, function ($mail) {
        $html = $mail->render();

        return $mail->charges->count() === 2 && str_contains($html, 'USD 7.00') && str_contains($html, 'GHS 50.00') && str_contains($html, '2026-09-01') && str_contains($html, $this->member->pay_token) && str_contains(html_entity_decode(strip_tags($html)), $this->tenant->name);
    });
    Http::assertSent(fn ($request) => str_contains($request->data()[0]['message'], 'USD 7.00') && str_contains($request->data()[0]['message'], 'GHS 50.00') && str_contains($request->data()[0]['message'], '2026-09-01') && str_contains($request->data()[0]['message'], $this->member->pay_token));
    expect(Reminder::query()->where('channel', 'email')->sole()->charge_ids)->toContain($other->id, $this->charge->id);
});

test('reminders disabled stops automatic and manual delivery', function () {
    reminderSettings($this, ['reminders_enabled' => false]);
    expect(remindToday($this)['sent'])->toBe(0)->and(app(DuesReminders::class)->remindArrears($this->tenant, (int) $this->owner->id)['sent'])->toBe(0);
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('unavailable SMS is skipped without debiting while email still sends', function (string $reason) {
    if ($reason === 'no_credit') {
        $this->pack->delete();
    }
    if ($reason === 'sms_disabled') {
        TenantNotificationSetting::forTenant($this->tenant->id)->update(['sms_enabled' => false]);
    }
    if ($reason === 'monthly_limit') {
        reminderSettings($this, ['sms_monthly_limit' => 0]);
    }
    if ($reason === 'not_configured') {
        app()->instance(SmsGateway::class, new App\Services\Sms\NullSmsGateway);
    }
    if ($reason === 'message_too_long') {
        $this->tenant->update(['name' => str_repeat('Long organisation ', 14)]);
        $this->member->update(['name' => str_repeat('Long member ', 20)]);
    }
    expect(remindToday($this))->toBe(['sent' => 1, 'skipped' => 1]);
    expect(Reminder::query()->where('channel', 'sms')->sole()->reason)->toBe($reason);
    Mail::assertSent(DuesReminder::class, 1);
    Http::assertNothingSent();
})->with(['no_credit', 'sms_disabled', 'monthly_limit', 'not_configured', 'message_too_long']);

test('confirmed refusal refunds original pack once and never retries that day', function () {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['messaging.test/*' => Http::response(['message' => 'Rejected'], 422)]);
    expect(remindToday($this)['sent'])->toBe(1)->and($this->pack->fresh()->meta['remaining'])->toBe(10);
    $row = Reminder::query()->where('channel', 'sms')->sole();
    expect($row->status)->toBe('failed');
    app(SmsAllowance::class)->refund($this->tenant, $row->credit_receipt_id);
    expect($this->pack->fresh()->meta['remaining'])->toBe(10);
    remindToday($this);
    Http::assertSentCount(1);
});

test('uncertain gateway outcomes remain reserved and cannot refund or retry automatically', function (string $outcome) {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['messaging.test/*' => $outcome === 'timeout' ? Http::failedConnection() : Http::response([], 500)]);
    remindToday($this);
    expect(Reminder::query()->where('channel', 'sms')->sole()->status)->toBe('reserved')->and($this->pack->fresh()->meta['remaining'])->toBe(9);
    remindToday($this);
    expect($this->pack->fresh()->meta['remaining'])->toBe(9);
})->with(['timeout', 'server_error']);

test('credit refund restores exact sources but cannot subtract from reset usage', function () {
    $this->tenant->features()->create(['feature_key' => 'sms_credits', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 1]]);
    $allowance = app(SmsAllowance::class);
    $receipt = $allowance->reserve($this->tenant, 3);
    expect($this->pack->fresh()->meta['remaining'])->toBe(8);
    app(FeatureMeteringService::class)->resetUsage($this->tenant, 'sms_credits');
    $allowance->consume($this->tenant, 1);
    $allowance->refund($this->tenant, $receipt->id);
    $allowance->refund($this->tenant, $receipt->id);
    expect($this->pack->fresh()->meta['remaining'])->toBe(10)->and($this->tenant->usage()->where('feature_slug', 'sms_credits')->value('used_count'))->toBe(1);
});

test('arrears endpoints queue only after both permissions and job rechecks permission', function () {
    Queue::fake();
    $base = ['subdomain' => 'reminders'];
    $this->getJson(route('tenant.community.money.dues.remind-arrears.preview', $base))->assertOk()->assertJsonPath('members', 1)->assertJsonPath('sms_credits_needed', 1);
    $this->postJson(route('tenant.community.money.dues.remind-arrears', $base))->assertAccepted()->assertJsonPath('status', 'queued');
    Queue::assertPushed(SendDuesReminders::class);
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach((int) $user->id);
    $user->givePermissionTo('manage members');
    $this->actingAs($user);
    $this->getJson(route('tenant.community.money.dues.remind-arrears.preview', $base))->assertForbidden();
    $this->postJson(route('tenant.community.money.dues.remind-arrears', $base))->assertForbidden();
    expect(fn () => app(DuesReminders::class)->remindArrears($this->tenant, (int) $user->id))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

test('settings persist defaults and validate values with tenant isolation', function () {
    $payload = ['reminders_enabled' => false, 'reminder_offsets' => ['before' => 4, 'on_day' => 0, 'after_7' => 8, 'after_30' => 32], 'frequent_digest' => 'weekly', 'sms_monthly_limit' => 5];
    $url = route('tenant.community.settings.update', ['subdomain' => 'reminders']);
    $this->patchJson($url, $payload)->assertOk();
    expect(CommunitySettings::for($this->tenant)->exists)->toBeTrue()->and(CommunitySettings::for($this->tenant)->reminders_enabled)->toBeFalse();
    $this->patchJson($url, [...$payload, 'sms_monthly_limit' => -1, 'frequent_digest' => 'hourly'])->assertUnprocessable()->assertJsonValidationErrors(['sms_monthly_limit', 'frequent_digest']);
});

test('command queues reminder phase even after generation already ran today', function () {
    Queue::fake();
    reminderSettings($this, []);
    CommunitySettings::for($this->tenant)->forceFill(['last_generated_on' => '2026-10-15'])->save();
    $this->artisan('dues:process', ['--tenant' => $this->tenant->slug])->assertSuccessful();
    Queue::assertPushed(SendDuesReminders::class);
});

test('white label identity uses organisation reply to and reminders do not meter email', function () {
    $this->tenant->features()->create(['feature_key' => 'white_label', 'enabled' => true, 'meta' => ['type' => 'boolean', 'value' => true]]);
    remindToday($this);
    Mail::assertSent(DuesReminder::class, function ($mail) {
        $envelope = $mail->envelope();

        return $envelope->from->name === $this->tenant->name && $envelope->replyTo[0]->address === $this->tenant->email;
    });
    expect($this->tenant->usage()->where('feature_slug', 'email_credits')->sum('used_count'))->toBe(0);
});

test('reserved SMS counts towards the monthly limit and resets by organisation local month', function () {
    reminderSettings($this, ['sms_monthly_limit' => 1]);
    $other = Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id]);
    $row = Reminder::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $other->id, 'sent_for_date' => '2026-10-01', 'status' => 'reserved']);
    expect(remindToday($this)['sent'])->toBe(1);
    expect(Reminder::query()->where('member_id', $this->member->id)->where('channel', 'sms')->sole()->reason)->toBe('monthly_limit');
    $row->update(['sent_for_date' => '2026-09-30']);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00'));
    expect(app(DuesReminders::class)->remindArrears($this->tenant, (int) $this->owner->id)['sent'])->toBe(2);
});

test('service reads only the requested tenant even when another tenant context is active', function () {
    [$other, $user] = eventHost('other-reminders');
    Artisan::call('community:enable', ['tenant' => $other->slug]);
    expect(remindToday($this)['sent'])->toBe(2);
    Mail::assertSent(DuesReminder::class, fn ($mail) => $mail->member->tenant_id === $this->tenant->id);
    expect(fn () => app(DuesReminders::class)->remindArrears($this->tenant, (int) $user->id))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('queued arrears job checks revoked permissions and rollout before delivery', function () {
    $job = new SendDuesReminders($this->tenant->id, (int) $this->owner->id);
    $this->owner->syncRoles([]);
    expect(fn () => $job->handle(app(DuesReminders::class)))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('provider throttling refunds credits records a skip and does not loop', function () {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['messaging.test/*' => Http::response([], 429, ['Retry-After' => 30])]);
    remindToday($this);
    expect(Reminder::query()->where('channel', 'sms')->sole()->reason)->toBe('provider_throttled')->and($this->pack->fresh()->meta['remaining'])->toBe(10);
    remindToday($this);
    Http::assertSentCount(1);
});

test('many open charges remain fully itemised in email while SMS stays bounded', function () {
    $this->type->update(['period' => 'daily']);
    foreach (range(1, 35) as $days) {
        $date = CarbonImmutable::parse('2026-08-01')->addDays($days)->toDateString();
        DuesCharge::query()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'membership_type_id' => $this->type->id, 'period_start' => $date, 'period_end' => $date, 'due_on' => $date, 'amount' => 100, 'currency' => 'GHS', 'status' => 'open']);
    }
    remindToday($this);
    Mail::assertSent(DuesReminder::class, fn ($mail) => $mail->charges->count() === 36 && mb_substr_count($mail->render(), 'GHS 1.00') === 35);
    Http::assertSent(fn ($request) => mb_strlen($request->data()[0]['message']) <= 459 && str_contains($request->data()[0]['message'], '36 open dues') && str_contains($request->data()[0]['message'], $this->member->pay_token));
});

test('a failed plan reservation refunds that usage row exactly once', function () {
    $this->tenant->features()->create(['feature_key' => 'sms_credits', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 2]]);
    $allowance = app(SmsAllowance::class);
    $receipt = $allowance->reserve($this->tenant, 1);
    $allowance->consume($this->tenant, 1);
    $allowance->refund($this->tenant, $receipt->id);
    $allowance->refund($this->tenant, $receipt->id);
    expect($this->tenant->usage()->where('feature_slug', 'sms_credits')->value('used_count'))->toBe(1)->and($allowance->packRemaining($this->tenant))->toBe(10);
});

test('automatic queue job respects disabled rollout and does not send stale local dates', function () {
    (new SendDuesReminders($this->tenant->id, localDate: '2026-10-14'))->handle(app(DuesReminders::class));
    expect(Reminder::query()->count())->toBe(0);
    $this->tenant->features()->where('feature_key', 'community')->update(['enabled' => false]);
    $this->tenant->features()->where('feature_key', 'member_dues')->update(['enabled' => false]);
    (new SendDuesReminders($this->tenant->id))->handle(app(DuesReminders::class));
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('arrears preview and sending also refuse staff with finance but no member management', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($user->id);
    $user->givePermissionTo('read finance');
    $this->actingAs($user);
    $this->getJson(route('tenant.community.money.dues.remind-arrears.preview', ['subdomain' => 'reminders']))->assertForbidden();
    $this->postJson(route('tenant.community.money.dues.remind-arrears', ['subdomain' => 'reminders']))->assertForbidden();
    $this->patchJson(route('tenant.community.settings.update', ['subdomain' => 'reminders']), [])->assertForbidden();
});

test('queued audiences continue independently after a delivery unit fails', function () {
    Queue::fake();
    $this->pack->update(['quantity' => 200]);
    foreach (range(1, 100) as $number) {
        $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id, 'phone' => '0241234567', 'email' => 'member'.$number.'@example.test']);
        DuesCharge::query()->create(['tenant_id' => $this->tenant->id, 'member_id' => $member->id, 'membership_type_id' => $this->type->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_on' => '2026-10-08', 'amount' => 100, 'currency' => 'GHS', 'status' => 'open']);
    }
    (new SendDuesReminders($this->tenant->id, (int) $this->owner->id))->handle(app(DuesReminders::class));
    Mail::assertNothingSent();
    Http::assertNothingSent();
    expect(Reminder::query()->count())->toBe(0);
    $units = Queue::pushed(App\Jobs\DeliverDuesReminders::class);
    $emails = $units->filter(fn ($job) => $job->channel === 'email');
    expect($emails)->toHaveCount(100);
    foreach ($units->filter(fn ($job) => $job->channel === 'sms') as $job) {
        expect(count($job->memberIds))->toBeLessThanOrEqual(50)->and($job->timeout)->toBeLessThan(90);
    }
    $continuation = Queue::pushed(SendDuesReminders::class)->sole();
    $fail = true;
    Reminder::creating(function () use (&$fail): void {
        if ($fail) {
            $fail = false;
            throw new RuntimeException('Unit interrupted before claiming a recipient.');
        }
    });
    expect(fn () => $emails->first()->handle(app(DuesReminders::class)))->toThrow(RuntimeException::class);
    $continuation->handle(app(DuesReminders::class));
    $allEmails = Queue::pushed(App\Jobs\DeliverDuesReminders::class)->filter(fn ($job) => $job->channel === 'email');
    expect($allEmails)->toHaveCount(101);
    foreach ($allEmails as $job) {
        expect($job->memberIds)->toHaveCount(1)->and($job->timeout)->toBeLessThan(90);
        $job->handle(app(DuesReminders::class));
    }
    Mail::assertSent(DuesReminder::class, 101);
    Http::assertNothingSent();
});

test('queued SMS is submitted independently without waiting on email transport', function () {
    Queue::fake();
    (new SendDuesReminders($this->tenant->id, (int) $this->owner->id))->handle(app(DuesReminders::class));
    Mail::assertNothingSent();
    $units = Queue::pushed(App\Jobs\DeliverDuesReminders::class);
    expect($units->first()->channel)->toBe('sms');
    $units->first()->handle(app(DuesReminders::class));
    Http::assertSentCount(1);
    Mail::assertNothingSent();
    expect(Reminder::query()->where('channel', 'sms')->sole()->status)->toBe('sent');
    $units->last()->handle(app(DuesReminders::class));
    Mail::assertSent(DuesReminder::class, 1);
});

test('delivery units recheck permissions settings date and current charges', function () {
    Queue::fake();
    (new SendDuesReminders($this->tenant->id, (int) $this->owner->id))->handle(app(DuesReminders::class));
    $unit = Queue::pushed(App\Jobs\DeliverDuesReminders::class)->first();
    $this->owner->syncRoles([]);
    expect(fn () => $unit->handle(app(DuesReminders::class)))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    $this->owner->assignRole('Org Superadmin');
    reminderSettings($this, ['reminders_enabled' => false]);
    $unit->handle(app(DuesReminders::class));
    expect(Reminder::query()->count())->toBe(0);
    reminderSettings($this, ['reminders_enabled' => true]);
    $this->charge->update(['status' => 'paid']);
    $unit->handle(app(DuesReminders::class));
    expect(Reminder::query()->count())->toBe(0);
    $this->charge->update(['status' => 'open']);
    (new App\Jobs\DeliverDuesReminders($this->tenant->id, [$this->member->id], 'sms', localDate: '2026-10-14'))->handle(app(DuesReminders::class));
    Http::assertNothingSent();
});

test('retried SMS units retain unknown claims and deliver only untouched recipients', function () {
    $other = Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id, 'phone' => '0241234567']);
    DuesCharge::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $other->id, 'membership_type_id' => $this->type->id, 'due_on' => '2026-10-08']);
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['messaging.test/*' => Http::response([], 500)]);
    $first = new App\Jobs\DeliverDuesReminders($this->tenant->id, [$this->member->id], 'sms', (int) $this->owner->id);
    $first->handle(app(DuesReminders::class));
    $first->handle(app(DuesReminders::class));
    Http::assertSentCount(1);
    expect(Reminder::query()->sole()->status)->toBe('reserved')->and($this->pack->fresh()->meta['remaining'])->toBe(9);
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'second']], 202)]);
    (new App\Jobs\DeliverDuesReminders($this->tenant->id, [$this->member->id, $other->id], 'sms', (int) $this->owner->id))->handle(app(DuesReminders::class));
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => count($request->data()[0]['users']) === 1);
    expect(Reminder::query()->where('member_id', $other->id)->sole()->status)->toBe('sent')->and($this->pack->fresh()->meta['remaining'])->toBe(8);
    Mail::assertNothingSent();
});

test('serialized manual delivery retries never replay across local midnight', function (int $responseStatus, string $status) {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'before-midnight']], $responseStatus)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 23:59:59', 'Africa/Accra'));
    $payload = serialize(new App\Jobs\DeliverDuesReminders($this->tenant->id, [$this->member->id], 'sms', (int) $this->owner->id));
    unserialize($payload)->handle(app(DuesReminders::class));
    expect(Reminder::query()->sole()->status)->toBe($status);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 00:00:01', 'Africa/Accra'));
    unserialize($payload)->handle(app(DuesReminders::class));
    Http::assertSentCount(1);
    expect(Reminder::query()->count())->toBe(1)->and($this->pack->fresh()->meta['remaining'])->toBe(9);
    expect(Reminder::query()->sole()->sent_for_date->toDateString())->toBe('2026-10-15');
    Queue::fake();
    $this->postJson(route('tenant.community.money.dues.remind-arrears', ['subdomain' => 'reminders']))->assertAccepted();
    $nextRun = Queue::pushed(SendDuesReminders::class)->sole();
    expect($nextRun->localDate)->toBe('2026-10-16');
    $nextRun->handle(app(DuesReminders::class));
    Queue::pushed(App\Jobs\DeliverDuesReminders::class)->first()->handle(app(DuesReminders::class));
    Http::assertSentCount(2);
    expect(Reminder::query()->count())->toBe(2)->and($this->pack->fresh()->meta['remaining'])->toBe(8);
})->with([[500, 'reserved'], [202, 'sent']]);

test('manual dispatcher payload freezes the run day before dispatch and continuation retries', function () {
    Queue::fake();
    foreach (range(1, 100) as $number) {
        $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id]);
        DuesCharge::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $member->id, 'membership_type_id' => $this->type->id, 'due_on' => '2026-10-08']);
    }
    $payload = serialize(new SendDuesReminders($this->tenant->id, (int) $this->owner->id));
    expect(unserialize($payload)->localDate)->toBe('2026-10-15');
    unserialize($payload)->handle(app(DuesReminders::class));
    $continuation = serialize(Queue::pushed(SendDuesReminders::class)->sole());
    expect(unserialize($continuation)->localDate)->toBe('2026-10-15');
    foreach (Queue::pushed(App\Jobs\DeliverDuesReminders::class) as $unit) {
        expect(unserialize(serialize($unit))->localDate)->toBe('2026-10-15');
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-16 00:00:01', 'Africa/Accra'));
    Queue::fake();
    unserialize($payload)->handle(app(DuesReminders::class));
    unserialize($continuation)->handle(app(DuesReminders::class));
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    Mail::assertNothingSent();
});
