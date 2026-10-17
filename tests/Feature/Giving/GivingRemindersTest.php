<?php

declare(strict_types=1);

use App\Mail\GivingReminder as ReminderMail;
use App\Models\GivingReminder;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Giving\GivingReminders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Support\GivingScenario;

function givingReminderProof(string $email = 'giver@example.test'): Illuminate\Contracts\Session\Session
{
    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $email, 'expires_at' => now()->addHour()->timestamp]);

    return $session;
}

test('reminder consent requires independently verified email and does not enrol a member or follower', function () {
    [$tenant,,$fund] = GivingScenario::make(['visibility' => 'public']);
    $host = eventSubdomainHost($tenant->slug);
    $url = 'http://'.$host.'/give/'.$fund->slug.'/reminder';
    $this->withServerVariables(['HTTP_HOST' => $host])->postJson($url, ['consent' => true, 'cadence' => 'monthly'])->assertUnauthorized();
    $this->withSession(givingReminderProof()->all())->postJson($url, ['consent' => false, 'cadence' => 'monthly'])->assertUnprocessable();
    $this->postJson($url, ['consent' => true, 'cadence' => 'monthly', 'email' => 'victim@example.test'])->assertOk();
    $row = GivingReminder::withoutGlobalScopes()->sole();
    expect($row->email)->toBe('giver@example.test')->and($row->anchor_day)->toBe(1)->and($row->local_time)->toBe('09:00');
    expect(App\Models\Member::withoutGlobalScopes()->count())->toBe(0)->and(App\Models\DuesCharge::withoutGlobalScopes()->count())->toBe(0)->and(App\Models\CommunityFollower::withoutGlobalScopes()->count())->toBe(0);
});

test('monthly anchors survive short months and weekly schedules follow organisation timezone including DST', function () {
    [$tenant,,$fund] = GivingScenario::make(['visibility' => 'public']);
    App\Models\CommunitySettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['timezone' => 'America/New_York', 'week_starts_on' => 0]);
    $service = app(GivingReminders::class);
    $this->travelTo(CarbonImmutable::parse('2026-01-30 12:00:00', 'America/New_York'));
    $row = $service->subscribe($fund, givingReminderProof(), 'monthly', 31, true);
    expect($row->next_due_at->setTimezone($row->timezone)->format('Y-m-d H:i'))->toBe('2026-01-31 09:00');
    expect($service->nextDue($row, $row->next_due_at)->setTimezone($row->timezone)->format('Y-m-d H:i'))->toBe('2026-02-28 09:00');
    expect($service->nextDue($row, $service->nextDue($row, $row->next_due_at))->setTimezone($row->timezone)->format('Y-m-d H:i'))->toBe('2026-03-31 09:00');
    $this->travelTo(CarbonImmutable::parse('2026-03-07 10:00:00', 'America/New_York'));
    $row = $service->subscribe($fund, givingReminderProof(), 'weekly', null, true);
    expect($row->next_due_at->format('Y-m-d H:i'))->toBe('2026-03-08 13:00');
});

test('duplicate scheduler runs catch up once and consume one marketing credit only', function () {
    Mail::fake();
    [$tenant,,$fund] = GivingScenario::make(['visibility' => 'public']);
    $tenant->features()->create(['feature_key' => 'email_credits', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 10]]);
    $this->travelTo(CarbonImmutable::parse('2026-01-01 08:00:00', 'UTC'));
    $service = app(GivingReminders::class);
    $row = $service->subscribe($fund, givingReminderProof(), 'monthly', 1, true);
    $this->travelTo(CarbonImmutable::parse('2026-04-15 10:00:00', 'UTC'));
    $service->sendDue();
    $service->sendDue();
    Mail::assertSent(ReminderMail::class, 1);
    expect($row->fresh()->next_due_at->format('Y-m-d H:i'))->toBe('2026-05-01 09:00');
    expect($tenant->usage()->where('feature_slug', 'email_credits')->sum('used_count'))->toBe(1);
    expect(App\Models\DuesCharge::withoutGlobalScopes()->count())->toBe(0);
});

test('unsubscribe is confirmed by POST and later subscription rotates its token', function () {
    [$tenant,,$fund] = GivingScenario::make(['visibility' => 'public']);
    $service = app(GivingReminders::class);
    $row = $service->subscribe($fund, givingReminderProof(), 'monthly', 1, true);
    $token = $service->unsubscribeToken($row);
    $host = eventSubdomainHost($tenant->slug);
    $url = 'http://'.$host.'/giving/reminders/'.$row->id.'/unsubscribe?token='.$token;
    $this->withServerVariables(['HTTP_HOST' => $host])->get($url)->assertOk();
    expect($row->fresh()->disabled_at)->toBeNull();
    $this->postJson($url, ['token' => $token])->assertOk();
    expect($row->fresh()->disabled_at)->not->toBeNull();
    $service->subscribe($fund, givingReminderProof(), 'monthly', 1, true);
    $this->postJson($url, ['token' => $token])->assertNotFound();
});

