<x-mail::message>
# {{ match ($action->action) {
    'requested' => 'We have your venue request',
    'approved', 'renewed' => 'The host is holding your dates',
    'declined' => 'The host could not accept your request',
    'released' => 'The host released your held dates',
    default => 'An update on your venue request',
} }}

Dear {{ $booking->planner_name }},

@if ($action->action === 'requested')
We have passed your request for **{{ $booking->listing->title }}** at **{{ $booking->shop->name }}** to the host. **Your dates are not reserved until the host approves.**
@elseif (in_array($action->action, ['approved', 'renewed'], true))
**{{ $booking->shop->name }}** has approved your request for **{{ $booking->listing->title }}** and is holding the dates **until {{ $holdUntil?->format('l, F j, Y — g:i A') }} ({{ $timezone }})**. This is a hold, not a confirmed booking, and payment is not available yet.
@elseif ($action->action === 'declined')
**{{ $booking->shop->name }}** could not accept your request for **{{ $booking->listing->title }}**.
@elseif ($action->action === 'released')
**{{ $booking->shop->name }}** has released the dates it was holding for **{{ $booking->listing->title }}**.
@endif

@if ($action->decision_reason && in_array($action->action, ['declined', 'released'], true))
**Reason from the host:** {{ $action->decision_reason }}
@endif

**Reference:** `{{ $booking->booking_reference }}`
**Requested time:** {{ $startsAt->format('l, F j, Y — g:i A') }} to {{ $endsAt->format('g:i A') }} ({{ $timezone }})

You will be asked to verify this email address before the request opens.

<x-mail::button :url="$bookingUrl">
View your request
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
