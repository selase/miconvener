<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Services\Events\AttendeePortalRateLimiter;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * A congress puts a thousand people behind one address. Every per-IP limit
 * therefore has to be read as "per hall", and anything that refuses the
 * twenty-first person refuses the room.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    Config::set('attendee_portal.turnstile.site_key', 'site-key');
    Config::set('attendee_portal.turnstile.secret_key', 'secret-key');
});

function turnstilePasses(): void
{
    Http::fake([
        'https://challenges.cloudflare.com/*' => Http::response([
            'success' => true,
            'action' => AttendeePortalRateLimiter::TURNSTILE_ACTION,
            'hostname' => app(TenantHostMatcher::class)->baseDomain(),
        ]),
    ]);
}

test('a hall of delegates gets in, once each has passed the challenge', function (): void {
    turnstilePasses();

    $service = app(PlatformAttendeeVerification::class);
    $hallIp = '41.66.10.1';

    // Well past the old twenty-per-ten-minutes ceiling, which used to refuse
    // the twenty-first person however plainly human they had just proved
    // themselves to be.
    for ($i = 1; $i <= 120; $i++) {
        $result = $service->requestSend("delegate{$i}@example.com", $hallIp, 'solved-token');

        expect($result['status'])->toBe(
            AttendeePortalRateLimiter::RESULT_ALLOWED,
            "delegate {$i} was refused"
        );
    }
});

test('the room is asked to prove itself from the sixth request on', function (): void {
    $service = app(PlatformAttendeeVerification::class);
    $hallIp = '41.66.10.2';

    for ($i = 1; $i <= 5; $i++) {
        expect($service->requestSend("early{$i}@example.com", $hallIp)['status'])
            ->toBe(AttendeePortalRateLimiter::RESULT_ALLOWED);
    }

    // Unchallenged volume still stops here. What changed is that passing the
    // challenge now gets you through rather than merely past one counter.
    expect($service->requestSend('sixth@example.com', $hallIp)['status'])
        ->toBe(AttendeePortalRateLimiter::RESULT_CHALLENGE_REQUIRED);
});

test('a script is still stopped, because it cannot pass the challenge', function (): void {
    Http::fake([
        'https://challenges.cloudflare.com/*' => Http::response(['success' => false]),
    ]);

    $service = app(PlatformAttendeeVerification::class);
    $ip = '198.51.100.7';

    for ($i = 1; $i <= 5; $i++) {
        $service->requestSend("spray{$i}@example.com", $ip);
    }

    expect($service->requestSend('spray6@example.com', $ip, 'forged')['status'])
        ->toBe(AttendeePortalRateLimiter::RESULT_CHALLENGE_FAILED);
});

test('a mailbox is protected however many people share the address they ask from', function (): void {
    turnstilePasses();

    $service = app(PlatformAttendeeVerification::class);
    $hallIp = '41.66.10.3';

    // The per-address caps are the ones that guard a person's inbox, and
    // sharing an IP with a thousand delegates must not loosen them.
    $victim = 'victim@example.com';

    expect($service->requestSend($victim, $hallIp, 'solved-token')['status'])
        ->toBe(AttendeePortalRateLimiter::RESULT_ALLOWED);

    expect($service->requestSend($victim, $hallIp, 'solved-token')['status'])
        ->toBe(AttendeePortalRateLimiter::RESULT_RATE_LIMITED);
});

test('with no challenge configured the burst ceiling still holds', function (): void {
    Config::set('attendee_portal.turnstile.site_key', '');
    Config::set('attendee_portal.turnstile.secret_key', '');

    $service = app(PlatformAttendeeVerification::class);
    $ip = '203.0.113.9';

    for ($i = 1; $i <= 20; $i++) {
        expect($service->requestSend("open{$i}@example.com", $ip)['status'])
            ->toBe(AttendeePortalRateLimiter::RESULT_ALLOWED);
    }

    // Nothing to pass, so the ceiling is all there is.
    expect($service->requestSend('open21@example.com', $ip)['status'])
        ->toBe(AttendeePortalRateLimiter::RESULT_RATE_LIMITED);
});

test('the client behind the edge is the one that gets rate limited, not the balancer', function (): void {
    $host = app(TenantHostMatcher::class)->baseDomain();

    // Every request arrives through Cloudflare and Cloud's balancer. If their
    // address were the one counted, every limit in this file would be one
    // global limit shared by the whole internet.
    $seen = null;

    \Illuminate\Support\Facades\Route::middleware('web')->get('/__probe-ip', function () use (&$seen) {
        $seen = request()->ip();

        return response()->noContent();
    });

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->get("http://{$host}/__probe-ip", [
            'HTTP_HOST' => $host,
            'HTTP_X_FORWARDED_FOR' => '41.66.10.44',
        ]);

    expect($seen)->toBe('41.66.10.44');
});
