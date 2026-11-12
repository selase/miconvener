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
        return app(TenantMailIdentity::class)->tenantEnvelope(isset($this->receipt->snapshot['digital_licence']) ? 'Your badge template licence receipt' : 'Your store order receipt', Tenant::query()->findOrFail($this->receipt->tenant_id));
    }

    public function headers(): Headers
    {
        $headers = app(TenantMailIdentity::class)->tenantHeaders(Tenant::query()->findOrFail($this->receipt->tenant_id));
        $headers->messageId = 'store-'.$this->receipt->id.'@'.parse_url((string) config('app.url'), PHP_URL_HOST);

        return $headers;
    }

    public function content(): Content
    {
        $snapshot = $this->receipt->snapshot;
        $charge = \App\Models\VenueBookingCharge::query()->with('booking')->where('order_id', $this->receipt->order_id)->first();
        $isVenue = $charge !== null;
        if ($isVenue) {
            $snapshot['seller'] = is_array($snapshot['seller']) ? $snapshot['seller']['name'] : $snapshot['seller'];
            $snapshot['lines'] = array_map(fn (array $line): array => $line + ['amount_pesewas' => $line['amount_pesewas'] ?? $line['line_amount_pesewas']], $snapshot['lines']);
            $snapshot['receipt_terms'] ??= '';
        }

        return new Content(markdown: 'emails.store-order-receipt', with: [
            'receipt' => $snapshot,
            'isVenue' => $isVenue,
            'isDigital' => isset($snapshot['digital_licence']),
            'orderUrl' => isset($snapshot['digital_licence'])
                ? route('badge-template-purchases.show', \App\Models\BadgeTemplatePurchase::query()->where('order_id', $this->receipt->order_id)->firstOrFail()->id)
                : ($isVenue ? route('marketplace.bookings.show', $charge->booking->booking_reference) : route('store.orders.show', $this->receipt->order_id)),
        ]);
    }
}
