<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Jobs\Events\SendPlatformAttendeeAccessCode;
use App\Mail\Events\PlatformAttendeeAccessCodeMail;
use App\Models\PlatformAttendeeAccessCode;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class PlatformAttendeeVerification
{
    /** Platform-verified proof lasts 12 hours (one full event day). */
    public const int VERIFIED_HOURS = 12;

    public const string SESSION_KEY = 'attendee_verified_platform';

    private static ?string $dummyHash = null;

    public function __construct(
        private readonly AttendeePortalRateLimiter $rateLimiter,
    ) {}

    public static function normalise(string $email): string
    {
        return mb_strtolower(mb_trim($email));
    }

    public static function getVerifiedEmail(?Session $session = null): ?string
    {
        $session ??= request()->hasSession() ? request()->session() : null;
        if ($session === null) {
            return null;
        }

        return app(self::class)->verifiedEmail($session);
    }

    /**
     * Validate send abuse limits and queue code generation and mailing.
     *
     * @return array{status: string, site_key?: string|null}
     */
    public function requestSend(string $email, string $ip, ?string $turnstileToken = null): array
    {
        $normalized = self::normalise($email);

        $check = $this->rateLimiter->checkSend($normalized, $ip, $turnstileToken);
        if ($check['status'] !== AttendeePortalRateLimiter::RESULT_ALLOWED) {
            $this->logSend($normalized, $ip, $check['status']);

            return $check;
        }

        SendPlatformAttendeeAccessCode::dispatch($normalized);

        $this->rateLimiter->recordSend($normalized, $ip);

        $this->logSend($normalized, $ip, AttendeePortalRateLimiter::RESULT_ALLOWED);

        return ['status' => AttendeePortalRateLimiter::RESULT_ALLOWED];
    }

    /**
     * Generates a code, invalidates previous unconsumed codes, and mails it.
     * Invoked from the queued worker job off the request thread.
     */
    public function dispatchCode(string $email): void
    {
        $normalized = self::normalise($email);

        // A new send consumes every unconsumed platform code for the address before creating the replacement.
        PlatformAttendeeAccessCode::query()
            ->where('email_normalized', $normalized)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = PlatformAttendeeAccessCode::generateCode();

        PlatformAttendeeAccessCode::query()->create([
            'email_normalized' => $normalized,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(PlatformAttendeeAccessCode::TTL_MINUTES),
        ]);

        Mail::to($normalized)->send(new PlatformAttendeeAccessCodeMail($code));
    }

    /**
     * Atomically reserve attempt, verify code, consume, rotate session, and store proof.
     */
    public function confirm(Session $session, string $email, string $code, string $ip): bool
    {
        $normalized = self::normalise($email);

        $this->rateLimiter->recordConfirmAttempt($normalized, $ip);

        $accessCode = PlatformAttendeeAccessCode::query()
            ->where('email_normalized', $normalized)
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if ($accessCode === null) {
            $this->checkDummyHash($code);

            return false;
        }

        $reserved = PlatformAttendeeAccessCode::query()
            ->whereKey($accessCode->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', PlatformAttendeeAccessCode::MAX_ATTEMPTS)
            ->increment('attempts');

        if ($reserved !== 1) {
            $this->checkDummyHash($code);

            return false;
        }

        if (! $accessCode->matches($code)) {
            return false;
        }

        $consumed = PlatformAttendeeAccessCode::query()
            ->whereKey($accessCode->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($consumed !== 1) {
            return false;
        }

        $session->regenerate();
        $session->put(self::SESSION_KEY, [
            'email' => $normalized,
            'verified_at' => now()->getTimestamp(),
            'expires_at' => now()->addHours(self::VERIFIED_HOURS)->getTimestamp(),
        ]);

        return true;
    }

    /**
     * The address proven platform-wide, or null if expired or missing.
     */
    public function verifiedEmail(Session $session): ?string
    {
        $marker = $session->get(self::SESSION_KEY);

        if (! is_array($marker) || ! isset($marker['email'], $marker['expires_at'])) {
            return null;
        }

        if ($marker['expires_at'] <= now()->getTimestamp()) {
            $session->forget(self::SESSION_KEY);

            return null;
        }

        return (string) $marker['email'];
    }

    public function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Cache::rememberForever(
            'platform-attendee-dummy-hash:'.config('hashing.driver').':'.config('hashing.bcrypt.rounds'),
            fn (): string => Hash::make('platform-attendee-verification-dummy-code'),
        );
    }

    /**
     * Record one send attempt for operators.
     *
     * The endpoint mails any syntactically valid address on purpose, so that
     * abuse of it is invisible unless it is counted. The address and the IP are
     * recorded as keyed digests: enough to group repeat offenders and to answer
     * a deliverability complaint, without writing an attendee's address into
     * the application log.
     */
    private function logSend(string $emailNormalized, string $ip, string $result): void
    {
        $key = (string) config('app.key');

        Log::info('platform attendee access code send', [
            'correlation_id' => (string) Str::uuid(),
            'email_hmac' => hash_hmac('sha256', $emailNormalized, $key),
            'ip_hmac' => hash_hmac('sha256', $ip, $key),
            'result' => $result,
        ]);
    }

    private function checkDummyHash(string $code): void
    {
        Hash::check($code, self::dummyHash());
    }
}
