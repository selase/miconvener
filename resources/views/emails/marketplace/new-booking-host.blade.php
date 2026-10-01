<x-mail::message>
# New Venue Booking Request

You have received a new booking request for **{{ $booking->listing->title }}** at {{ $booking->shop->name }}.

**Booking Reference:** `{{ $booking->booking_reference }}`  
**Status:** {{ strtoupper(str_replace('_', ' ', $booking->status)) }}

---

### Event Schedule & Specifications
- **Event Type:** {{ $booking->event_type }}
- **Start Time:** {{ $booking->starts_at->format('l, F j, Y — g:i A') }}
- **End Time:** {{ $booking->ends_at->format('l, F j, Y — g:i A') }}
- **Duration:** {{ $booking->duration_units }} {{ $booking->time_slot_type === 'hourly' ? 'hours' : 'days' }}
- **Expected Attendees:** {{ $booking->guest_count }} guests
- **Seating Arrangement:** {{ ucfirst($booking->layout_style) }}

---

### Event Planner Contact
- **Name:** {{ $booking->planner_name }}
- **Email:** {{ $booking->planner_email }}
@if($booking->planner_phone)
- **Phone:** {{ $booking->planner_phone }}
@endif
@if($booking->planner_company)
- **Organization / Agency:** {{ $booking->planner_company }}
@endif

@if($booking->special_requests)
---
### Special Logistics & Equipment Requirements
> {{ $booking->special_requests }}
@endif

---

### Financial Breakdown
- **Base Rental Fee:** GHS {{ number_format($booking->rental_amount_pesewas / 100, 2) }}
@if($booking->security_deposit_pesewas > 0)
- **Security Deposit:** GHS {{ number_format($booking->security_deposit_pesewas / 100, 2) }}
@endif
- **Total Amount:** **GHS {{ number_format($booking->total_amount_pesewas / 100, 2) }}**
- **Reservation Deposit:** GHS {{ number_format($booking->deposit_required_pesewas / 100, 2) }} ({{ ucfirst(str_replace('_', ' ', $booking->payment_status)) }})

<x-mail::button :url="$inboxUrl">
View in Venue Inquiries Inbox
</x-mail::button>

Please review and respond promptly in your venue manager console.

Regards,  
**MiConvener Marketplace Operations**
</x-mail::message>
