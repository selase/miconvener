<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;
use Spatie\Permission\Models\Permission as SpatiePermission;

final class Permission extends SpatiePermission
{
    use HasUuid;

    /**
     * List of permissions that an Org Superadmin is allowed to assign to their custom roles.
     */
    public const array TENANT_SAFE = [
        'access dashboard',
        'read user',
        'create user',
        'update user',
        'delete user',
        'read role',
        'create role',
        'update role',
        'delete role',
        'read team',
        'create team',
        'update team',
        'delete team',
        'read communication',
        'create communication',
        'update communication',
        'delete communication',
        'manage organization settings',
    ];

    protected $connection = 'landlord';

    public function features()
    {
        return $this->belongsToMany(Feature::class, 'feature_permissions');
    }
}
