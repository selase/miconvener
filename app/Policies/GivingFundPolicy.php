<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GivingFund;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Giving\GivingRollout;

final class GivingFundPolicy
{
    public function create(User $user, Tenant $tenant): bool
    {
        return ($user->tenant_id === $tenant->id || $tenant->users()->where('users.id', $user->id)->exists())
            && $user->can('manage giving') && app(GivingRollout::class)->enabled($tenant);
    }

    public function update(User $user, GivingFund $fund): bool
    {
        return $this->create($user, $fund->tenant);
    }
}
