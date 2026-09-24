<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Http\Middleware\EnsurePlatformAttendeeVerified;
use App\Jobs\Events\SendPlatformAttendeeAccessCode;
use App\Mail\Events\PlatformAttendeeAccessCodeMail;
use App\Models\PlatformAttendeeAccessCode;
use App\Services\Events\AttendeePortalRateLimiter;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

function turnstileHost(): string
{
    return app(\App\Services\Tenancy\TenantHostMatcher::class)->baseDomain();
}

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
    Queue::fake();
});

test('requestSend normalizes email and dispatches asynchronous send job', function (): void {
    $service = app(PlatformAttendeeVerification::class);

    $result = $service->requestSend('  Ama.Conference@example.com  ', '192.168.1.1');

    expect($result['status'])->toBe(AttendeePortalRateLimiter::RESULT_ALLOWED);

    Queue::assertPushed(SendPlatformAttendeeAccessCode::class, function (SendPlatformAttendeeAccessCode $job): bool {
        return $job->email === 'ama.conference@example.com';
    });
});

test('requestSend enforces 60-second cooldown per normalized email', function (): void {
    $service = app(PlatformAttendeeVerification::class);

    $first = $service->requestSend('attendee@example.com', '192.168.1.1');
    expect($first['status'])->toBe(AttendeePortalRateLimiter::RESULT_ALLOWED);

    $second = $service->requestSend('ATTENDEE@example.com', '192.168.1.2');
    expect($second['status'])->toBe(AttendeePortalRateLimiter::RESULT_RATE_LIMITED);
});

test('requestSend enforces hourly and daily limits per email across distributed IPs', function (): void {
    $service = app(PlatformAttendeeVerification::class);
    $emailHash = hash('sha256', 'rate-test@example.com');

    // 5 per hour limit: send 5 times with cooldown cleared between them
    for ($i = 1; $i <= 5; $i++) {
        \Illuminate\Support\Facades\RateLimiter::clear("portal:rl:email:cooldown:{$emailHash}");
        $res = $service->requestSend('rate-test@example.com', "10.0.0.{$i}");
        expect($res['status'])->toBe(AttendeePortalRateLimiter::RESULT_ALLOWED);
    }

    // 6th attempt in the same hour is blocked by hourly cap even with cooldown cleared
    \Illuminate\Support\Facades\RateLimiter::clear("portal:rl:email:cooldown:{$emailHash}");
    $sixth = $service->requestSend('rate-test@example.com', '10.0.0.99');
    expect($sixth['status'])->toBe(AttendeePortalRateLimiter::RESULT_RATE_LIMITED);
});

test('requestSend triggers Turnstile step-up challenge on 6th attempt from one IP in 10 minutes', function (): void {
    Config::set('attendee_portal.turnstile.site_key', 'test-site-key-123');
    Config::set('attendee_portal.turnstile.secret_key', 'test-secret-key-xyz');

    $service = app(PlatformAttendeeVerification::class);

    // First 5 attempts from same IP with different emails pass without challenge
    for ($i = 1; $i <= 5; $i++) {
        $res = $service->requestSend("user{$i}@example.com", '172.16.0.50');
        expect($res['status'])->toBe(AttendeePortalRateLimiter::RESULT_ALLOWED);
    }

    // 6th attempt without Turnstile token triggers challenge_required with site_key
    $challenged = $service->requestSend('user6@example.com', '172.16.0.50');
    expect($challenged['status'])->toBe(AttendeePortalRateLimiter::RESULT_CHALLENGE_REQUIRED)
        ->and($challenged['site_key'])->toBe('test-site-key-123');

    // Sequential fake for failing then passing verification
    Http::fakeSequence('https://challenges.cloudflare.com/*')
        ->push(['success' => false])
        ->push(['success' => true, 'action' => AttendeePortalRateLimiter::TURNSTILE_ACTION, 'hostname' => turnstileHost()]);

    // 6th attempt with failing Turnstile token triggers challenge_failed
    $failedChallenge = $service->requestSend('user6@example.com', '172.16.0.50', 'invalid-token');
    expect($failedChallenge['status'])->toBe(AttendeePortalRateLimiter::RESULT_CHALLENGE_FAILED);

    // Subsequent attempt with successful Turnstile token is allowed
    $passedChallenge = $service->requestSend('user7@example.com', '172.16.0.50', 'valid-token');
    expect($passedChallenge['status'])->toBe(AttendeePortalRateLimiter::RESULT_ALLOWED);
});

