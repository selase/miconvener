<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

final class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Tenant::query()->updateOrCreate(['slug' => 'purpledot'], [
            'name' => 'Purpledot',
            'email' => 'hello@wearepurpledot.com',
            'status' => \App\Enum\TenantStatusEnum::ACTIVE,
            'isolation_mode' => 'shared',
            'db_driver' => 'pgsql',
            'country' => 'Ghana',
            'city' => 'Accra',
            'state' => 'Greater Accra',
        ]);

        Tenant::query()->updateOrCreate(['slug' => 'ugmc'], [
            'name' => 'UGMC',
            'email' => 'support@ugmedicalcentre.org',
            'status' => \App\Enum\TenantStatusEnum::ACTIVE,
            'isolation_mode' => 'db_per_tenant',
            'db_driver' => 'pgsql',
            'country' => 'Ghana',
            'city' => 'Accra',
            'state' => 'Greater Accra',
        ]);

        Tenant::query()->updateOrCreate(['slug' => 'banned-tenant'], [
            'name' => 'Banned Corp',
            'email' => 'banned@example.com',
            'status' => \App\Enum\TenantStatusEnum::BANNED,
            'isolation_mode' => 'shared',
            'db_driver' => 'pgsql',
            'country' => 'Global',
            'city' => 'Banned City',
            'state' => 'Banned State',
        ]);
    }
}
