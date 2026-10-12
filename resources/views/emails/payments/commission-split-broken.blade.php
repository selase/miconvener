<x-mail::message>
# Paystack payments are paused

Attendees can't pay **{{ $tenant->name }}** online through Paystack right now, because the commission subaccount on your Paystack account no longer checks out:

> {{ $reason }}

Open your payment settings, fix or re-enter the subaccount code, and choose **Check again**. Payments resume as soon as it passes. Card payments through Stripe and offline payment options are not affected.

<x-mail::button :url="$settingsUrl">
Open payment settings
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
