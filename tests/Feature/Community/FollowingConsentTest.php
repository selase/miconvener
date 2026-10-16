<?php

declare(strict_types=1);

use App\Models\CommunityFollower;
use App\Models\Tenant;
use App\Services\Community\Following;
use App\Services\Community\MessagingPreferences;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Support\Facades\Artisan;

it('withdraws marketing for overlapping members and needs explicit consent to resume', function (): void {
    $tenant = Tenant::factory()->create();
    Artisan::call('community:following-enable', ['tenant' => $tenant->slug]);
    $session = app('session.store');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'follow@example.test', 'expires_at' => now()->addHour()->timestamp]);
    $service = app(Following::class);
    $preferences = app(MessagingPreferences::class);
    $service->follow($tenant, $session, 'email', false, null);
    $service->unfollow($tenant, $session, 'email');
    expect($preferences->allowsMarketing($tenant, 'email', 'follow@example.test'))->toBeFalse();
    expect(fn () => $service->follow($tenant, $session, 'email', false, null))->toThrow(Illuminate\Validation\ValidationException::class);
    $service->follow($tenant, $session, 'email', true, null);
    expect($preferences->allowsMarketing($tenant, 'email', 'follow@example.test'))->toBeTrue()
        ->and(CommunityFollower::query()->count())->toBe(1);
    $preferences->disableMarketing($tenant, 'email', 'follow@example.test');
    expect($service->isFollowing($tenant, $session, 'email'))->toBeFalse();
});

it('stops SMS marketing on a confirmed wrong-number report and requires fresh consent after independent resolution', function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$tenant,$owner] = eventHost('consent-hold');
    Artisan::call('community:following-enable', ['tenant' => $tenant->slug]);
    $session = app('session.store');
    $session->put(App\Services\Community\PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    $following = app(Following::class);
    $following->follow($tenant, $session, 'sms', false);
    $reports = app(App\Services\Community\WrongNumberReports::class);
    $report = $reports->report($tenant, $session, 'Messages are intended for someone else.');
    $reports->review($report, $owner, true, 'Independently confirmed the number was reassigned.');
    expect(app(MessagingPreferences::class)->allowsMarketing($tenant, 'sms', '+233241234567'))->toBeFalse();
    expect(fn () => $following->follow($tenant, $session, 'sms', true))->toThrow(Illuminate\Validation\ValidationException::class);
    $reports->resolve($report->fresh(), $owner, 'in_person', 'Independently reverified the current number holder.');
    expect(fn () => $following->follow($tenant, $session, 'sms', false))->toThrow(Illuminate\Validation\ValidationException::class);
    $following->follow($tenant, $session, 'sms', true);
    expect($following->isFollowing($tenant, $session, 'sms'))->toBeTrue();
});