test('requestSend blocks after IP burst limit of 20 attempts in 10 minutes even with Turnstile', function (): void {
    Config::set('attendee_portal.turnstile.site_key', 'test-site-key-123');
    Config::set('attendee_portal.turnstile.secret_key', 'test-secret-key-xyz');

    Http::fake([
        'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
            'success' => true,
            'action' => AttendeePortalRateLimiter::TURNSTILE_ACTION,
            'hostname' => turnstileHost(),
        ]),
    ]);

    $service = app(PlatformAttendeeVerification::class);
    $ip = '172.16.0.99';

    // Simulate 20 attempts from the IP
    $ipHash = hash('sha256', $ip);
    for ($i = 1; $i <= 20; $i++) {
        \Illuminate\Support\Facades\RateLimiter::hit("portal:rl:ip:burst:{$ipHash}", 600);
    }

    $blocked = $service->requestSend('user21@example.com', $ip, 'valid-token');
    expect($blocked['status'])->toBe(AttendeePortalRateLimiter::RESULT_RATE_LIMITED);
});

test('dispatchCode consumes previous unconsumed codes and sends platform-branded email', function (): void {
    $service = app(PlatformAttendeeVerification::class);

    $oldCode = PlatformAttendeeAccessCode::factory()->create([
        'email_normalized' => 'active@example.com',
        'consumed_at' => null,
    ]);

    $service->dispatchCode('active@example.com');

    // Previous code must be consumed
    expect($oldCode->fresh()->consumed_at)->not->toBeNull();

    // New code must exist
    $latestCode = PlatformAttendeeAccessCode::query()
        ->where('email_normalized', 'active@example.com')
        ->whereNull('consumed_at')
        ->first();

    expect($latestCode)->not->toBeNull()
        ->and($latestCode->attempts)->toBe(0)
        ->and($latestCode->expires_at->isFuture())->toBeTrue();

    // Mail must be sent
    Mail::assertSent(PlatformAttendeeAccessCodeMail::class, function (PlatformAttendeeAccessCodeMail $mail) use ($latestCode): bool {
        return $mail->hasTo('active@example.com')
            && $mail->envelope()->subject === 'Your MiConvener sign-in code'
            && $latestCode->matches($mail->code);
    });
});

test('confirm consumes code, regenerates session, and stores 12-hour platform proof', function (): void {
    $service = app(PlatformAttendeeVerification::class);
    $session = app('session.store');
    $session->start();
    $initialSessionId = $session->getId();

    $code = '654321';
    $accessCode = PlatformAttendeeAccessCode::factory()->create([
        'email_normalized' => 'verified@example.com',
        'code_hash' => Hash::make($code),
        'consumed_at' => null,
        'attempts' => 0,
        'expires_at' => now()->addMinutes(15),
    ]);

    $confirmed = $service->confirm($session, 'verified@example.com', $code, '127.0.0.1');

    expect($confirmed)->toBeTrue()
        ->and($accessCode->fresh()->consumed_at)->not->toBeNull()
        ->and($session->getId())->not->toBe($initialSessionId)
        ->and($service->verifiedEmail($session))->toBe('verified@example.com');

    $marker = $session->get(PlatformAttendeeVerification::SESSION_KEY);
    expect($marker)->toBeArray()
        ->and($marker['email'])->toBe('verified@example.com')
        ->and($marker['expires_at'])->toBeGreaterThan(now()->addHours(11)->getTimestamp());
});

test('confirm rejects incorrect code and atomically increments attempts', function (): void {
    $service = app(PlatformAttendeeVerification::class);
    $session = app('session.store');
    $session->start();

    $accessCode = PlatformAttendeeAccessCode::factory()->create([
        'email_normalized' => 'attempts@example.com',
        'code_hash' => Hash::make('123456'),
        'consumed_at' => null,
        'attempts' => 0,
        'expires_at' => now()->addMinutes(15),
    ]);

    $confirmed = $service->confirm($session, 'attempts@example.com', '999999', '127.0.0.1');

    expect($confirmed)->toBeFalse()
        ->and($accessCode->fresh()->attempts)->toBe(1)
        ->and($accessCode->fresh()->consumed_at)->toBeNull()
        ->and($service->verifiedEmail($session))->toBeNull();
});

test('confirm rejects code after 5 failed attempts', function (): void {
    $service = app(PlatformAttendeeVerification::class);
    $session = app('session.store');
    $session->start();

    $accessCode = PlatformAttendeeAccessCode::factory()->create([
        'email_normalized' => 'exhausted@example.com',
        'code_hash' => Hash::make('123456'),
        'consumed_at' => null,
        'attempts' => 4,
        'expires_at' => now()->addMinutes(15),
    ]);

    // 5th attempt (fails)
    expect($service->confirm($session, 'exhausted@example.com', '000000', '127.0.0.1'))->toBeFalse();
    expect($accessCode->fresh()->attempts)->toBe(5);

    // 6th attempt with CORRECT code is now rejected because attempts >= 5
    expect($service->confirm($session, 'exhausted@example.com', '123456', '127.0.0.1'))->toBeFalse();
    expect($accessCode->fresh()->consumed_at)->toBeNull();
});

