<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\NewPasswordController;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

function installResetValidationHook(Closure $hook): void
{
    $repository = new class(Password::broker()->getRepository(), $hook) implements TokenRepositoryInterface
    {
        private bool $triggered = false;

        public function __construct(private TokenRepositoryInterface $inner, private Closure $hook) {}

        public function create(CanResetPassword $user): string
        {
            return $this->inner->create($user);
        }

        public function exists(CanResetPassword $user, $token): bool
        {
            $valid = $this->inner->exists($user, $token);
            if ($valid && ! $this->triggered) {
                $this->triggered = true;
                ($this->hook)();
            }

            return $valid;
        }

        public function recentlyCreatedToken(CanResetPassword $user): bool
        {
            return $this->inner->recentlyCreatedToken($user);
        }

        public function delete(CanResetPassword $user): void
        {
            $this->inner->delete($user);
        }

        public function deleteExpired(): void
        {
            $this->inner->deleteExpired();
        }
    };

    $broker = new PasswordBroker(
        $repository,
        app('auth')->createUserProvider(config('auth.passwords.'.config('auth.defaults.passwords').'.provider')),
        app('events'),
    );
    $brokers = new ReflectionProperty(app('auth.password'), 'brokers');
    $brokers->setValue(app('auth.password'), [config('auth.defaults.passwords') => $broker]);
}

function submitIdentityReset(string $email, string $token, string $password = 'replacement-password'): mixed
{
    return app(NewPasswordController::class)->store(Request::create('/reset-password', 'POST', [
        'email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password,
    ]));
}

beforeEach(function (): void {
    Mail::fake();
    Notification::fake();
    Event::fake([PasswordReset::class]);
    $this->identity = User::factory()->create(['email' => 'original@example.org', 'security_version' => 0]);
    $this->originalPassword = $this->identity->password;
    $this->originalRemember = $this->identity->remember_token;
    $this->identity->createToken('existing');
    $this->resetToken = Password::createToken($this->identity);
});

it('rejects a validated reset when the locked identity or live token changed', function (string $change): void {
    installResetValidationHook(function () use ($change): void {
        $this->identity->getConnection()->transaction(function () use ($change): void {
            $locked = User::query()->whereKey($this->identity->id)->lockForUpdate()->firstOrFail();
            if (in_array($change, ['confirmation', 'token'], true)) {
                Password::broker()->deleteToken($locked);
            }
            if (in_array($change, ['confirmation', 'email'], true)) {
                $locked->email = 'confirmed@example.org';
            }
            if (in_array($change, ['confirmation', 'version'], true)) {
                $locked->security_version++;
            }
            $locked->save();
        });
    });

    try {
        submitIdentityReset($this->identity->email, $this->resetToken);
        $this->fail('A stale validated reset must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['email' => [__(Password::INVALID_TOKEN)]]);
    }

    $fresh = $this->identity->fresh();
    expect($fresh->password)->toBe($this->originalPassword)
        ->and($fresh->remember_token)->toBe($this->originalRemember)
        ->and($fresh->security_version)->toBe(in_array($change, ['confirmation', 'version'], true) ? 1 : 0)
        ->and($fresh->email)->toBe(in_array($change, ['confirmation', 'email'], true) ? 'confirmed@example.org' : 'original@example.org')
        ->and($fresh->tokens()->count())->toBe(1);
    Event::assertNotDispatched(PasswordReset::class);
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
})->with(['confirmation', 'email', 'version', 'token']);

it('allows only one of two overlapping validated resets for the same user', function (): void {
    installResetValidationHook(function (): void {
        submitIdentityReset($this->identity->email, $this->resetToken, 'winning-password');
    });

    expect(fn () => submitIdentityReset($this->identity->email, $this->resetToken, 'losing-password'))
        ->toThrow(ValidationException::class);

    $fresh = $this->identity->fresh();
    expect(Hash::check('winning-password', $fresh->password))->toBeTrue()
        ->and($fresh->security_version)->toBe(1)
        ->and($fresh->tokens()->count())->toBe(0)
        ->and(Password::tokenExists($fresh, $this->resetToken))->toBeFalse();
    Event::assertDispatchedTimes(PasswordReset::class, 1);
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

it('preserves a normal normalized reset and consumes its token before publishing success', function (): void {
    $response = submitIdentityReset(' ORIGINAL@Example.Org ', $this->resetToken);

    $fresh = $this->identity->fresh();
    expect($response->getTargetUrl())->toBe(route('login'))
        ->and(Hash::check('replacement-password', $fresh->password))->toBeTrue()
        ->and($fresh->remember_token)->not->toBe($this->originalRemember)
        ->and($fresh->security_version)->toBe(1)
        ->and($fresh->tokens()->count())->toBe(0)
        ->and(Password::tokenExists($fresh, $this->resetToken))->toBeFalse();
    Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event): bool => $event->user->is($fresh) && $event->user->security_version === 1);
    Event::assertDispatchedTimes(PasswordReset::class, 1);
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});
