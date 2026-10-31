<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\DuesCharge;
use App\Models\Member;
use App\Services\Mail\TenantMailIdentity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** Sent inside the reminder job so reservation status reflects mail acceptance. */
final class DuesReminder extends Mailable
{
    /** @param Collection<int, DuesCharge> $charges */
    public function __construct(public Member $member, public Collection $charges) {}

    public function envelope(): Envelope
    {
        return app(TenantMailIdentity::class)->tenantEnvelope("Dues reminder: {$this->member->tenant->name}", $this->member->tenant);
    }

    public function headers(): Headers
    {
        return app(TenantMailIdentity::class)->tenantHeaders($this->member->tenant);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.dues-reminder', with: [
            'payLink' => route('public.dues.show', ['subdomain' => $this->member->tenant->slug, 'token' => $this->member->pay_token]),
            'totals' => $this->charges->groupBy('currency')->map(fn ($charges): int => (int) $charges->sum('amount')),
            'oldestDue' => $this->charges->min('due_on')->toDateString(),
        ]);
    }
}
