<x-mail::message>
# Your payment didn't go through

We couldn't take **{{ $amountDisplay }}** for the {{ config('app.name') }} subscription for **{{ $tenant->name }}**.

Your plan is still active. Nothing has been canceled.

@if ($retryOn)
We'll try the payment again on **{{ $retryOn }}**. To avoid an interruption, make sure your card or mobile money account can cover it before then.
@else
To avoid an interruption, make sure your card or mobile money account can cover the payment, or update your payment method.
@endif

@if ($movesToFreeOn)
If the payment still hasn't gone through by **{{ $movesToFreeOn }}**, your organization moves to the Free plan.
@endif

<x-mail::button :url="$billingUrl">
Review billing
</x-mail::button>

If you've already sorted this out, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
