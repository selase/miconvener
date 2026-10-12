<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Mail\Platform\TenantSignupMail;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells MiConvener's staff when an organisation signs up and when it finishes
 * onboarding. Everyone whose platform role may view signups is told: the
 * superadmin, finance and marketing. A failure here must never get in the way
 * of the organisation signing up, so it is reported and swallowed.
 */
final class SignupAlerts
{
    public function __construct(private readonly PlatformStaff $staff) {}

    public function signedUp(Tenant $tenant): void
    {
        $this->send($tenant, TenantSignupMail::STAGE_SIGNED_UP);
    }

    public function onboarded(Tenant $tenant): void
    {
        $this->send($tenant, TenantSignupMail::STAGE_ONBOARDED);
    }

    private function send(Tenant $tenant, string $stage): void
    {
        try {
            $recipients = $this->staff->emailsWithPermission('view signups');

            if ($recipients !== []) {
                Mail::to($recipients)->queue(new TenantSignupMail($tenant, $stage));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
