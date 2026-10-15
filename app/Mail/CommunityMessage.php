<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\CommunityMessage as Message;
use App\Services\Mail\TenantMailIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

final class CommunityMessage extends Mailable
{
    public function __construct(public Message $announcement, public string $unsubscribeUrl) {}

    public function envelope(): Envelope
    {
        return app(TenantMailIdentity::class)->tenantEnvelope($this->announcement->subject, $this->announcement->tenant);
    }

    public function headers(): Headers
    {
        return app(TenantMailIdentity::class)->tenantHeaders($this->announcement->tenant);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.community-message');
    }
}
