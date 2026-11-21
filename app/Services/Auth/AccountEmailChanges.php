<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Mail\Users\AccountEmailChanged;
use App\Mail\Users\VerifyAccountEmailChange;
use App\Models\AccountEmailChange;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class AccountEmailChanges
{
    public function request(User $actor, string $email, #[SensitiveParameter] string $password, #[SensitiveParameter] ?string $code): AccountEmailChange
    {
        $email = mb_strtolower(mb_trim($email));

        return $actor->getConnection()->transaction(function () use ($actor, $email, $password, $code): AccountEmailChange {
            $user = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->email === $actor->email && (int) $user->security_version === (int) $actor->security_version, 409);
            $this->reauthenticate($user, $password, $code);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255 || $email === $user->email) {
                throw ValidationException::withMessages(['email' => 'Enter a different valid email address.']);
            }
            $this->assertAvailable($email, $user);
            AccountEmailChange::query()->where('user_id', $user->id)->whereNull('consumed_at')->whereNull('cancelled_at')->update(['cancelled_at' => now(), 'token_hash' => null]);
            $token = bin2hex(random_bytes(32));
            $change = AccountEmailChange::query()->create([
                'user_id' => $user->id, 'current_email' => $user->email, 'new_email' => $email,
                'security_version' => (int) $user->security_version, 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHour(),
            ]);
            Mail::to($email)->queue(new VerifyAccountEmailChange($change->id, Crypt::encryptString($token)));

            return $change;
        });
    }

    public function pending(User $actor, string $id): AccountEmailChange
    {
        $change = AccountEmailChange::query()->findOrFail($id);
        $this->assertPending($change, $actor->fresh());

        return $change;
    }

    public function confirm(User $actor, string $id, #[SensitiveParameter] string $token, #[SensitiveParameter] string $password, #[SensitiveParameter] ?string $code): User
    {
        try {
            return $actor->getConnection()->transaction(function () use ($actor, $id, $token, $password, $code): User {
                $user = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $change = AccountEmailChange::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $this->assertPending($change, $user);
                abort_unless(preg_match('/\A[a-f0-9]{64}\z/D', $token) && hash_equals((string) $change->token_hash, hash('sha256', $token)), 410, 'This email verification link is no longer valid.');
                $this->reauthenticate($user, $password, $code);
                $this->assertAvailable($change->new_email, $user);
                Password::deleteToken($user);
                $user->forceFill([
                    'email' => $change->new_email, 'email_verified_at' => now(),
                    'security_version' => (int) $user->security_version + 1,
                    'remember_token' => Str::random(60),
                ])->save();
                $user->tokens()->delete();
                $change->forceFill(['consumed_at' => now(), 'token_hash' => null])->save();
                Mail::to($change->current_email)->queue(new AccountEmailChanged($change->id));

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email address is unavailable. Request another change.']);
        }
    }

    private function assertPending(AccountEmailChange $change, ?User $user): void
    {
        abort_unless($user && (string) $change->user_id === (string) $user->id, 403);
        abort_unless(in_array($user->status, [null, User::STATUS_ACTIVE], true) && $user->email_verified_at, 403);
        abort_unless(! $change->consumed_at && ! $change->cancelled_at && $change->expires_at->isFuture()
            && $change->token_hash && $change->current_email === $user->email
            && $change->security_version === (int) $user->security_version, 410, 'This email verification link is no longer valid.');
    }

    private function reauthenticate(User $user, #[SensitiveParameter] string $password, #[SensitiveParameter] ?string $code): void
    {
        abort_unless(in_array($user->status, [null, User::STATUS_ACTIVE], true) && $user->email_verified_at, 403);
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }
        if ($user->two_factor_secret && $user->two_factor_confirmed_at
            && (! $code || ! preg_match('/\A[0-9]{6}\z/D', $code) || ! app('pragmarx.google2fa')->verifyKey($user->two_factor_secret, $code))) {
            throw ValidationException::withMessages(['code' => 'Enter a valid code from your authenticator app.']);
        }
    }

    private function assertAvailable(string $email, User $user): void
    {
        if (User::query()->whereKeyNot($user->id)->whereRaw('lower(btrim(email)) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'This email address is unavailable. Request another change.']);
        }
    }
}
