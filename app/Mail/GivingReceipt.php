<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\GivingReceiptNotice;
use App\Models\Tenant;
use App\Services\Mail\TenantMailIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

final class GivingReceipt extends Mailable
{
    public function __construct(public GivingReceiptNotice $notice) {}

    public function envelope(): Envelope
    {
        return app(TenantMailIdentity::class)->tenantEnvelope('Your contribution receipt', Tenant::query()->findOrFail($this->notice->tenant_id));
    }

    public function headers(): Headers
    {
        $headers = app(TenantMailIdentity::class)->tenantHeaders(Tenant::query()->findOrFail($this->notice->tenant_id));
        $headers->messageId = 'giving-'.$this->notice->id.'@'.parse_url((string) config('app.url'), PHP_URL_HOST);

        return $headers;
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.giving-receipt', with: ['receipt' => $this->notice->snapshot]);
    }
}
