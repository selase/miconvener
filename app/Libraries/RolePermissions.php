<?php

declare(strict_types=1);

namespace App\Libraries;

use Spatie\Permission\Models\Role;

final class RolePermissions
{
    /**
     * Assign the permissions
     */
    public static function assign(): void
    {
        self::setSuperadminPermissions();

        self::setOrganizationSuperadminPermissions();

        self::setOrganizationAdminPermissions();
    }

    /**
     * Set superadmin permissions
     */
    public static function setSuperadminPermissions(): void
    {
        $superadmin = Role::findByName('Superadmin');

        $superadmin->givePermissionTo([
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
        ]);
    }

    /**
     * Set organization superadmin permissions
     */
    public static function setOrganizationSuperadminPermissions(): void
    {
        $organizationSuperadmin = Role::findByName('Org Superadmin');

        $organizationSuperadmin->givePermissionTo([
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
        ]);
    }

    /**
     * Set organization admin permissions
     */
    public static function setOrganizationAdminPermissions(): void
    {
        $organizationAdmin = Role::findByName('Org Admin');

        $organizationAdmin->givePermissionTo([
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
        ]);
    }
}
