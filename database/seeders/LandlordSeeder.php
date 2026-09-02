<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class LandlordSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->updateOrCreate(['slug' => 'acme'], [
            'name' => 'Acme Corp',
            'status' => \App\Enum\TenantStatusEnum::ACTIVE,
            'isolation_mode' => 'shared',
            'db_driver' => 'pgsql',
        ]);

        $user = User::query()->updateOrCreate(['email' => 'admin@acme.com'], [
            'first_name' => 'System',
            'last_name' => 'Admin',
            'password' => Hash::make('password'),
            'status' => 'active',
            'tenant_id' => $tenant->id,
        ]);

        $tenant->users()->syncWithoutDetaching([$user->id => ['role_hint' => 'admin']]);
    }
}
