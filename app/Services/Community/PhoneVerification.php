<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Contracts\SmsGateway;
use App\Jobs\Community\SendPhoneAccessCode;
use App\Models\PlatformPhoneAccessCode;
use App\Services\Events\AttendeePortalRateLimiter;
use App\Services\Sms\SmsMessage;
use App\Services\Sms\SmsResult;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

final class PhoneVerification
{
    public const string SESSION_KEY = 'community_verified_phone';

    public function __construct(private readonly MemberIdentity $identity, private readonly PhoneSignInBudget $budget, private readonly AttendeePortalRateLimiter $limiter, private readonly SmsGateway $gateway) {}

    public static function digest(string $value): string
    {
        return hash_hmac('sha256', 'community-phone-proof:v1:'.$value, (string) config('app.key'));
    }

    /** @return array{status:string, request_id?:string, site_key?:?string, action?:string} */
    public function requestSend(string $phone, string $ip, ?string $challenge = null): array
    {
        if (! config('attendee_portal.phone.enabled') || (int) config('attendee_portal.phone.daily_cap') <= 0 || ! $this->gateway->isConfigured()) {
            return ['status' => 'unavailable'];
        }
        $normalised = $this->identity->normalisePhone($phone);
        if ($normalised === null) {
            return ['status' => 'invalid'];
        }
        $connection = (new PlatformPhoneAccessCode)->getConnection();
        $result = $connection->transaction(function () use ($connection, $normalised, $ip, $challenge): array {
            $hash = self::digest($normalised);
            $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['phone-signin-ip:'.self::digest($ip)]);
            $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['phone-signin-number:'.$hash]);
            $check = $this->limiter->checkSend('phone:'.$hash, $ip, $challenge);
            if ($check['status'] !== 'allowed') {
                return $check;
            }
            $codes = PlatformPhoneAccessCode::query()->where('phone_hash', $hash);
            if ((clone $codes)->where('created_at', '>', now()->subSeconds(60))->exists()
                || (clone $codes)->where('created_at', '>=', now('UTC')->startOfDay())->count() >= (int) config('attendee_portal.phone.number_daily_cap', 5)
                || (clone $codes)->where('created_at', '>', now()->subMinutes(10))->sum('attempts') >= 5) {
                return ['status' => 'rate_limited'];
            }
            $id = (string) Str::uuid();
            if (! $this->budget->reserve($id)) {
                return ['status' => 'unavailable'];
            }
            (clone $codes)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            PlatformPhoneAccessCode::query()->create(['id' => $id, 'phone' => $normalised, 'phone_hash' => $hash, 'ip_hash' => self::digest($ip), 'expires_at' => now()->addMinutes(5)]);
            $this->limiter->recordSend('phone:'.$hash, $ip);

            return ['status' => 'allowed', 'request_id' => $id];
        });
        if ($result['status'] === 'allowed') {
            SendPhoneAccessCode::dispatch($result['request_id'])->afterCommit();
        }

        return $result;
    }

    public function dispatchCode(string $id): void
    {
        $connection = (new PlatformPhoneAccessCode)->getConnection();
        $submission = $connection->transaction(function () use ($id): ?array {
            $row = PlatformPhoneAccessCode::query()->lockForUpdate()->find($id);
            if ($row === null || $row->submitted_at !== null || $row->consumed_at !== null || $row->expires_at->lessThanOrEqualTo(now())) {
                return null;
            }
            if (! config('attendee_portal.phone.enabled') || ! $this->gateway->isConfigured()) {
                $row->update(['consumed_at' => now()]);
                $this->budget->finish($id, 'failed');

                return null;
            }
            $code = mb_str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $row->update(['submitted_at' => now(), 'code_hash' => Hash::make($code)]);

            return [$row->phone, $code];
        });
        if ($submission === null) {
            return;
        }
        try {
            $results = \Laravel\Telescope\Telescope::withoutRecording(fn () => $this->gateway->send([new SmsMessage($submission[0], 'MiConvener sign-in code: '.$submission[1].'. Expires in 5 minutes. Do not share this code.', $id)]));
            $result = $results[$id] ?? SmsResult::unknown('Missing provider outcome.');
            $this->budget->finish($id, $result->accepted ? 'submitted' : ($result->uncertain ? 'unknown' : 'failed'));
            if (! $result->accepted && ! $result->uncertain) {
                PlatformPhoneAccessCode::query()->whereKey($id)->update(['consumed_at' => now()]);
            }
        } catch (Throwable) {
            $this->budget->finish($id, 'unknown');
        }
    }

    public function confirm(Session $session, string $phone, string $code, string $ip): bool
    {
        $normalised = $this->identity->normalisePhone($phone);
        if ($normalised === null || ! config('attendee_portal.phone.enabled')) {
            return false;
        }
        $hash = self::digest($normalised);
        if (! $this->limiter->checkConfirm('phone:'.$hash, $ip)) {
            return false;
        }
        $this->limiter->recordConfirmAttempt('phone:'.$hash, $ip);
        $connection = (new PlatformPhoneAccessCode)->getConnection();
        $confirmed = $connection->transaction(function () use ($connection, $hash, $code): bool {
            $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['phone-signin-number:'.$hash]);
            $row = PlatformPhoneAccessCode::query()->where('phone_hash', $hash)->whereNull('consumed_at')->latest('created_at')->lockForUpdate()->first();
            if ($row === null || $row->expires_at->lessThanOrEqualTo(now()) || $row->code_hash === null || $row->attempts >= 5) {
                Hash::check($code, Hash::make('not-a-code'));

                return false;
            }
            $row->increment('attempts');
            if (! Hash::check($code, $row->code_hash)) {
                return false;
            }
            $row->update(['consumed_at' => now()]);

            return true;
        });
        if ($confirmed) {
            $old = $this->verifiedPhone($session);
            if ($old !== null && $old !== $normalised) {
                $session->forget('community_member_grants');
            }
            $session->forget('giving_checkout_access');
            $session->regenerate(true);
            $session->put(self::SESSION_KEY, ['phone' => $normalised, 'verified_at' => now()->timestamp, 'expires_at' => now()->addHours(12)->timestamp]);
        }

        return $confirmed;
    }

    public function verifiedPhone(Session $session): ?string
    {
        $proof = $session->get(self::SESSION_KEY);
        if (! is_array($proof) || ! isset($proof['phone'], $proof['expires_at']) || ! is_string($proof['phone']) || ! is_numeric($proof['expires_at']) || $proof['expires_at'] <= now()->timestamp) {
            $session->forget([self::SESSION_KEY, 'community_member_grants']);

            return null;
        }

        return $this->identity->normalisePhone($proof['phone']);
    }

    public function forget(Session $session): void
    {
        $session->forget([self::SESSION_KEY, 'giving_checkout_access', 'community_member_grants', 'community_claim_capabilities', 'community_join_capabilities']);
    }
}
