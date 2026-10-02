<x-mail::message>
# Custom Venue Quote from {{ $booking->shop->name }}

Dear {{ $booking->planner_name }},

The venue team at **{{ $booking->shop->name }}** has reviewed your inquiry and prepared a custom quotation for **{{ $booking->listing->title }}**.

**Inquiry Reference:** `{{ $booking->booking_reference }}`

---

### Quoted Schedule & Terms
- **Date & Time:** {{ $booking->starts_at->format('l, F j, Y — g:i A') }} to {{ $booking->ends_at->format('g:i A') }}
- **Attendees:** {{ $booking->guest_count }} guests ({{ ucfirst($booking->layout_style) }} layout)
- **Quoted Base Rental:** GHS {{ number_format($booking->rental_amount_pesewas / 100, 2) }}
@if($booking->security_deposit_pesewas > 0)
- **Security Deposit:** GHS {{ number_format($booking->security_deposit_pesewas / 100, 2) }}
@endif
- **Total Quoted Amount:** **GHS {{ number_format($booking->total_amount_pesewas / 100, 2) }}**
- **Required Deposit to Lock Calendar:** **GHS {{ number_format($booking->deposit_required_pesewas / 100, 2) }}**

@if($booking->host_notes)
---
### Notes from Host
> {{ $booking->host_notes }}
@endif

<x-mail::button :url="$checkoutUrl">
Review Quote & Pay Deposit
</x-mail::button>

If you have any questions, you can contact the host directly at {{ $booking->shop->email }} or {{ $booking->shop->phone }}.

Best regards,  
**{{ $booking->shop->name }} via MiConvener Marketplace**
</x-mail::message>
