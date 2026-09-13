<?php

declare(strict_types=1);

namespace App\Libraries;

use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;

final class RolePermissions
{
    /**
     * Permissions each built-in role receives, keyed by role name.
     *
     * @return array<string, array<int, string>>
     */
    public static function defaults(): array
    {
        return [
            'Superadmin' => self::superadminPermissions(),
            'Org Superadmin' => self::organizationSuperadminPermissions(),
            'Org Admin' => self::organizationAdminPermissions(),
        ];
    }

    /**
     * Grant the built-in roles their default permissions.
     *
     * Pass $only to grant just those permissions — the seeder passes the ones
     * it has just created, so a re-run on a live database adds new permissions
     * without handing back one an administrator deliberately removed from a
     * built-in role. Omit it to grant every default.
     *
     * A built-in role that cannot be found (renamed in the admin panel, say)
     * is skipped and reported rather than thrown, because this runs during
     * deploy and must not fail it.
     *
     * @param  array<int, string>|null  $only
     * @return array<int, string> Names of the roles that could not be found.
     */
    public static function assign(?array $only = null): array
    {
        $missingRoles = [];

        foreach (self::defaults() as $roleName => $permissions) {
            $toGrant = $only === null ? $permissions : array_values(array_intersect($permissions, $only));

            if ($toGrant === []) {
                continue;
            }

            try {
                Role::findByName($roleName)->givePermissionTo($toGrant);
            } catch (RoleDoesNotExist) {
                $missingRoles[] = $roleName;
            }
        }

        return $missingRoles;
    }

    /**
     * Default permissions for the built-in Superadmin role.
     *
     * @return array<int, string>
     */
    private static function superadminPermissions(): array
    {
        return [
            'create setting',
            'read setting',
            'update setting',
            'delete setting',
            'create role',
            'read role',
            'update role',
            'delete role',
            'create permission',
            'read permission',
            'update permission',
            'delete permission',
            'create user',
            'read user',
            'update user',
            'delete user',
            'create communication',
            'read communication',
            'update communication',
            'delete communication',
            'access dashboard',
            'user analytics',
            'read audit-trail',
            'create tenant',
            'read tenant',
            'update tenant',
            'delete tenant',
            'create team',
            'read team',
            'update team',
            'delete team',
            'impersonate user',
            'read application health',
            'manage organization settings',
            'manage api keys',
            'create event',
            'read event',
            'update event',
            'delete event',
            'create abstract',
            'read abstract',
            'update abstract',
            'delete abstract',
            'review abstract',
            'decide abstract',
            'assign abstract-reviewer',
            'manage scientific-programme',
            'create event-operation',
            'read event-operation',
            'update event-operation',
            'delete event-operation',
            'manage operation-pillars',
            'create certificate',
            'read certificate',
            'update certificate',
            'delete certificate',
            'issue certificates',
            'create dynamic-form',
            'read dynamic-form',
            'update dynamic-form',
            'delete dynamic-form',
            'manage participant-groups',
            'create notification-rule',
            'read notification-rule',
            'update notification-rule',
            'delete notification-rule',
            'manage notification-settings',
        ];
    }

    /**
     * Default permissions for the built-in Org Superadmin role.
     *
     * @return array<int, string>
     */
    private static function organizationSuperadminPermissions(): array
    {
        return [
            'create communication',
            'read communication',
            'update communication',
            'delete communication',
            'access dashboard',
            'create user',
            'read user',
            'update user',
            'delete user',
            'manage organization settings',
            'manage api keys',
            'create role',
            'read role',
            'update role',
            'delete role',
            'create event',
            'read event',
            'update event',
            'delete event',
            'create abstract',
            'read abstract',
            'update abstract',
            'delete abstract',
            'review abstract',
            'decide abstract',
            'assign abstract-reviewer',
            'manage scientific-programme',
            'create event-operation',
            'read event-operation',
            'update event-operation',
            'delete event-operation',
            'manage operation-pillars',
            'create certificate',
            'read certificate',
            'update certificate',
            'delete certificate',
            'issue certificates',
            'create dynamic-form',
            'read dynamic-form',
            'update dynamic-form',
            'delete dynamic-form',
            'manage participant-groups',
            'create notification-rule',
            'read notification-rule',
            'update notification-rule',
            'delete notification-rule',
            'manage notification-settings',
        ];
    }

    /**
     * Default permissions for the built-in Org Admin role.
     *
     * @return array<int, string>
     */
    private static function organizationAdminPermissions(): array
    {
        return [
            'create communication',
            'read communication',
            'update communication',
            'delete communication',
            'access dashboard',
            'manage organization settings',
            'manage api keys',
            'create role',
            'read role',
            'update role',
            'delete role',
            'create user',
            'read user',
            'update user',
            'delete user',
            'create event',
            'read event',
            'update event',
            'delete event',
            'create abstract',
            'read abstract',
            'update abstract',
            'delete abstract',
            'review abstract',
            'decide abstract',
            'assign abstract-reviewer',
            'manage scientific-programme',
            'create event-operation',
            'read event-operation',
            'update event-operation',
            'delete event-operation',
            'manage operation-pillars',
            'create certificate',
            'read certificate',
            'update certificate',
            'delete certificate',
            'issue certificates',
            'create dynamic-form',
            'read dynamic-form',
            'update dynamic-form',
            'delete dynamic-form',
            'manage participant-groups',
            'create notification-rule',
            'read notification-rule',
            'update notification-rule',
            'delete notification-rule',
            'manage notification-settings',
        ];
    }
}
