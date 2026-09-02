<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Libraries\RolePermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = array_merge($this->modelPermissions(), $this->defaultPermissions());

        foreach ($permissions as $permission) {
            Permission::query()->updateOrCreate([
                'name' => $permission['name'],
            ], [
                'uuid' => Str::uuid(),
                'name' => $permission['name'],
                'category' => $permission['category'],
            ]);
        }

        /** Assign created permissions to roles */
        RolePermissions::assign();
    }

    /**
     * @return string[]
     */
    public function crudActions(string $name): array
    {
        $action = [];

        $crud = [
            'create',
            'read',
            'update',
            'delete',
        ];

        foreach ($crud as $value) {
            $action[] = $value.' '.$name;
        }

        return $action;
    }

    public function defaultPermissions(): array
    {
        return [
            [
                'uuid' => Str::uuid(),
                'name' => 'access dashboard',
                'guard_name' => 'web',
                'category' => 'dashboard',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'user analytics',
                'guard_name' => 'web',
                'category' => 'analytics',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'read audit-trail',
                'guard_name' => 'web',
                'category' => 'audit-trail',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'impersonate user',
                'guard_name' => 'web',
                'category' => 'user',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'read application health',
                'guard_name' => 'web',
                'category' => 'application-health',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'manage organization settings',
                'guard_name' => 'web',
                'category' => 'tenant',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'manage api keys',
                'guard_name' => 'web',
                'category' => 'api',
            ],
        ];
    }

    /**
     * @return array{name: mixed, category: ('communication' | 'permission' | 'role' | 'setting' | 'team' | 'tenant' | 'user')}[]
     */
    public function modelPermissions(): array
    {
        $data = [];

        $models = ['setting', 'role', 'permission', 'user', 'communication', 'tenant', 'team'];

        foreach ($models as $value) {
            foreach ($this->crudActions($value) as $action) {
                $data[] = [
                    'name' => $action,
                    'category' => $value,
                ];
            }
        }

        return $data;
    }
}
