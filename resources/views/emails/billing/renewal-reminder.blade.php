<x-mail::message>
@if ($overdue)
# Your payment is overdue

The **{{ $planName }}** plan for **{{ $tenant->name }}** was due for renewal on **{{ $dueOn }}**, and we haven't received the payment.

Everything is still working for now.
@if ($movesToFreeOn)
If the payment hasn't come through by **{{ $movesToFreeOn }}**, your organization moves to the Free plan.
@endif
@else
# Your plan renews on {{ $dueOn }}

The **{{ $planName }}** plan for **{{ $tenant->name }}** renews on **{{ $dueOn }}**. Your payment method can't be charged automatically, so please pay for the next period before then.
@endif

<x-mail::panel>
**{{ $amountDisplay }}**<br>
{{ $planName }} plan
</x-mail::panel>

<x-mail::button :url="$payUrl">
Pay {{ $amountDisplay }}
</x-mail::button>

If you pay by card, future renewals can usually be charged automatically.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
