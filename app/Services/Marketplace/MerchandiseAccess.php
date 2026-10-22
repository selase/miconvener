<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enum\MemberStatusEnum;
use App\Models\Member;
use App\Models\StoreListing;
use App\Models\User;
use App\Scopes\MerchandiseVisibilityScope;
use App\Scopes\TenantScope;
use App\Services\Community\MemberAccess;
use App\Services\Community\MemberIdentity;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Gate;

final class MerchandiseAccess
{
    public function canRead(StoreListing $listing, Session $session): bool
    {
        $current = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->with(['merchandise', 'shop.tenant'])
            ->where('listing_kind', StoreListing::KIND_MERCHANDISE)->find($listing->id);
        if ($current === null || $current->merchandise === null || $current->merchandise->archived_at !== null || blank($current->merchandise->collection_instructions)
            || ! $current->isPublished() || $current->taken_down_at !== null
            || ! app(CommerceCatalogRollout::class)->enabled($current->shop->tenant)
            || ! $current->variants()->where('is_active', true)->exists()) {
            return false;
        }
        if ($current->merchandise->visibility === 'public') {
            return true;
        }
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($session);
        $email = app(MemberIdentity::class)->normaliseEmail($email);
        if ($email === null) {
            return false;
        }
        $members = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $current->shop->tenant_id)
            ->where('status', MemberStatusEnum::Active)->whereRaw('lower(trim(email)) = ?', [$email])->get();

        return $members->contains(fn (Member $member): bool => app(MemberAccess::class)->role($session, $member) === 'self');
    }

    public function canManage(StoreListing $listing, User $actor): bool
    {
        $current = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->with('shop.tenant')
            ->where('listing_kind', StoreListing::KIND_MERCHANDISE)->find($listing->id);

        if ($current === null || ! app(CommerceCatalogRollout::class)->enabled($current->shop->tenant)
            || (! User::query()->whereKey($actor->id)->where('tenant_id', $current->shop->tenant_id)->exists() && ! $actor->tenants()->whereKey($current->shop->tenant_id)->exists())) {
            return false;
        }
        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($current->shop->tenant_id);

            return Gate::forUser($actor->fresh())->allows('manage store catalog');
        } finally {
            setPermissionsTeamId($team);
        }
    }
}