test('disabled closed expired target-reached private or opted-out funds never solicit contributions', function (string $reason) {
    Mail::fake();
    [$tenant,,$fund] = GivingScenario::make(['visibility' => 'public']);
    $service = app(GivingReminders::class);
    $row = $service->subscribe($fund, givingReminderProof(), 'monthly', 1, true);
    $row->update(['next_due_at' => now()->subDay()]);
    match ($reason) {
        'flag' => $tenant->features()->where('feature_key', 'community_giving')->update(['enabled' => false]),
        'closed' => $fund->update(['state' => 'closed']),
        'expired' => $fund->update(['ends_on' => now()->subDay()->toDateString()]),
        'private' => $fund->update(['visibility' => 'private']),
        'target' => $fund->update(['kind' => 'campaign', 'target' => 1, 'close_on_target' => true]),
        'optout' => app(App\Services\Community\MessagingPreferences::class)->disableMarketing($tenant, 'email', $row->email),
        'unsubscribe' => $row->update(['disabled_at' => now()]),
    };
    if ($reason === 'target') {
        App\Models\GivingGift::factory()->create(['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'status' => 'completed', 'amount' => 100]);
    }
    $service->sendDue();
    Mail::assertNothingSent();
})->with(['flag', 'closed', 'expired', 'private', 'target', 'optout', 'unsubscribe']);

test('no email credits produces a staff outcome and never sends or uses SMS', function () {
    Mail::fake();
    [,,$fund] = GivingScenario::make(['visibility' => 'public']);
    $service = app(GivingReminders::class);
    $row = $service->subscribe($fund, givingReminderProof(), 'monthly', 1, true);
    $row->update(['next_due_at' => now()->subHour()]);
    $service->sendDue();
    Mail::assertNothingSent();
    expect($row->fresh()->delivery_state)->toBe('no_credit');
    expect(App\Models\CommunitySmsReservation::query()->count())->toBe(0);
});

test('only finance staff receive aggregate reminder and receipt delivery outcomes', function () {
    [$tenant,$user,$fund] = GivingScenario::make(['visibility' => 'public']);
    $row = app(GivingReminders::class)->subscribe($fund, givingReminderProof(), 'monthly', 1, true);
    $row->update(['delivery_state' => 'no_credit']);
    $host = eventSubdomainHost($tenant->slug);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => $host])->get('http://'.$host.'/community/money/giving')->assertInertia(fn ($page) => $page->where('delivery.reminders.no_credit', 1));
    $user->revokePermissionTo('read finance');
    $this->get('http://'.$host.'/community/money/giving')->assertInertia(fn ($page) => $page->where('delivery', null));
});

test('queued reminders recheck consent and uncertain mail outcomes stay claimed without a second credit', function () {
    Illuminate\Support\Facades\Queue::fake();
    [$tenant,,$fund] = GivingScenario::make(['visibility' => 'public']);
    $tenant->features()->create(['feature_key' => 'email_credits', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 10]]);
    $service = app(GivingReminders::class);
    $row = $service->subscribe($fund, givingReminderProof(), 'monthly', 1, true);
    $row->update(['next_due_at' => now()->subHour()]);
    $service->sendDue();
    $key = $row->fresh()->last_delivery_key;
    app(App\Services\Community\MessagingPreferences::class)->disableMarketing($tenant, 'email', $row->email);
    Mail::fake();
    $service->deliver($row->id, $key);
    Mail::assertNothingSent();
    expect($row->fresh()->delivery_state)->toBe('stopped');
    app(App\Services\Community\MessagingPreferences::class)->enableMarketing($tenant, 'email', $row->email);
    $row = $service->subscribe($fund, givingReminderProof(), 'monthly', 1, true);
    $row->update(['next_due_at' => now()->subHour()]);
    $service->sendDue();
    $key = $row->fresh()->last_delivery_key;
    Mail::swap(Mockery::mock(Illuminate\Contracts\Mail\Mailer::class));
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Unknown send result'));
    $service->deliver($row->id, $key);
    $service->deliver($row->id,$key);
    expect($row->fresh()->delivery_state)->toBe('outcome_unknown')->and($tenant->usage()->where('feature_slug','email_credits')->sum('used_count'))->toBe(1);
});
