<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;
use App\Traits\SpatieActivityLogs;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;

final class Role extends SpatieRole
{
    use HasUuid;
    use SpatieActivityLogs;

    /**
     * Role names that are considered "System Roles" and cannot be deleted or modified by tenants.
     */
    public const array SYSTEM_ROLES = [
        'Superadmin',
        'Org Superadmin',
        'Org Admin',
    ];

    protected $connection = 'landlord';

    /**
     * Determine if the role is a system role.
     */
    public function isSystemRole(): bool
    {
        return in_array($this->name, self::SYSTEM_ROLES) && $this->tenant_id === null;
    }

    /**
     * Determine if the role is a custom tenant role.
     */
    public function isCustomRole(): bool
    {
        return $this->tenant_id !== null && ! $this->isSystemRole();
    }

    /**
     * Get the system role this role was cloned from.
     */
    public function clonedFromRole(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cloned_from_role_id');
    }

    /**
     * Get all roles cloned from this role.
     */
    public function clones(): HasMany
    {
        return $this->hasMany(self::class, 'cloned_from_role_id');
    }

    /**
     * Count users currently assigned to this role.
     */
    public function assignedUsersCount(): int
    {
        return (int) DB::connection('landlord')
            ->table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $this->id)
            ->count();
    }
}
