<x-mail::message>
# Booking Confirmation & Receipt

Dear {{ $booking->planner_name }},

Your booking for **{{ $booking->listing->title }}** at **{{ $booking->shop->name }}** is confirmed.

**Booking Reference:** `{{ $booking->booking_reference }}`  
**Status:** {{ strtoupper(str_replace('_', ' ', $booking->status)) }} ({{ strtoupper(str_replace('_', ' ', $booking->payment_status)) }})

---

### Event Schedule & Location
- **Venue:** {{ $booking->shop->name }} — {{ $booking->listing->title }}
- **Address:** {{ $booking->shop->address }}, {{ $booking->shop->city }}, {{ $booking->shop->region }}
- **Date & Time:** {{ $booking->starts_at->format('l, F j, Y — g:i A') }} to {{ $booking->ends_at->format('g:i A') }}
- **Duration:** {{ $booking->duration_units }} {{ $booking->time_slot_type === 'hourly' ? 'hours' : 'days' }}
- **Seating Configuration:** {{ ucfirst($booking->layout_style) }} ({{ $booking->guest_count }} attendees)

---

### Payment Receipt
- **Rental Amount:** GHS {{ number_format($booking->rental_amount_pesewas / 100, 2) }}
@if($booking->security_deposit_pesewas > 0)
- **Security Deposit:** GHS {{ number_format($booking->security_deposit_pesewas / 100, 2) }}
@endif
- **Total Amount:** GHS {{ number_format($booking->total_amount_pesewas / 100, 2) }}
- **Amount Paid:** **GHS {{ number_format($booking->amount_paid_pesewas / 100, 2) }}**
@if($booking->paystack_reference)
- **Payment Reference:** `{{ $booking->paystack_reference }}`
@endif

---

### Venue Contact
- **Host Name:** {{ $booking->shop->name }}
- **Host Email:** {{ $booking->shop->email }}
- **Host Phone:** {{ $booking->shop->phone }}

<x-mail::button :url="$bookingUrl">
View Booking Details & Contract
</x-mail::button>

Thank you for choosing MiConvener Marketplace for your event.

Warm regards,  
**The MiConvener Team**
</x-mail::message>
