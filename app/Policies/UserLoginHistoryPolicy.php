<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserLoginHistory;
use Illuminate\Auth\Access\HandlesAuthorization;

final class UserLoginHistoryPolicy extends BasePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, UserLoginHistory $history): bool
    {
        return $this->belongsToActiveTenant($history);
    }
}
