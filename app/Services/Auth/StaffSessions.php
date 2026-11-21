<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use LogicException;

final class StaffSessions
{
    public static function logoutCurrentDevice(): void
    {
        $guard = Auth::guard('web');
        if (! $guard instanceof SessionGuard) {
            throw new LogicException('Staff authentication requires the native web session guard.');
        }
        $guard->logoutCurrentDevice();
    }
}
