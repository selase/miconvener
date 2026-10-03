<x-mail::message>
# New Payment Proof Submitted

**{{ $registration->full_name }}** has submitted proof of payment for **{{ $event->name }}**.

- **Ticket**: {{ $registration->ticketType?->name ?? 'Standard Ticket' }}
- **Amount**: {{ $registration->currency }} {{ number_format($registration->amount / 100, 2) }}
- **Reference / Memo**: {{ $registration->offline_payment_reference ?: 'None provided' }}
- **Attendee Email**: {{ $registration->email }}
- **Attendee Phone**: {{ $registration->phone ?: 'None provided' }}

Please inspect the payment slip against your bank or mobile money statement and approve or reject the submission in your MiConvener console.

<x-mail::button :url="$consoleUrl">
Review payment in console
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
