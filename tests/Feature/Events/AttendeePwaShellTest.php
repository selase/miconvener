<?php

declare(strict_types=1);
use App\Services\Events\PlatformAttendeeVerification;
use Inertia\Testing\AssertableInertia as Assert;

it('provides only an anonymous app shell and public hashed assets for offline boot', function (): void {
    $host = mb_ltrim((string) config('session.domain'), '.');
    $this->get("http://{$host}/my/offline-shell", ['HTTP_HOST' => $host])->assertOk()->assertInertia(fn (Assert $page) => $page->where('app_shell_enabled', true)->where('offline_identity.state', 'unknown_offline')->missing('initialHistory'));
    $assets = $this->getJson("http://{$host}/my/offline-assets", ['HTTP_HOST' => $host])->assertOk()->json('assets');
    expect($assets)->not->toBeEmpty();
    foreach ($assets as $asset) {
        expect($asset)->toStartWith('/build/assets/')->not->toContain('..');
    }
});
it('issues opaque proof-bound cache partitions and never caches signed in HTML', function (): void {
    $service = app(App\Services\Events\AttendeeIdentityContext::class);
    $session = app('session.store');
    expect($service->for($session)['state'])->toBe('signed_out');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'a@example.test', 'expires_at' => now()->addHour()->timestamp]);
    $first = $service->for($session);
    expect($first['owner_key'])->toHaveLength(64)->not->toContain('a@example.test');
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'b@example.test', 'expires_at' => now()->addHour()->timestamp]);
    expect($service->for($session)['owner_key'])->not->toBe($first['owner_key']);
    $host = mb_ltrim((string) config('session.domain'), '.');
    $response = $this->withSession($session->all())->get("http://{$host}/my", ['HTTP_HOST' => $host])->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
it('allows the worker to control both canonical my and nested workspaces', function (): void {
    $host = mb_ltrim((string) config('session.domain'), '.');
    $this->get("http://{$host}/attendee-worker.js", ['HTTP_HOST' => $host])->assertOk()->assertHeader('Service-Worker-Allowed', '/my');
});
