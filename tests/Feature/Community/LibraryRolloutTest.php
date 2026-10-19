<?php

declare(strict_types=1);

use App\Models\CommunityLibraryItem;
use App\Models\Tenant;
use App\Services\Community\CommunityLibraryRollout;

it('requires explicit community app and library switches and an entitled plan to publish', function () {
    $tenant = Tenant::factory()->create();
    $rollout = app(CommunityLibraryRollout::class);
    expect($rollout->canPublish($tenant))->toBeFalse();
    foreach (['community', 'community_app', 'community_library', 'community_library_access'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    expect($rollout->canPublish($tenant))->toBeTrue();
    $tenant->features()->where('feature_key', 'community_library_access')->update(['enabled' => false]);
    expect($rollout->canPublish($tenant))->toBeFalse();
    CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id]);
    expect($rollout->available($tenant))->toBeTrue();
    $tenant->features()->where('feature_key', 'community_library')->update(['enabled' => false]);
    expect($rollout->available($tenant))->toBeTrue()->and($rollout->canPublish($tenant))->toBeFalse();
    $tenant->update(['status' => 'banned']);
    expect($rollout->available($tenant))->toBeFalse();
});

it('seeds library for Starter and polls only from Growth without enabling rollout', function () {
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    foreach (['free' => [false, false], 'starter' => [true, false], 'growth' => [true, true], 'enterprise' => [true, true]] as $slug => [$library, $polls]) {
        $package = App\Models\Package::where('slug', $slug)->firstOrFail();
        expect((bool) $package->features()->where('slug', 'community_library_access')->firstOrFail()->pivot->value)->toBe($library)
            ->and((bool) $package->features()->where('slug', 'community_polls_access')->firstOrFail()->pivot->value)->toBe($polls);
    }
});
