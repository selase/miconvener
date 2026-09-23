<?php

declare(strict_types=1);

namespace App\Services\Events;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

final class AttendeePortalRateLimiter
{
    public const string RESULT_ALLOWED = 'allowed';

    public const string RESULT_RATE_LIMITED = 'rate_limited';

    public const string RESULT_CHALLENGE_REQUIRED = 'challenge_required';

    public const string RESULT_CHALLENGE_FAILED = 'challenge_failed';

    /**
     * Check if a send request passes abuse limits and human challenge requirements.
     *
     * @return array{status: string, site_key?: string|null}
     */
    public function checkSend(string $emailNormalized, string $ip, ?string $turnstileToken = null): array
    {
        $emailHash = hash('sha256', $emailNormalized);
        $ipHash = hash('sha256', $ip);

        $ipDailyLimit = (int) config('attendee_portal.rate_limits.ip_daily', 100);
        if (RateLimiter::tooManyAttempts("portal:rl:ip:daily:{$ipHash}", $ipDailyLimit)) {
            return ['status' => self::RESULT_RATE_LIMITED];
        }

        $ipBurstLimit = (int) config('attendee_portal.rate_limits.ip_burst', 20);
        if (RateLimiter::tooManyAttempts("portal:rl:ip:burst:{$ipHash}", $ipBurstLimit)) {
            return ['status' => self::RESULT_RATE_LIMITED];
        }

        $ipBurstAttempts = RateLimiter::attempts("portal:rl:ip:burst:{$ipHash}");
        $challengeThreshold = (int) config('attendee_portal.rate_limits.ip_challenge_threshold', 5);

        if ($ipBurstAttempts >= $challengeThreshold) {
            $siteKey = config('attendee_portal.turnstile.site_key');

            if ($turnstileToken === null || mb_trim($turnstileToken) === '') {
                return [
                    'status' => self::RESULT_CHALLENGE_REQUIRED,
                    'site_key' => $siteKey,
                ];
            }

            if (! $this->verifyTurnstileToken($turnstileToken, $ip)) {
                return [
                    'status' => self::RESULT_CHALLENGE_FAILED,
                    'site_key' => $siteKey,
                ];
            }
        }

        $cooldown = (int) config('attendee_portal.rate_limits.email_cooldown_seconds', 60);
        if (RateLimiter::tooManyAttempts("portal:rl:email:cooldown:{$emailHash}", 1)) {
            return ['status' => self::RESULT_RATE_LIMITED];
        }

        $hourlyLimit = (int) config('attendee_portal.rate_limits.email_hourly', 5);
        if (RateLimiter::tooManyAttempts("portal:rl:email:hourly:{$emailHash}", $hourlyLimit)) {
            return ['status' => self::RESULT_RATE_LIMITED];
        }

        $dailyLimit = (int) config('attendee_portal.rate_limits.email_daily', 10);
        if (RateLimiter::tooManyAttempts("portal:rl:email:daily:{$emailHash}", $dailyLimit)) {
            return ['status' => self::RESULT_RATE_LIMITED];
        }

        return ['status' => self::RESULT_ALLOWED];
    }

    /**
     * Record a successfully dispatched send against all buckets.
     */
    public function recordSend(string $emailNormalized, string $ip): void
    {
        $emailHash = hash('sha256', $emailNormalized);
        $ipHash = hash('sha256', $ip);

        $cooldown = (int) config('attendee_portal.rate_limits.email_cooldown_seconds', 60);
        $burstWindow = (int) config('attendee_portal.rate_limits.ip_burst_window_seconds', 600);

        RateLimiter::hit("portal:rl:email:cooldown:{$emailHash}", $cooldown);
        RateLimiter::hit("portal:rl:email:hourly:{$emailHash}", 3600);
        RateLimiter::hit("portal:rl:email:daily:{$emailHash}", 86400);
        RateLimiter::hit("portal:rl:ip:burst:{$ipHash}", $burstWindow);
        RateLimiter::hit("portal:rl:ip:daily:{$ipHash}", 86400);
    }

    /**
     * Check if a confirmation request passes rate limits.
     */
    public function checkConfirm(string $emailNormalized, string $ip): bool
    {
        $emailHash = hash('sha256', $emailNormalized);
        $ipHash = hash('sha256', $ip);

        $emailLimit = (int) config('attendee_portal.rate_limits.confirm_email_limit', 10);
        if (RateLimiter::tooManyAttempts("portal:rl:confirm:email:{$emailHash}", $emailLimit)) {
            return false;
        }

        $ipLimit = (int) config('attendee_portal.rate_limits.confirm_ip_limit', 60);
        if (RateLimiter::tooManyAttempts("portal:rl:confirm:ip:{$ipHash}", $ipLimit)) {
            return false;
        }

        return true;
    }

    /**
     * Record a confirmation attempt against email and IP buckets.
     */
    public function recordConfirmAttempt(string $emailNormalized, string $ip): void
    {
        $emailHash = hash('sha256', $emailNormalized);
        $ipHash = hash('sha256', $ip);

        $emailWindow = (int) config('attendee_portal.rate_limits.confirm_email_window_seconds', 600);
        $ipWindow = (int) config('attendee_portal.rate_limits.confirm_ip_window_seconds', 600);

        RateLimiter::hit("portal:rl:confirm:email:{$emailHash}", $emailWindow);
        RateLimiter::hit("portal:rl:confirm:ip:{$ipHash}", $ipWindow);
    }

    /**
     * Verify Turnstile token with Cloudflare API.
     */
    public function verifyTurnstileToken(string $token, ?string $ip = null): bool
    {
        $secret = (string) config('attendee_portal.turnstile.secret_key');
        if ($secret === '') {
            Log::error('Cloudflare Turnstile secret key is not configured.');

            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]);

            return (bool) ($response->json('success') ?? false);
        } catch (Throwable $e) {
            Log::error('Turnstile verification failed: '.$e->getMessage());

            return false;
        }
    }

    public function clearSendLimits(string $emailNormalized, string $ip): void
    {
        $emailHash = hash('sha256', $emailNormalized);
        $ipHash = hash('sha256', $ip);

        RateLimiter::clear("portal:rl:email:cooldown:{$emailHash}");
        RateLimiter::clear("portal:rl:email:hourly:{$emailHash}");
        RateLimiter::clear("portal:rl:email:daily:{$emailHash}");
        RateLimiter::clear("portal:rl:ip:burst:{$ipHash}");
        RateLimiter::clear("portal:rl:ip:daily:{$ipHash}");
    }

    public function clearConfirmLimits(string $emailNormalized, string $ip): void
    {
        $emailHash = hash('sha256', $emailNormalized);
        $ipHash = hash('sha256', $ip);

        RateLimiter::clear("portal:rl:confirm:email:{$emailHash}");
        RateLimiter::clear("portal:rl:confirm:ip:{$ipHash}");
    }
}
