<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\GivingFund;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

final class GivingScenario
{
    /** @param array<string, mixed> $fundAttributes
     * @return array{Tenant, User, GivingFund}
     */
    public static function make(array $fundAttributes = []): array
    {
        Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
        Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
        $tenant = Tenant::factory()->create();
        $tenant->features()->create(['feature_key' => 'community', 'enabled' => true]);
        $tenant->features()->create(['feature_key' => 'community_giving', 'enabled' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        setPermissionsTeamId($tenant->id);
        $tenant->users()->attach($user->id);
        $user->givePermissionTo(['manage giving', 'read finance']);
        $fund = GivingFund::factory()->create(['tenant_id' => $tenant->id, 'state' => 'open', ...$fundAttributes]);

        return [$tenant, $user, $fund];
    }
}
