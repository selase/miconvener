<x-mail::message>
# New quote request

**{{ $quote->planner_name }}** has asked {{ $quote->shop->name }} for a quote on **{{ $quote->listing->title }}**.

**Reference:** `{{ $quote->quote_reference }}`

@if($quote->event_title || $quote->event_date || $quote->guest_count)
- **Event:** {{ $quote->event_title ?? 'Not given' }}
@if($quote->event_date)
- **Date:** {{ $quote->event_date->format('l, F j, Y') }}
@endif
@if($quote->guest_count)
- **Guests:** {{ number_format($quote->guest_count) }}
@endif
@endif
@if($quote->location_address)
- **Location:** {{ $quote->location_address }}
@endif

### What they need
> {{ $quote->requirements_description }}

<x-mail::button :url="$inboxUrl">
Prepare your quote
</x-mail::button>

Contact: {{ $quote->planner_email }}@if($quote->planner_phone) · {{ $quote->planner_phone }}@endif

</x-mail::message>
