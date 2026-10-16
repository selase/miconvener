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
