<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\StoreFulfilmentNotice;
use App\Models\Tenant;
use App\Services\Mail\TenantMailIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

final class StoreOrderReady extends Mailable
{
    public function __construct(private readonly StoreFulfilmentNotice $notice) {}

    public function envelope(): Envelope
    {
        return app(TenantMailIdentity::class)->tenantEnvelope('Your store order is ready', Tenant::query()->findOrFail($this->notice->tenant_id));
    }

    public function headers(): Headers
    {
        $headers = app(TenantMailIdentity::class)->tenantHeaders(Tenant::query()->findOrFail($this->notice->tenant_id));
        $headers->messageId = 'store-ready-'.$this->notice->id.'@'.parse_url((string) config('app.url'), PHP_URL_HOST);

        return $headers;
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.store-order-ready', with: ['notice' => $this->notice->snapshot, 'orderUrl' => mb_rtrim((string) config('app.url'), '/').route('store.orders.show', $this->notice->order_id, false)]);
    }
}
