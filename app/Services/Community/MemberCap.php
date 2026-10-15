<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enum\MemberStatusEnum;
use App\Models\Member;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;

final class MemberCap
{
    public function limit(Tenant $tenant): ?int
    {
        $feature = $tenant->features()->where('feature_key', 'community_members')->first();
        if ($feature !== null) {
            if (! $feature->enabled) {
                return 0;
            }
            $meta = $feature->meta ?? [];
            if (($meta['type'] ?? null) === 'limit' && isset($meta['value']) && is_numeric($meta['value'])) {
                $limit = (int) $meta['value'];
                if ($limit === -1) {
                    return null;
                }
                if ($limit >= 0) {
                    return $limit;
                }
            }
        }

        return match ($tenant->package?->slug) {
            'starter' => 500,
            'growth' => 5000,
            'enterprise' => null,
            default => 50,
        };
    }

    public function counted(Tenant $tenant): int
    {
        return Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)
            ->whereIn('status', [MemberStatusEnum::Pending, MemberStatusEnum::Active, MemberStatusEnum::Lapsed])
            ->count();
    }

    /** Call inside withLock(), keeping the check and all additions in its transaction. */
    public function assertRoomFor(Tenant $tenant, int $adding): void
    {
        if ($adding <= 0) {
            return;
        }
        $limit = $this->limit($tenant);
        if ($limit !== null && $this->counted($tenant) + $adding > $limit) {
            $plan = $tenant->package->name ?? 'Free';
            throw new DomainException("Your {$plan} plan includes {$limit} members. Upgrade your plan or mark members who have left as exited before adding more.");
        }
    }

    /**
     * Serialize all member additions, including imports and rejoining members.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withLock(Tenant $tenant, Closure $callback): mixed
    {
        return DB::connection('landlord')->transaction(function () use ($tenant, $callback): mixed {
            DB::connection('landlord')->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['community.members:'.$tenant->id]);

            return $callback();
        });
    }
}
