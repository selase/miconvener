<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class TwoFactorSettings
{
    public function disable(User $actor, #[SensitiveParameter] string $password): void
    {
        $actor->getConnection()->transaction(function () use ($actor, $password): void {
            $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [null, User::STATUS_ACTIVE], true)
                || (int) $locked->security_version !== (int) $actor->security_version) {
                throw new AuthenticationException;
            }
            abort_if($locked->tenants()->where('require_2fa', true)->exists(), 403);
            if (! Hash::check($password, $locked->password)) {
                throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
            }
            $locked->update([
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
            ]);
            $actor->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $actor, #[SensitiveParameter] string $password): array
    {
        return $actor->getConnection()->transaction(function () use ($actor, $password): array {
            $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [null, User::STATUS_ACTIVE], true)
                || (int) $locked->security_version !== (int) $actor->security_version) {
                throw new AuthenticationException;
            }
            abort_if($locked->two_factor_confirmed_at === null, 409);
            if (! Hash::check($password, $locked->password)) {
                throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
            }
            $codes = $locked->generateTwoFactorRecoveryCodes();
            $actor->setRawAttributes($locked->getAttributes(), true);

            return $codes;
        });
    }
}
