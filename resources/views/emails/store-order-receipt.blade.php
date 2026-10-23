<x-mail::message>
# Your store order receipt

{{ $receipt['seller'] }} · Order {{ $receipt['number'] }}

@foreach ($receipt['lines'] as $line)
{{ $line['title'] }} · Quantity {{ $line['quantity'] }} · {{ $receipt['currency'] }} {{ number_format($line['amount_pesewas'] / 100, 2) }}

@endforeach
@if (isset($receipt['buyer_fee_pesewas']))
Checkout fee: {{ $receipt['currency'] }} {{ number_format($receipt['buyer_fee_pesewas'] / 100, 2) }}

@endif
**Total: {{ $receipt['currency'] }} {{ number_format($receipt['total_pesewas'] / 100, 2) }}**

## Collection
{{ $receipt['collection_terms'] }}

## Refunds and support
{{ $receipt['refund_terms'] }}

{{ $receipt['support_contact'] }}

{{ $receipt['receipt_terms'] }}

<x-mail::button :url="$orderUrl">Read your order</x-mail::button>

Verify the original buyer email to open your private record. This receipt is a payment record, not a VAT invoice or proof of collection. Refund updates appear on your order.
</x-mail::message>
