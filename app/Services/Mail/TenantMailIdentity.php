<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Event;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Who an event's mail appears to come from, and where a reply to it goes.
 *
 * The envelope address stays on our own verified sending domain -- it is the
 * only address SES will accept -- so what changes is the display name the
 * attendee actually reads, and the Reply-To that carries their answer back to
 * the organizer rather than into a noreply mailbox.
 *
 * Sending as the organizer's own address is a separate, larger feature: it
 * needs their domain verified as a sending identity, which needs DNS records
 * they may not be able to add.
 */
final class TenantMailIdentity
{
    /**
     * Build an envelope for mail an attendee receives about an event.
     *
     * A null event means we could not resolve who the mail is for, so it keeps
     * the platform's own identity rather than guessing at a tenant's.
     */
    public function envelope(string $subject, ?Event $event): Envelope
    {
        $tenant = $event?->tenant;

        if ($tenant === null) {
            return new Envelope(subject: $subject);
        }

        // A tenant here implies an event: it was reached through one.
        $replyTo = $event->contact_email ?: $tenant->email;

        return new Envelope(
            subject: $subject,
            from: new Address((string) config('mail.from.address'), $this->displayName($tenant->name, $tenant->planAllows('white_label'))),
            replyTo: $replyTo ? [new Address($replyTo, $tenant->name)] : [],
        );
    }

    /**
     * White-label drops the platform's name; everyone else is attributed with
     * it, so the attendee sees who is writing without us disappearing from a
     * message we are the ones delivering.
     */
    private function displayName(string $tenantName, bool $whiteLabel): string
    {
        if ($whiteLabel) {
            return $tenantName;
        }

        $platform = (string) config('app.name');

        // A tenant that happens to share our name should not read "X via X".
        return $tenantName === $platform ? $tenantName : "{$tenantName} via {$platform}";
    }
}
