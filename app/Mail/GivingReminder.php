<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\GivingReminder as Reminder;
use App\Services\Mail\TenantMailIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

final class GivingReminder extends Mailable
{
    public function __construct(public Reminder $reminder, public string $unsubscribeToken) {}

    public function envelope(): Envelope
    {
        return app(TenantMailIdentity::class)->tenantEnvelope('Your optional giving reminder', $this->reminder->tenant);
    }

    public function headers(): Headers
    {
        return app(TenantMailIdentity::class)->tenantHeaders($this->reminder->tenant);
    }

    public function content(): Content
    {
        $args = ['subdomain' => $this->reminder->tenant->slug];

        return new Content(markdown: 'emails.giving-reminder', with: ['organisation' => $this->reminder->tenant->name, 'fund' => $this->reminder->fund->name,
            'giveUrl' => route('public.giving.show', [...$args, 'slug' => $this->reminder->fund->slug]),
            'unsubscribeUrl' => route('public.giving.reminders.unsubscribe', [...$args, 'reminder' => $this->reminder->id, 'token' => $this->unsubscribeToken])]);
    }
}
