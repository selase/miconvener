<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\PlatformAttendeeAccessCode;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
    Queue::fake();
});

if (! function_exists('Tests\Feature\Events\platformHost')) {
    function platformHost(): string
    {
        return mb_ltrim((string) config('session.domain'), '.');
    }
}

test('page renders MyPortal on platform host with optional presentation organiser context', function (): void {
    $host = platformHost();
    $tenant = Tenant::factory()->create([
        'name' => 'African Science Forum',
        'slug' => 'asf',
        'isolation_mode' => 'shared',
    ]);

    // Unverified with organiser query param
    $this->get("http://{$host}/my?organiser=asf", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/AttendeePortal/MyPortal')
            ->where('organiser.name', 'African Science Forum')
            ->where('organiser.slug', 'asf')
            ->where('verifiedEmail', null)
        );

    // Verified session
    $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'ama@example.com',
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/AttendeePortal/MyPortal')
            ->where('verifiedEmail', 'am•@example.com')
        );
});

test('send validates email and dispatches code request', function (): void {
    $host = platformHost();

    // Invalid email
    $this->postJson("http://{$host}/my/verify/send", ['email' => 'not-an-email'], ['HTTP_HOST' => $host])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    // Valid email
    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@example.com'], ['HTTP_HOST' => $host])
        ->assertStatus(202)
        ->assertJson(['message' => 'A sign-in code is on its way to your email.']);
});

test('send returns 428 when Turnstile challenge is required and 422 when invalid', function (): void {
    Config::set('attendee_portal.turnstile.site_key', 'site-key-abc');
    Config::set('attendee_portal.turnstile.secret_key', 'secret-key-xyz');

    $host = platformHost();
    $ip = '10.20.30.40';

    // Simulate 5 attempts from this IP to hit challenge threshold
    $ipHash = hash('sha256', $ip);
    for ($i = 1; $i <= 5; $i++) {
        \Illuminate\Support\Facades\RateLimiter::hit("portal:rl:ip:burst:{$ipHash}", 600);
    }

    // 6th attempt without token returns 428
    $this->postJson("http://{$host}/my/verify/send", ['email' => 'user6@example.com'], [
        'HTTP_HOST' => $host,
        'REMOTE_ADDR' => $ip,
    ])
        ->assertStatus(428)
        ->assertJson([
            'challenge_required' => true,
            'site_key' => 'site-key-abc',
        ]);

    // Fake Cloudflare Turnstile sequence: first fails, second succeeds
    Http::fakeSequence('https://challenges.cloudflare.com/*')
        ->push(['success' => false])
        ->push([
            'success' => true,
            'action' => \App\Services\Events\AttendeePortalRateLimiter::TURNSTILE_ACTION,
            'hostname' => app(\App\Services\Tenancy\TenantHostMatcher::class)->baseDomain(),
        ]);

    $this->postJson("http://{$host}/my/verify/send", [
        'email' => 'user6@example.com',
        'turnstile_token' => 'invalid-token',
    ], [
        'HTTP_HOST' => $host,
        'REMOTE_ADDR' => $ip,
    ])
        ->assertStatus(422)
        ->assertJson([
            'challenge_required' => true,
            'site_key' => 'site-key-abc',
        ]);

    $this->postJson("http://{$host}/my/verify/send", [
        'email' => 'user7@example.com',
        'turnstile_token' => 'valid-token',
    ], [
        'HTTP_HOST' => $host,
        'REMOTE_ADDR' => $ip,
    ])
        ->assertStatus(202);
});

test('confirm verifies code and establishes platform proof', function (): void {
    $host = platformHost();

    PlatformAttendeeAccessCode::factory()->create([
        'email_normalized' => 'attendee@example.com',
        'code_hash' => Hash::make('482913'),
        'consumed_at' => null,
        'attempts' => 0,
        'expires_at' => now()->addMinutes(15),
    ]);

    // Mismatched code returns 422
    $this->postJson("http://{$host}/my/verify/confirm", [
        'email' => 'attendee@example.com',
        'code' => '000000',
    ], ['HTTP_HOST' => $host])
        ->assertStatus(422)
        ->assertJson(['message' => "That code didn't match. Check it, or ask for a new one."]);

    // Matching code returns 200 with masked email
    $this->postJson("http://{$host}/my/verify/confirm", [
        'email' => 'attendee@example.com',
        'code' => '482913',
    ], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['email' => 'at••••••@example.com'])
        ->assertSessionHas(PlatformAttendeeVerification::SESSION_KEY);
});

test('forget clears platform verification session', function (): void {
    $host = platformHost();

    $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'attendee@example.com',
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->postJson("http://{$host}/my/verify/forget", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['message' => 'Signed out.'])
        ->assertSessionMissing(PlatformAttendeeVerification::SESSION_KEY);
});

test('session endpoint reports verified email behind platform_attendee_verified middleware', function (): void {
    $host = platformHost();

    // Unverified returns 401
    $this->getJson("http://{$host}/my/session", ['HTTP_HOST' => $host])
        ->assertUnauthorized();

    // Verified returns masked email
    $this->withSession([
        PlatformAttendeeVerification::SESSION_KEY => [
            'email' => 'attendee@example.com',
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(12)->getTimestamp(),
        ],
    ])->getJson("http://{$host}/my/session", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['email' => 'at••••••@example.com']);
});
