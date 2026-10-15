<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Mail\Concerns\BrandedForTenant;
use App\Models\Event;
use App\Models\EventBlast;
use App\Models\EventBlastEmailClaim;
use App\Services\Community\EventAnnouncementDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class EventBlastMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use BrandedForTenant;
    use Queueable;
    use SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public EventBlast $blast, public string $recipientName, public string $recipientId, public ?string $claimId = null) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope($this->blast->subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.blast',
            with: [
                'recipientName' => $this->recipientName,
                'event' => $this->blast->event,
                'bodyText' => $this->blast->body,
                'unsubscribeUrl' => $this->unsubscribeUrl(),
                'trackingUrl' => route('public.blasts.open', ['subdomain' => $this->blast->tenant->slug, 'recipient' => $this->recipientId]),
            ],
        );
    }

    public function send($mailer): ?\Illuminate\Mail\SentMessage
    {
        return app(EventAnnouncementDelivery::class)->sendQueuedEmail($this, fn () => parent::send($mailer));
    }

    protected function brandingEvent(): ?Event
    {
        return $this->blast->event;
    }

    private function unsubscribeUrl(): ?string
    {
        $claim = $this->claimId === null ? null : EventBlastEmailClaim::query()->find($this->claimId);
        if ($claim?->unsubscribe_token === null) {
            return null;
        }

        return route('public.community.preferences.show', ['subdomain' => $this->blast->tenant->slug, 'token' => $claim->unsubscribe_token]);
    }
}
