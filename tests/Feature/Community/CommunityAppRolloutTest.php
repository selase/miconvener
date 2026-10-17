<?php

declare(strict_types=1);

use App\Models\Member;
use App\Models\Tenant;
use App\Services\Community\CommunityHistory;
use App\Services\Events\PlatformAttendeeVerification;

it('keeps the app off unless an explicit pilot context or an authorised organisation enables it', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'app-pilot']);
    $session = app('session.store');
    $history = app(CommunityHistory::class);
    expect($history->usesAppShell($session, null))->toBeFalse();
    $tenant->features()->create(['feature_key' => 'community_app', 'enabled' => true]);
    expect($history->usesAppShell($session, 'app-pilot'))->toBeTrue()
        ->and($history->usesAppShell($session, null))->toBeFalse();
});

it('enables and disables only the requested organisation idempotently', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'app-command']);
    $other = Tenant::factory()->create(['slug' => 'app-other']);
    $this->artisan('community:enable-app app-command')->assertSuccessful();
    $this->artisan('community:enable-app app-command')->assertSuccessful();
    expect($tenant->features()->where('feature_key', 'community_app')->count())->toBe(1)
        ->and($other->features()->where('feature_key', 'community_app')->exists())->toBeFalse();
    $this->artisan('community:enable-app app-command --off')->assertSuccessful();
    expect($tenant->fresh()->featureEnabled('community_app'))->toBeFalse();
});

it('finds an authorised pilot beyond the first page and never infers it from another phone contact', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'zzz-pilot']);
    $tenant->features()->create(['feature_key' => 'community_app', 'enabled' => true]);
    Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'owner@example.test']);
    $session = app('session.store');
    for ($i = 0; $i < 21; $i++) {
        $earlier = Tenant::factory()->create(['name' => 'AAA '.$i]);
        Member::factory()->create(['tenant_id' => $earlier->id, 'email' => 'owner@example.test']);
    }
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'owner@example.test', 'verified_at' => now()->timestamp, 'expires_at' => now()->addHour()->timestamp]);
    expect(app(CommunityHistory::class)->usesAppShell($session, null))->toBeTrue();
    $session->put(PlatformAttendeeVerification::SESSION_KEY.'.email', 'other@example.test');
    expect(app(CommunityHistory::class)->usesAppShell($session, null))->toBeFalse();
    $host = mb_ltrim((string) config('session.domain'), '.');
    $this->get("http://{$host}/my?organiser=zzz-pilot", ['HTTP_HOST' => $host])->assertOk()->assertInertia(fn ($page) => $page->where('app_shell_enabled', true));
});
