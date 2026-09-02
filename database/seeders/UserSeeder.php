<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

final class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Assign Superadmin Role Globally (tenant_id is null)
        setPermissionsTeamId(null);

        $superAdmin = User::query()->updateOrCreate([
            'email' => 'hiselase@gmail.com',
        ], [
            'first_name' => 'Selase',
            'last_name' => 'Kwawu',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'phone_no' => '233208333151',
            'status' => User::STATUS_ACTIVE,
        ]);
        \Illuminate\Support\Facades\DB::table('model_has_roles')->insert([
            'role_id' => \Spatie\Permission\Models\Role::findByName('Superadmin')->id,
            'model_type' => User::class,
            'model_id' => (string) $superAdmin->id,
            'tenant_id' => null,
        ]);

        // 2. Resolve Purpledot tenant for Organization Superadmin context
        $purpledot = \App\Models\Tenant::where('slug', 'purpledot')->first();

        if ($purpledot) {
            $orgAdmin = User::query()->updateOrCreate([
                'email' => 'dev@wearepurpledot.com',
            ], [
                'first_name' => 'Purpledot',
                'last_name' => 'Developer',
                'email_verified_at' => now(),
                'password' => bcrypt('password'),
                'phone_no' => '233208333151',
                'status' => User::STATUS_ACTIVE,
                'tenant_id' => $purpledot->id,
            ]);

            // attach organization superadmin to its tenant and assign role in that context
            $orgAdmin->tenants()->sync([$purpledot->id]);

            setPermissionsTeamId($purpledot->id);
            \Illuminate\Support\Facades\DB::table('model_has_roles')->insert([
                'role_id' => \Spatie\Permission\Models\Role::findByName('Org Superadmin')->id,
                'model_type' => User::class,
                'model_id' => (string) $orgAdmin->id,
                'tenant_id' => $purpledot->id,
            ]);
            setPermissionsTeamId(null);
        }

        // 3. User for Banned Tenant
        $bannedTenant = \App\Models\Tenant::where('slug', 'banned-tenant')->first();
        if ($bannedTenant) {
            $bannedUser = User::query()->updateOrCreate([
                'email' => 'banned-user@example.com',
            ], [
                'first_name' => 'Banned',
                'last_name' => 'User',
                'email_verified_at' => now(),
                'password' => bcrypt('password'),
                'status' => User::STATUS_ACTIVE,
                'tenant_id' => $bannedTenant->id,
            ]);

            $bannedUser->tenants()->sync([$bannedTenant->id]);

            setPermissionsTeamId($bannedTenant->id);
            \Illuminate\Support\Facades\DB::table('model_has_roles')->insert([
                'role_id' => \Spatie\Permission\Models\Role::findByName('Org Admin')->id,
                'model_type' => User::class,
                'model_id' => (string) $bannedUser->id,
                'tenant_id' => $bannedTenant->id,
            ]);
            setPermissionsTeamId(null);
        }

        // Reset team ID for safe measure
        setPermissionsTeamId(null);
    }
}
