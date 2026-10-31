<x-mail::message>
# {{ $kind === 'withdrawn' ? 'The host withdrew your quote' : 'Your final venue quote is ready' }}

Dear {{ $booking->planner_name }},

@if ($kind === 'withdrawn')
**{{ $booking->shop->name }}** withdrew the quote for **{{ $booking->listing->title }}**.@if ($withdrawnReason) **Reason from the host:** {{ $withdrawnReason }}@endif You have not been charged. A new quote may follow.
@else
**{{ $booking->shop->name }}** has sent a final quote for **{{ $booking->listing->title }}**. Nothing is charged until you accept it and choose to pay.

- **Hire and extras:** GHS {{ number_format(($quote->rental_pesewas + $quote->extras_pesewas) / 100, 2) }}
- **First payment:** GHS {{ number_format($quote->initial_pesewas / 100, 2) }}
@if ($quote->balance_pesewas > 0)
- **Balance:** GHS {{ number_format($quote->balance_pesewas / 100, 2) }}, due {{ $balanceDueAt?->format('l, F j, Y — g:i A') }} ({{ $timezone }})
@endif
@if ($quote->security_pesewas > 0)
- **Refundable security:** GHS {{ number_format($quote->security_pesewas / 100, 2) }}, due {{ $securityDueAt?->format('l, F j, Y — g:i A') }} ({{ $timezone }})
@endif

**Accept by:** {{ $expiresAt->format('l, F j, Y — g:i A') }} ({{ $timezone }}). After that the quote expires.

**Cancellation and refunds:** {{ $quote->cancellation_terms }}
@endif

**Reference:** `{{ $booking->booking_reference }}`

You will be asked to verify this email address before the booking opens.

<x-mail::button :url="$bookingUrl">
View your booking
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
