<x-mail::message>
# {{ $isVenue ? 'Your venue payment receipt' : 'Your store order receipt' }}

{{ $receipt['seller'] }} · Order {{ $receipt['number'] }}

@foreach ($receipt['lines'] as $line)
{{ $line['title'] }} · Quantity {{ $line['quantity'] }} · {{ $receipt['currency'] }} {{ number_format($line['amount_pesewas'] / 100, 2) }}

@endforeach
@foreach ($receipt['charges'] ?? [] as $charge)
{{ $charge['label'] }}: {{ $receipt['currency'] }} {{ number_format($charge['amount_pesewas'] / 100, 2) }}

@endforeach
@if (isset($receipt['production_terms']))
## Your agreed specification
{{ $receipt['customisation'] }}

{{ $receipt['production_terms'] }}

@endif
@if (!$isVenue && isset($receipt['buyer_fee_pesewas']))
Checkout fee: {{ $receipt['currency'] }} {{ number_format($receipt['buyer_fee_pesewas'] / 100, 2) }}

@endif
**Total: {{ $receipt['currency'] }} {{ number_format($receipt['total_pesewas'] / 100, 2) }}**

@unless ($isVenue)
## Collection
{{ $receipt['collection_terms'] }}
@endunless

## Refunds and support
{{ $receipt['refund_terms'] }}

{{ $receipt['support_contact'] }}

{{ $receipt['receipt_terms'] }}

<x-mail::button :url="$orderUrl">{{ $isVenue ? 'Read your booking' : 'Read your order' }}</x-mail::button>

Verify the original buyer email to open your private record. This receipt is a payment record, not a VAT invoice{{ $isVenue ? '.' : ' or proof of collection.' }} Refund updates appear on your {{ $isVenue ? 'booking' : 'order' }}.
</x-mail::message>
