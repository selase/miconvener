<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventRegistration;
use App\Services\Tenancy\TenantHostMatcher;
use Illuminate\Contracts\Session\Session;

final class PlatformAttendeeWorkspaceAuthorizer
{
    public const string CHECKOUT_GRANTS_SESSION_KEY = 'attendee_checkout_grants';

    public const string REGISTERED_SESSION_KEY = 'attendee_registered_in_session';

    public const int DEFAULT_GRANT_TTL_MINUTES = 30;

    public function __construct(
        private readonly PlatformAttendeeVerification $verification,
        private readonly TenantHostMatcher $hostMatcher,
    ) {}

    /**
     * Issue a short-lived checkout grant for a specific registration.
     */
    public function grantCheckoutAccess(Session $session, string $registrationId, int $ttlMinutes = self::DEFAULT_GRANT_TTL_MINUTES): void
    {
        $grants = $session->get(self::CHECKOUT_GRANTS_SESSION_KEY, []);
        $grants[$registrationId] = now()->addMinutes($ttlMinutes)->getTimestamp();
        $session->put(self::CHECKOUT_GRANTS_SESSION_KEY, $grants);
    }

    /**
     * Check if the current session has an unexpired checkout grant for this registration.
     */
    public function hasCheckoutGrant(Session $session, string $registrationId): bool
    {
        $grants = $session->get(self::CHECKOUT_GRANTS_SESSION_KEY, []);

        if (! isset($grants[$registrationId])) {
            return false;
        }

        if ($grants[$registrationId] < now()->getTimestamp()) {
            unset($grants[$registrationId]);
            $session->put(self::CHECKOUT_GRANTS_SESSION_KEY, $grants);

            return false;
        }

        return true;
    }

    /**
     * Remember that this browser created a registration, so the person who
     * just filled in the form keeps reaching their own ticket without proving
     * an address they have not been asked for yet.
     */
    public function rememberRegistered(Session $session, string $registrationId): void
    {
        $owned = $session->get(self::REGISTERED_SESSION_KEY, []);
        $owned[$registrationId] = now()->getTimestamp();
        $session->put(self::REGISTERED_SESSION_KEY, $owned);
    }

    /**
     * Whether this browser created the registration during this session.
     */
    public function registeredInThisSession(Session $session, string $registrationId): bool
    {
        return array_key_exists($registrationId, $session->get(self::REGISTERED_SESSION_KEY, []));
    }

    /**
     * Revoke checkout grant for a specific registration (e.g. after transfer).
     */
    public function revokeCheckoutAccess(Session $session, string $registrationId): void
    {
        $grants = $session->get(self::CHECKOUT_GRANTS_SESSION_KEY, []);
        unset($grants[$registrationId]);
        $session->put(self::CHECKOUT_GRANTS_SESSION_KEY, $grants);
    }

    /**
     * Authorize whether the current session can view or interact with a registration.
     * Authorized if:
     * 1) Session has an active checkout grant for this exact registration ID, OR
     * 2) Session has a verified platform attendee email matching the registration's current email.
     */
    public function canAccess(Session $session, EventRegistration $registration): bool
    {
        if ($this->hasCheckoutGrant($session, $registration->id)) {
            return true;
        }

        $verifiedEmail = $this->verification->verifiedEmail($session);
        if ($verifiedEmail === null) {
            return false;
        }

        return mb_strtolower(mb_trim($verifiedEmail)) === mb_strtolower(mb_trim($registration->email));
    }

    /**
     * Generate canonical workspace URL for a registration on the platform host.
     */
    public function workspaceUrl(EventRegistration $registration): string
    {
        $baseDomain = $this->hostMatcher->baseDomain();
        $scheme = request()->getScheme();

        return "{$scheme}://{$baseDomain}/my/events/{$registration->id}";
    }
}
