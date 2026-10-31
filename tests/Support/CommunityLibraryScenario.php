<?php

declare(strict_types=1);

use App\Models\CommunityLibraryItem;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\PlatformAttendeeVerification;

/** @return array{Tenant, Member, CommunityLibraryItem} */
function libraryReader(): array
{
    $tenant = Tenant::factory()->create();
    foreach (['community', 'community_app', 'community_library', 'community_library_access'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $type = MembershipType::factory()->create(['tenant_id' => $tenant->id, 'amount' => null, 'period' => null]);
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'membership_type_id' => $type->id]);
    $item = CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id]);
    app('session.store')->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]);

    return [$tenant, $member, $item];
}

/** @return array{Tenant,User} */
function libraryStaff(): array
{
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$tenant, $user] = eventHost('library-host');
    makeTenantOwner($user, $tenant);
    foreach (['community', 'community_app', 'community_library', 'community_library_access'] as $key) {
        $tenant->features()->updateOrCreate(['feature_key' => $key], ['enabled' => true]);
    }

    return [$tenant, $user];
}
