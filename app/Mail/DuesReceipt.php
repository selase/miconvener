<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\DuesCharge;
use App\Models\Member;
use App\Services\Mail\TenantMailIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * A member's receipt for dues paid online. It arrives under the
 * organisation's name and replies reach the organisation, because the member
 * paid the organisation, not MiConvener.
 */
final class DuesReceipt extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  Collection<int, DuesCharge>  $charges  The charges this payment settled.
     */
    public function __construct(
        public Member $member,
        public Collection $charges,
        public string $reference,
    ) {}

    public function envelope(): Envelope
    {
        return app(TenantMailIdentity::class)->tenantEnvelope("Receipt: {$this->member->tenant->name} dues", $this->member->tenant);
    }

    public function headers(): Headers
    {
        return app(TenantMailIdentity::class)->tenantHeaders($this->member->tenant);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.dues-receipt',
            with: [
                'organisationName' => $this->member->tenant->name,
                'memberName' => $this->member->name,
                'charges' => $this->charges,
                'reference' => $this->reference,
                'currency' => (string) ($this->charges->first()->currency ?? 'GHS'),
                'total' => (int) $this->charges->sum('amount'),
            ],
        );
    }
}
