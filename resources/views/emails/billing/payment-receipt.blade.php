<x-mail::message>
# Payment received

Thank you. We've received your payment for **{{ $tenant->name }}**.

<x-mail::panel>
**{{ $amountDisplay }}**<br>
{{ $description }}
</x-mail::panel>

- **Paid on:** {{ $paidOn }}
@if ($paymentMethod)
- **Payment method:** {{ $paymentMethod }}
@endif
- **Reference:** {{ $reference }}

@if ($activePlan)
Your **{{ $activePlan }}** plan is active, and its features are available now.
@endif

<x-mail::button :url="$billingUrl">
View billing
</x-mail::button>

Keep this email as your receipt.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