test('confirm rejects expired code and pays dummy hash comparison cost', function (): void {
    $service = app(PlatformAttendeeVerification::class);
    $session = app('session.store');
    $session->start();

    PlatformAttendeeAccessCode::factory()->create([
        'email_normalized' => 'expired@example.com',
        'code_hash' => Hash::make('123456'),
        'consumed_at' => null,
        'attempts' => 0,
        'expires_at' => now()->subMinute(),
    ]);

    $confirmed = $service->confirm($session, 'expired@example.com', '123456', '127.0.0.1');
    expect($confirmed)->toBeFalse();
});

test('verifiedEmail returns null and clears session when proof has expired', function (): void {
    $service = app(PlatformAttendeeVerification::class);
    $session = app('session.store');
    $session->start();

    $session->put(PlatformAttendeeVerification::SESSION_KEY, [
        'email' => 'expired-session@example.com',
        'verified_at' => now()->subHours(13)->getTimestamp(),
        'expires_at' => now()->subHour()->getTimestamp(),
    ]);

    expect($service->verifiedEmail($session))->toBeNull()
        ->and($session->has(PlatformAttendeeVerification::SESSION_KEY))->toBeFalse();
});

test('forget clears platform verification from session', function (): void {
    $service = app(PlatformAttendeeVerification::class);
    $session = app('session.store');
    $session->start();

    $session->put(PlatformAttendeeVerification::SESSION_KEY, [
        'email' => 'active-session@example.com',
        'verified_at' => now()->getTimestamp(),
        'expires_at' => now()->addHours(12)->getTimestamp(),
    ]);

    expect($service->verifiedEmail($session))->toBe('active-session@example.com');

    $service->forget($session);

    expect($service->verifiedEmail($session))->toBeNull();
});

test('EnsurePlatformAttendeeVerified middleware protects routes', function (): void {
    Route::middleware(['web', EnsurePlatformAttendeeVerified::class])->get('/test/protected-attendee', function (Request $request) {
        return response()->json(['email' => $request->attributes->get('attendee_email')]);
    });

    // Unauthenticated JSON request returns 401
    $this->getJson('/test/protected-attendee')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Confirm your email address to see this.']);

    // Unauthenticated web request redirects to attendee.my
    $this->get('/test/protected-attendee')
        ->assertRedirect(route('attendee.my'));

    // Authenticated request passes and receives attendee_email
    $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'ama@example.com',
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->getJson('/test/protected-attendee')
        ->assertOk()
        ->assertJson(['email' => 'ama@example.com']);
});

test('a genuine token is still refused when it was not solved for this form or this site', function (): void {
    Config::set('attendee_portal.turnstile.site_key', 'test-site-key-123');
    Config::set('attendee_portal.turnstile.secret_key', 'test-secret-key-xyz');

    $limiter = app(AttendeePortalRateLimiter::class);

    // Cloudflare says all three tokens are real. Only the last was minted for
    // this form, on this site.
    Http::fakeSequence('https://challenges.cloudflare.com/*')
        ->push(['success' => true, 'action' => 'newsletter-signup', 'hostname' => turnstileHost()])
        ->push(['success' => true, 'action' => AttendeePortalRateLimiter::TURNSTILE_ACTION, 'hostname' => 'phishing.example.com'])
        ->push(['success' => true, 'action' => AttendeePortalRateLimiter::TURNSTILE_ACTION, 'hostname' => turnstileHost()]);

    expect($limiter->verifyTurnstileToken('real-token', '10.1.1.1'))->toBeFalse()
        ->and($limiter->verifyTurnstileToken('real-token', '10.1.1.1'))->toBeFalse()
        ->and($limiter->verifyTurnstileToken('real-token', '10.1.1.1'))->toBeTrue();
});

test('the challenge response tells the widget which action to sign', function (): void {
    Config::set('attendee_portal.turnstile.site_key', 'test-site-key-123');

    $service = app(PlatformAttendeeVerification::class);

    for ($i = 1; $i <= 5; $i++) {
        $service->requestSend("action-probe{$i}@example.com", '172.16.9.9');
    }

    $challenged = $service->requestSend('action-probe6@example.com', '172.16.9.9');

    expect($challenged['status'])->toBe(AttendeePortalRateLimiter::RESULT_CHALLENGE_REQUIRED)
        ->and($challenged['action'])->toBe(AttendeePortalRateLimiter::TURNSTILE_ACTION);
});
