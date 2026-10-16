<?php

declare(strict_types=1);

use App\Models\Member;
use App\Models\Tenant;
use App\Services\Community\MemberAccess;
use App\Services\Community\PhoneVerification;
use App\Services\Community\WrongNumberReports;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$this->tenant, $this->owner] = eventHost('wrong-number-qa');
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    $this->session = app('session.store');
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
});

it('queues a number report without revealing or revoking matching members', function (): void {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234567', 'name' => 'Private member']);
    $service = app(WrongNumberReports::class);
    $report = $service->report($this->tenant, $this->session, 'I now own this number.');
    expect($report->status)->toBe('pending')->and($service->isBlocked($this->tenant, 'sms', $member->phone))->toBeFalse()
        ->and($member->fresh()->access_version)->toBe(1);
    $same = $service->report($this->tenant, $this->session, 'Second report.');
    expect($same->id)->toBe($report->id);
    $this->withServerVariables(['HTTP_HOST' => mb_ltrim((string) config('session.domain'), '.')])->withSession($this->session->all())
        ->postJson('/my/communities/'.$this->tenant->slug.'/wrong-number', ['note' => 'I now own this number.'])
        ->assertOk()->assertJsonMissing(['name' => $member->name])->assertJsonMissingPath('members');
});

it('blocks a confirmed disputed destination and revokes its phone grants without deleting history', function (): void {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234567']);
    $this->session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
    app(MemberAccess::class)->enrolPhone($this->session, $member);
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    $service = app(WrongNumberReports::class);
    $report = $service->report($this->tenant, $this->session, 'Wrong member at this number.');
    $service->review($report, $this->owner, true, 'Confirmed reassignment independently.');
    expect($service->isBlocked($this->tenant, 'sms', $member->phone))->toBeTrue()
        ->and($service->isBlocked(Tenant::factory()->create(), 'sms', $member->phone))->toBeFalse()
        ->and(app(MemberAccess::class)->canView($this->session, $member))->toBeFalse()
        ->and($member->fresh())->not->toBeNull();
});

it('lets an email-authorised member report their recorded number without controlling it', function (): void {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->session->forget(PhoneVerification::SESSION_KEY);
    $this->session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);
    $report = app(WrongNumberReports::class)->report($this->tenant, $this->session, 'My recorded number is outdated.', $member);
    expect($report->status)->toBe('pending');
    $other = Member::factory()->create(['tenant_id' => $this->tenant->id]);
    expect(fn () => app(WrongNumberReports::class)->report($this->tenant, $this->session, 'Another member.', $other))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

it('requires a tenant-authorised reviewer and does not block a dismissed report', function (): void {
    $service = app(WrongNumberReports::class);
    $report = $service->report($this->tenant, $this->session, 'Please check.');
    $reader = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    expect(fn () => $service->review($report, $reader, true, 'No independent authority.'))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    $service->review($report, $this->owner, false, 'Number ownership checked; report not confirmed.');
    expect($service->isBlocked($this->tenant, 'sms', '+233241234567'))->toBeFalse()->and($report->fresh()->status)->toBe('dismissed');
});

it('requires an independent reconfirmation to clear a known number hold without restoring old credentials', function (): void {
    $old = Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234567']);
    $this->session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $old->email, 'expires_at' => now()->addHour()->timestamp]);
    $credential = app(MemberAccess::class)->enrolPhone($this->session, $old);
    $reports = app(WrongNumberReports::class);
    $report = $reports->report($this->tenant, $this->session, 'Number reassigned.');
    $reports->review($report, $this->owner, true, 'Confirmed number ownership changed.');
    $new = Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => $old->phone]);
    $this->session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $new->email, 'expires_at' => now()->addHour()->timestamp]);
    expect(fn () => app(MemberAccess::class)->enrolPhone($this->session, $new))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    $reports->resolve($report, $this->owner, 'in_person', 'New holder independently verified in person.');
    expect($reports->isBlocked($this->tenant, 'sms', $new->phone))->toBeFalse();
    $fresh = app(MemberAccess::class)->enrolPhone($this->session, $new);
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    expect(app(MemberAccess::class)->establish($this->session, $credential['selector'], $credential['secret']))->toBeFalse()
        ->and(app(MemberAccess::class)->establish($this->session, $fresh['selector'], $fresh['secret']))->toBeTrue();
});
