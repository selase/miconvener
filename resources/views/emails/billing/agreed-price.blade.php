<x-mail::message>
# Your {{ $planName }} price

The price for **{{ $tenant->name }}** on the **{{ $planName }}** plan is **{{ $price }}**.

@if ($alreadyPaying)
Your next renewal will be charged at this price. Nothing changes before then.
@else
Pay the first period from your Billing page to start the plan. After that it renews at this price, and we will remind you before each renewal.
@endif

<x-mail::button :url="$billingUrl">
Open Billing
</x-mail::button>

If this isn't what we agreed, reply to this email and we'll put it right.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
