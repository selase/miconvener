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

        /*
         * Safe to re-run against a live database, which it must be: seeders do
         * not run on deploy, so a permission added here reaches an existing
         * environment only when this is run there. A permission that already
         * exists keeps its uuid; only its category is brought up to date.
         */
        $created = [];

        foreach ($permissions as $permission) {
            $model = Permission::query()->firstOrNew(['name' => $permission['name']]);

            if (! $model->exists) {
                $created[] = $permission['name'];
            }

            $model->uuid ??= (string) Str::uuid();
            $model->category = $permission['category'];
            $model->save();
        }

        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        /*
         * Only permissions created on this run are granted. A fresh install
         * creates all of them, so every built-in role gets its full set; a
         * re-run on a live database — which happens on every deploy — grants
         * only genuinely new ones, and never hands back a permission an
         * administrator removed from a built-in role on purpose.
         */
        foreach (RolePermissions::assign($created) as $missingRole) {
            $this->command?->warn("Built-in role [{$missingRole}] was not found; its new permissions were not granted.");
        }
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
                'name' => 'manage billing',
                'guard_name' => 'web',
                'category' => 'tenant',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'manage api keys',
                'guard_name' => 'web',
                'category' => 'api',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'review abstract',
                'guard_name' => 'web',
                'category' => 'abstract',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'decide abstract',
                'guard_name' => 'web',
                'category' => 'abstract',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'assign abstract-reviewer',
                'guard_name' => 'web',
                'category' => 'abstract',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'manage scientific-programme',
                'guard_name' => 'web',
                'category' => 'programme',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'manage operation-pillars',
                'guard_name' => 'web',
                'category' => 'operations',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'issue certificates',
                'guard_name' => 'web',
                'category' => 'certificates',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'manage participant-groups',
                'guard_name' => 'web',
                'category' => 'stratification',
            ],
            [
                'uuid' => Str::uuid(),
                'name' => 'manage notification-settings',
                'guard_name' => 'web',
                'category' => 'notifications',
            ],
        ];
    }

    /**
     * @return array{name: mixed, category: ('communication' | 'permission' | 'role' | 'setting' | 'team' | 'tenant' | 'user' | 'event' | 'abstract' | 'programme' | 'operations' | 'certificates' | 'event-operation' | 'certificate' | 'dynamic-form' | 'stratification' | 'notification-rule' | 'notifications')}[]
     */
    public function modelPermissions(): array
    {
        $data = [];

        $models = ['setting', 'role', 'permission', 'user', 'communication', 'tenant', 'team', 'event', 'abstract', 'event-operation', 'certificate', 'dynamic-form', 'notification-rule'];

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
