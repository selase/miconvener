<x-mail::message>
# Hi {{ $registration->full_name }}

We have received your proof of payment for **{{ $event->name }}**.

The organizer will review your payment slip against their account statement. As soon as your payment is verified, your official ticket and entry QR code will be emailed to you.

<x-mail::button :url="$statusUrl">
Check payment status
</x-mail::button>

If you have any questions or need to submit an updated receipt, you can visit the link above at any time.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
