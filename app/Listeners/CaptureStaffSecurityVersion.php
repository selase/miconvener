<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Http\Middleware\StaffAccountSecurity;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Login;

final class CaptureStaffSecurityVersion
{
    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User || ! request()->hasSession()) {
            return;
        }

        $fresh = User::query()->find($event->user->getAuthIdentifier());
        if (! $fresh || ! in_array($fresh->status, [null, User::STATUS_ACTIVE], true)
            || (int) $fresh->security_version !== (int) $event->user->security_version) {
            \App\Services\Auth\StaffSessions::logoutCurrentDevice();
            request()->session()->forget([
                StaffAccountSecurity::SESSION_KEY, 'google2fa', 'two_factor_setup_secret', 'auth.password_confirmed_at',
                'active_tenant_id', 'original_user', 'original_security_version', 'original_active_tenant_id', 'impersonation_tenant_id',
            ]);
            request()->session()->regenerate(true);

            throw new AuthenticationException;
        }

        request()->session()->forget(['google2fa', 'two_factor_setup_secret', 'auth.password_confirmed_at']);
        request()->session()->put(StaffAccountSecurity::SESSION_KEY, [
            'user_id' => (string) $event->user->getAuthIdentifier(),
            'version' => (int) $event->user->security_version,
        ]);
    }
}
