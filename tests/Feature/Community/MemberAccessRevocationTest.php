<?php

declare(strict_types=1);

use App\Models\CommunityAccessCredential;
use App\Models\Member;
use App\Services\Community\MemberAccess;
use App\Services\Community\MemberContactChanges;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$this->tenant, $this->owner] = eventHost('revoke-qa');
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    Mail::fake();
    Illuminate\Support\Facades\Queue::fake([App\Jobs\Community\DeliverContactNotice::class]);
    $this->member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'old@example.test', 'phone' => '+233241234567']);
    $this->session = app('session.store');
    $this->session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $this->member->email, 'expires_at' => now()->addHour()->timestamp]);
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $this->member->phone, 'expires_at' => now()->addHour()->timestamp]);
    $this->credential = app(MemberAccess::class)->enrolPhone($this->session, $this->member);
});

it('invalidates phone grants even when a direct model contact edit changes away and back', function (): void {
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    $this->member->update(['phone' => '+233241234568']);
    $this->member->update(['phone' => '+233241234567']);
    expect($this->member->fresh()->access_version)->toBe(3)
        ->and(app(MemberAccess::class)->canView($this->session, $this->member))->toBeFalse()
        ->and(app(MemberAccess::class)->establish($this->session, $this->credential['selector'], $this->credential['secret']))->toBeFalse();
});

it('does not invalidate access for an equivalent normalised contact', function (): void {
    $this->member->update(['phone' => '024 123 4567', 'email' => ' OLD@EXAMPLE.TEST ']);
    expect($this->member->fresh()->access_version)->toBe(1);
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    expect(app(MemberAccess::class)->canView($this->session, $this->member))->toBeTrue();
});

it('updates contacts through an authorised action and removes the old email access immediately', function (): void {
    app(MemberContactChanges::class)->update($this->member, ['email' => 'new@example.test', 'phone' => '+233241234568'], $this->owner);
    expect(app(MemberAccess::class)->canView($this->session, $this->member))->toBeFalse()
        ->and($this->member->fresh()->access_version)->toBe(2);
    $this->session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'new@example.test', 'expires_at' => now()->addHour()->timestamp]);
    expect(app(MemberAccess::class)->canView($this->session, $this->member))->toBeTrue();
});

it('revokes a member credential with evidence and preserves membership history', function (): void {
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    app(MemberAccess::class)->revoke($this->member, $this->owner, 'Member reported a compromised recovery credential.');
    expect(app(MemberAccess::class)->canView($this->session, $this->member))->toBeFalse()
        ->and(Member::query()->whereKey($this->member->id)->exists())->toBeTrue();
    expect(CommunityAccessCredential::query()->whereKey($this->credential['selector'])->first()->revoked_at)->not->toBeNull();
});

it('forgets all attendee proofs and grants without signing out the staff console', function (): void {
    $host = mb_ltrim((string) config('session.domain'), '.');
    $this->actingAs($this->owner)->withServerVariables(['HTTP_HOST' => $host])->withSession($this->session->all())
        ->postJson('/my/verify/forget')->assertOk();
    expect(app(PlatformAttendeeVerification::class)->verifiedEmail(app('session.store')))->toBeNull()
        ->and(app(PhoneVerification::class)->verifiedPhone(app('session.store')))->toBeNull()
        ->and(app('session.store')->get('community_member_grants', []))->toBe([]);
    $this->assertAuthenticatedAs($this->owner);
});

it('sends generic contact notices once and does not disclose member history', function (): void {
    app(MemberContactChanges::class)->update($this->member, ['email' => 'new@example.test'], $this->owner);
    $notices = App\Models\CommunityContactNotice::query()->where('channel', 'email')->get();
    expect($notices)->toHaveCount(2);
    foreach ($notices as $notice) {
        app(App\Services\Community\ContactNotices::class)->deliver($notice->id);
        app(App\Services\Community\ContactNotices::class)->deliver($notice->id);
    }
    Mail::assertSent(App\Mail\CommunityContactNotice::class, 2);
    Mail::assertSent(App\Mail\CommunityContactNotice::class, function ($mail): bool {
        $html = $mail->render();

        return ! str_contains($html, $this->member->name) && ! str_contains($html, $this->member->pay_token) && ! str_contains($html, 'new@example.test');
    });
});

it('uses accountable tenant SMS reservations for generic notices and never retries an unknown send', function (): void {
    $this->tenant->features()->create(['feature_key' => 'sms_credits', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 2]]);
    App\Models\TenantNotificationSetting::forTenant($this->tenant->id)->update(['sms_enabled' => true]);
    $gateway = Mockery::mock(App\Contracts\SmsGateway::class);
    $gateway->shouldReceive('isConfigured')->andReturn(true);
    $gateway->shouldReceive('send')->once()->andReturnUsing(fn ($messages) => [$messages[0]->reference => App\Services\Sms\SmsResult::unknown('Uncertain test transport.')]);
    app()->instance(App\Contracts\SmsGateway::class, $gateway);
    app(MemberContactChanges::class)->update($this->member, ['phone' => '+233241234568'], $this->owner);
    $notice = App\Models\CommunityContactNotice::query()->where('channel', 'sms')->firstOrFail();
    app(App\Services\Community\ContactNotices::class)->deliver($notice->id);
    app(App\Services\Community\ContactNotices::class)->deliver($notice->id);
    expect($notice->fresh()->status)->toBe('unknown');
    expect(App\Models\CommunitySmsReservation::query()->where('source_type', 'member_contact_notice')->where('source_id', $notice->id)->first()->reason)->toBe('outcome_unknown');
});

it('revokes an individual representative without removing the member own grant', function (): void {
    $representative = CommunityAccessCredential::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'role' => 'representative', 'phone' => '+233241234568', 'phone_hash' => PhoneVerification::digest('+233241234568')]);
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    app(MemberAccess::class)->revokeCredential($representative, $this->owner, 'Representative authority withdrawn.');
    expect($representative->fresh()->revoked_at)->not->toBeNull()
        ->and(app(MemberAccess::class)->canView($this->session, $this->member))->toBeTrue();
});

it('exposes tenant-scoped contact review and credential controls without credential secrets', function (): void {
    $report = App\Models\CommunityWrongNumberReport::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234568', 'phone_hash' => PhoneVerification::digest('+233241234568')]);
    $base = 'http://'.eventSubdomainHost('revoke-qa').'/community/';
    $this->actingAs($this->owner)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('revoke-qa')]);
    $this->getJson($base.'access/reports')->assertOk()->assertJsonPath('reports.data.0.id', $report->id);
    $this->postJson($base.'access/reports/'.$report->id.'/review', ['confirmed' => true, 'reason' => 'Confirmed by an independent contact check.'])->assertOk();
    $this->postJson($base.'access/reports/'.$report->id.'/resolve', ['method' => 'in_person', 'reason' => 'New owner independently checked in person.'])->assertOk();
    $this->getJson($base.'people/members/'.$this->member->id.'/credentials')->assertOk()->assertJsonMissingPath('credentials.0.secret_hash');
    $this->postJson($base.'access/credentials/'.$this->credential['selector'].'/revoke', ['reason' => 'Representative authority withdrawn.'])->assertOk();
    $this->postJson($base.'people/members/'.$this->member->id.'/revoke-access', ['reason' => 'All phone access requires a fresh check.'])->assertOk();
    expect(CommunityAccessCredential::query()->findOrFail($this->credential['selector'])->revoked_at)->not->toBeNull();
});
