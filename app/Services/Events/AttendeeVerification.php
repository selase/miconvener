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

        $key = "attendee-code:{$tenant->id}:{$email}";
        if (RateLimiter::tooManyAttempts($key, self::CODES_PER_HOUR)) {
            return;
        }
        RateLimiter::hit($key, 3600);

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

        if ($accessCode === null || ! $accessCode->isUsable()) {
            return false;
        }

        if (! $accessCode->matches($code)) {
            $accessCode->increment('attempts');

            return false;
        }

        $accessCode->update(['consumed_at' => now()]);

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
}
