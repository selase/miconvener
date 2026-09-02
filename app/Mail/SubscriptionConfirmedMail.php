<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SubscriptionConfirmedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Tenant $tenant,
        public readonly Package $package,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.$this->package->name.' subscription is active!',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.subscription-confirmed',
            with: [
                'user' => $this->user,
                'tenant' => $this->tenant,
                'package' => $this->package,
                'appName' => config('app.name'),
                'dashboardUrl' => route('tenant.dashboard', ['subdomain' => $this->tenant->slug]),
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
