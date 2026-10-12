<?php

declare(strict_types=1);

namespace App\Mail\Platform;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells MiConvener's staff that an organisation signed up or finished
 * onboarding, with enough to follow up and a link to its signup record.
 */
final class TenantSignupMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public const string STAGE_SIGNED_UP = 'signed_up';

    public const string STAGE_ONBOARDED = 'onboarded';

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $stage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->stage === self::STAGE_ONBOARDED
            ? "{$this->tenant->name} finished onboarding"
            : "New organisation signed up: {$this->tenant->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.platform.tenant-signup', with: [
            'onboarded' => $this->stage === self::STAGE_ONBOARDED,
            'plan' => $this->tenant->package?->name,
            'signupUrl' => route('admin.signups.show', $this->tenant->uuid),
        ]);
    }
}
