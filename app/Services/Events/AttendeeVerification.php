<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Mail\Events\AttendeeAccessCodeMail;
use App\Models\AttendeeAccessCode;
use App\Models\EventAbstractAuthor;
use App\Models\EventCertificate;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Tenancy\FeatureMeteringService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Proves that someone holds an email address, and remembers it for a while, so
 * they can see everything tied to that address with one organiser.
 *
 * The proof is scoped to a tenant explicitly. The session cookie is shared
 * across every organiser's subdomain, so one session can hold proofs for
 * several organisers side by side; nothing about the cookie keeps them apart.
 * Only the tenant key in verifiedEmail() does.
 */
final class AttendeeVerification
{
    /** How long a proof lasts: enough to look around, short on a shared device. */
    public const int VERIFIED_MINUTES = 120;

    /** Codes one address can be sent per hour, whatever IPs ask for them. */
    public const int CODES_PER_HOUR = 5;

    /**
     * A bcrypt hash of a value nobody typed. Checked in place of a real code
     * when none exists, so confirm() pays the same Hash::check cost either
     * way and a timing difference can't reveal whether an address has a code
     * outstanding.
     */
    private const string DUMMY_HASH = '$2y$12$C/ukppqcB46CUvwCU.EF0eLTzL5DaymhtFd7W5xtNqmYR6O9QEQk.';

    public static function normalise(string $email): string
    {
        return mb_strtolower(mb_trim($email));
    }

    /**
     * Sends a code if there is anything to see. The caller answers the same
     * either way, so this cannot be used to find out who attends.
     */
    public function sendCode(Tenant $tenant, string $email): void
    {
        $email = self::normalise($email);

        if (! $this->hasAnythingFor($tenant, $email)) {
            return;
        }

        // hit() increments and returns the new count atomically, so two
        // concurrent requests for the same address can't both read the count
        // as under the cap before either one lands.
        $key = "attendee-code:{$tenant->id}:{$email}";
        if (RateLimiter::hit($key, 3600) > self::CODES_PER_HOUR) {
            return;
        }

        // Only the latest code works.
        AttendeeAccessCode::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = AttendeeAccessCode::generateCode();

        AttendeeAccessCode::query()->create([
            'tenant_id' => $tenant->id,
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(AttendeeAccessCode::TTL_MINUTES),
        ]);

        Mail::to($email)->queue(new AttendeeAccessCodeMail($tenant, $code));
        app(FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');
    }

    /**
     * On a match: consumes the code, rotates the session id against fixation,
     * and remembers the address for this tenant only.
     *
     * The attempt is reserved with an atomic increment before the code is
     * checked, and the code is consumed with an atomic update after -- each
     * guarded by a where clause on the same conditions isUsable() would
     * check. Reading attempts in PHP and writing it back later, as
     * isUsable()-then-increment did, lets concurrent guesses all read the
     * count before any of them lands, so N guesses in flight can all get past
     * the cap; the same race let two concurrent correct guesses both consume
     * the code. Only a row count of exactly one from the conditional write
     * proves this request was the one that changed the row.
     */
    public function confirm(Session $session, Tenant $tenant, string $email, string $code): bool
    {
        $email = self::normalise($email);

        $accessCode = AttendeeAccessCode::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->latest()
            ->first();

        if ($accessCode === null) {
            $this->checkDummyHash($code);

            return false;
        }

        $reserved = AttendeeAccessCode::query()
            ->whereKey($accessCode->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', AttendeeAccessCode::MAX_ATTEMPTS)
            ->increment('attempts');

        if ($reserved !== 1) {
            $this->checkDummyHash($code);

            return false;
        }

        if (! $accessCode->matches($code)) {
            return false;
        }

        $consumed = AttendeeAccessCode::query()
            ->whereKey($accessCode->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($consumed !== 1) {
            return false;
        }

        $session->regenerate();
        $session->put(self::sessionKey($tenant), [
            'email' => $email,
            'expires_at' => now()->addMinutes(self::VERIFIED_MINUTES)->getTimestamp(),
        ]);

        return true;
    }

    /** The address proven for this tenant, or null. Never another tenant's. */
    public function verifiedEmail(Session $session, Tenant $tenant): ?string
    {
        $marker = $session->get(self::sessionKey($tenant));

        if (! is_array($marker) || ! isset($marker['email'], $marker['expires_at'])) {
            return null;
        }

        if ($marker['expires_at'] <= now()->getTimestamp()) {
            $session->forget(self::sessionKey($tenant));

            return null;
        }

        return (string) $marker['email'];
    }

    public function forget(Session $session, Tenant $tenant): void
    {
        $session->forget(self::sessionKey($tenant));
    }

    /**
     * A live registration, an abstract authorship, or a certificate.
     * Certificates count on their own: a speaker may hold one with no
     * registration at all.
     */
    public function hasAnythingFor(Tenant $tenant, string $email): bool
    {
        $email = self::normalise($email);

        return EventRegistration::query()
            ->where('tenant_id', $tenant->id)
            ->forEmail($email)
            ->whereNotIn('status', [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED])
            ->exists()
            || EventAbstractAuthor::query()
                ->whereHas('abstract', fn ($query) => $query->where('tenant_id', $tenant->id))
                ->whereRaw('lower(email) = ?', [$email])
                ->exists()
            || EventCertificate::query()
                ->where('tenant_id', $tenant->id)
                ->whereRaw('lower(recipient_email) = ?', [$email])
                ->exists();
    }

    private static function sessionKey(Tenant $tenant): string
    {
        return "attendee_verified.{$tenant->id}";
    }

    /**
     * Pays the same Hash::check cost a real comparison would, against a value
     * nobody could have typed, so confirm() takes the same time whether the
     * code was missing, expired, exhausted or consumed already.
     */
    private function checkDummyHash(string $code): void
    {
        Hash::check($code, self::DUMMY_HASH);
    }
}
