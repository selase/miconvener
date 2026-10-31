<?php

declare(strict_types=1);

use App\Models\CommunityFollower;
use App\Models\CommunitySettings;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

it('enables Following independently of Community and package', function (bool $community, string $package): void {
    $tenant = Tenant::factory()->create();
    $tenant->package()->associate(App\Models\Package::factory()->create(['slug' => $package]));
    $tenant->save();
    $tenant->features()->updateOrCreate(['feature_key' => 'community'], ['enabled' => $community]);
    expect(Artisan::call('community:following-enable', ['tenant' => $tenant->slug]))->toBe(0);
    expect($tenant->fresh()->followingEnabled())->toBeTrue()
        ->and($tenant->fresh()->communityEnabled())->toBe($community);
    Artisan::call('community:following-enable', ['tenant' => $tenant->slug, '--off' => true]);
    expect($tenant->fresh()->followingEnabled())->toBeFalse();
})->with([[false, 'free'], [true, 'free'], [false, 'growth'], [true, 'growth']]);

it('updates only the selected rollout and preserves existing overrides', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $a->features()->create(['feature_key' => 'community_members', 'enabled' => true, 'meta' => ['value' => 99]]);
    Artisan::call('community:following-enable', ['tenant' => $a->slug]);
    Artisan::call('community:following-enable', ['tenant' => $a->slug]);
    expect($a->features()->where('feature_key', 'community_following')->count())->toBe(1)
        ->and($a->features()->where('feature_key', 'community_members')->first()->meta)->toBe(['value' => 99])
        ->and($b->fresh()->followingEnabled())->toBeFalse();
    expect(Artisan::call('community:following-enable', ['tenant' => 'does-not-exist']))->toBe(1);
});

it('defaults public joining to disabled approval and keeps follower addresses encrypted', function (): void {
    $tenant = Tenant::factory()->create();
    $settings = CommunitySettings::for($tenant);
    expect($settings->join_mode)->toBe('approval_required')->and($settings->public_join_enabled)->toBeFalse();
    $follower = CommunityFollower::factory()->create(['tenant_id' => $tenant->id, 'address' => 'private@example.test']);
    expect($follower->address)->toBe('private@example.test')
        ->and($follower->getRawOriginal('address'))->not->toContain('private@example.test');
});
