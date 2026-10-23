<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\StoreReceipt;
use App\Models\Tenant;
use App\Services\Mail\TenantMailIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

final class StoreOrderReceipt extends Mailable
{
    public function __construct(private readonly StoreReceipt $receipt) {}

    public function envelope(): Envelope
    {
        return app(TenantMailIdentity::class)->tenantEnvelope('Your store order receipt', Tenant::query()->findOrFail($this->receipt->tenant_id));
    }

    public function headers(): Headers
    {
        $headers = app(TenantMailIdentity::class)->tenantHeaders(Tenant::query()->findOrFail($this->receipt->tenant_id));
        $headers->messageId = 'store-'.$this->receipt->id.'@'.parse_url((string) config('app.url'), PHP_URL_HOST);

        return $headers;
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.store-order-receipt', with: ['receipt' => $this->receipt->snapshot, 'orderUrl' => route('store.orders.show', $this->receipt->order_id)]);
    }
}
