<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\OrganisationMemberships;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class AuditOrganisationMemberships extends Command
{
    protected $signature = 'organisations:audit-memberships {--tenant= : Limit to a tenant ID} {--json : Emit safe identifiers and counts as JSON}';

    protected $description = 'Read-only reconciliation of legacy affiliation, memberships and active organisation owners';

    public function handle(OrganisationMemberships $memberships): int
    {
        $rows = [];
        $hasLifecycle = Schema::connection('landlord')->hasColumn('tenant_user', 'status');
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id));
        foreach ($tenants->lazyById(100) as $tenant) {
            $primaryOnly = User::query()->where('tenant_id', $tenant->id)->whereDoesntHave('allTenants', fn ($query) => $query->where('tenants.id', $tenant->id))->pluck('id')->all();
            $ownerCount = $memberships->inTenant($tenant, [], function () use ($tenant, $hasLifecycle): int {
                $users = $tenant->belongsToMany(User::class, 'tenant_user');
                if ($hasLifecycle) {
                    $users->wherePivot('status', 'active');
                }

                return $users->with('roles')->where(fn ($query) => $query->where('users.status', 'active')->orWhereNull('users.status'))->get()->filter(fn (User $user): bool => $user->hasRole('Org Superadmin'))->count();
            });
            $roleOrphans = \Illuminate\Support\Facades\DB::connection('landlord')->table(config('permission.table_names.model_has_roles'))->where('tenant_id', $tenant->id)->where('model_type', User::class)->whereNotIn('model_id', $tenant->allUsers()->pluck('users.id')->map(fn ($id): string => (string) $id))->pluck('model_id')->all();
            $rows[] = ['orphan_role_user_ids' => $roleOrphans, 'tenant_id' => $tenant->id, 'memberships' => TenantMembership::query()->where('tenant_id', $tenant->id)->count(), 'active_owners' => $ownerCount, 'primary_only_user_ids' => $primaryOnly];
        }
        $collisions = User::query()->selectRaw('array_agg(id) AS user_ids')->whereNotNull('email')->groupByRaw('lower(btrim(email))')->havingRaw('COUNT(*) > 1')->get()->pluck('user_ids')->all();
        $this->line(json_encode(['read_only' => true, 'membership_lifecycle_schema_present' => $hasLifecycle, 'email_collision_user_ids' => $collisions, 'organisations' => $rows], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $collisions !== [] || collect($rows)->contains(fn (array $row): bool => $row['active_owners'] === 0 || $row['primary_only_user_ids'] !== [] || $row['orphan_role_user_ids'] !== []) ? self::FAILURE : self::SUCCESS;
    }
}
