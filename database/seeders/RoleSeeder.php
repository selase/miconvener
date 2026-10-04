<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Libraries\RolePermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->roles() as $role) {
            $model = Role::query()->updateOrCreate(['name' => $role['name']], $role);

            // A built-in role added after launch has none of its permissions
            // on an existing database: the permissions seeder only grants the
            // ones it creates. Give it the defaults that already exist, once,
            // when the role itself is new.
            if ($model->wasRecentlyCreated) {
                $defaults = RolePermissions::defaults()[$role['name']] ?? [];
                $existing = Permission::query()->whereIn('name', $defaults)->pluck('name')->all();

                if ($existing !== []) {
                    $model->givePermissionTo($existing);
                    app(PermissionRegistrar::class)->forgetCachedPermissions();
                }
            }
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

            [
                'uuid' => Str::uuid(),
                'name' => 'Event Staff',
                'slug' => str()->slug('Event Staff', '_'),
                'guard_name' => 'web',
            ],
        ];
    }
}
