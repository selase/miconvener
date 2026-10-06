<?php

declare(strict_types=1);

namespace App\Mail\Moderation;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells an organisation MiConvener took down (or restored) one of its events
 * or marketplace listings, and why. It comes from MiConvener, not branded as
 * the organiser: it is our decision about their content.
 */
final class TakedownNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  'event'|'listing'  $kind
     */
    public function __construct(
        public readonly string $organisationName,
        public readonly string $kind,
        public readonly string $itemName,
        public readonly bool $restored,
        public readonly ?string $reason,
    ) {}

    public function envelope(): Envelope
    {
        $what = $this->kind === 'event' ? 'event' : 'marketplace listing';

        return new Envelope(subject: $this->restored
            ? "Your {$what} \"{$this->itemName}\" is back"
            : "We have taken down your {$what} \"{$this->itemName}\"");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.moderation.takedown-notice');
    }
}
