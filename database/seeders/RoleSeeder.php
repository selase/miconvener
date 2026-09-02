<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

final class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->roles() as $role) {
            Role::query()->updateOrCreate(['name' => $role['name']], $role);
        }
    }

    public function roles(): array
    {
        return [
            [
                'uuid' => Str::uuid(),
                'name' => 'Superadmin',
                'slug' => str()->slug('Superadmin', '_'),
                'guard_name' => 'web',
            ],

            [
                'uuid' => Str::uuid(),
                'name' => 'Org Superadmin',
                'slug' => str()->slug('Org Superadmin', '_'),
                'guard_name' => 'web',
            ],

            [
                'uuid' => Str::uuid(),
                'name' => 'Org Admin',
                'slug' => str()->slug('Org Admin', '_'),
                'guard_name' => 'web',
            ],
        ];
    }
}
