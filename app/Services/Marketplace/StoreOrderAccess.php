<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Gate;

final class StoreOrderAccess
{
    public function buyer(StoreOrder $order, Session $session): bool
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($session);
        if ($email === null) {
            return false;
        }

        return hash_equals($order->buyer_email, PlatformAttendeeVerification::normalise($email));
    }

    public function staff(StoreOrder $order, Tenant $tenant, User $actor, string $permission): bool
    {
        $current = StoreOrder::query()->with('shop')->find($order->id);
        if ($current === null || $current->tenant_id !== $tenant->id || $current->shop?->tenant_id !== $tenant->id
            || (! User::query()->whereKey($actor->id)->where('tenant_id', $tenant->id)->exists() && ! $actor->tenants()->whereKey($tenant->id)->exists())) {
            return false;
        }
        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);

            return Gate::forUser($actor->fresh())->allows($permission);
        } finally {
            setPermissionsTeamId($team);
        }
    }
}
