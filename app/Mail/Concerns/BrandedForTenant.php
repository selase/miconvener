<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Models\Event;
use App\Services\Mail\TenantMailIdentity;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Applied to mail an attendee receives about a tenant's event, so it arrives
 * under the organizer's name and replies reach them.
 *
 * Opting in is deliberate rather than automatic: billing and account mail comes
 * from us and must keep saying so, and a receipt that appeared to be from the
 * organizer would be a lie about who charged the card.
 */
trait BrandedForTenant
{
    /**
     * The event this mail belongs to, which carries both the tenant whose name
     * goes on it and the optional address replies should go to.
     */
    abstract protected function brandingEvent(): ?Event;

    protected function brandedEnvelope(string $subject): Envelope
    {
        return app(TenantMailIdentity::class)->envelope($subject, $this->brandingEvent());
    }
}
