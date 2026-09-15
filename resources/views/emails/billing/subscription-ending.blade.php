<x-mail::message>
# Your {{ $planName }} plan won't renew

The **{{ $planName }}** plan for **{{ $tenant->name }}** has been set not to renew.

@if ($endsOn)
Everything keeps working until **{{ $endsOn }}**. After that, your organization moves to the Free plan.
@else
Everything keeps working until the end of your current billing period. After that, your organization moves to the Free plan.
@endif

Your events, registrations and records stay. Features the Free plan doesn't include, such as paid tickets, will switch off.

<x-mail::button :url="$billingUrl">
Review your plan
</x-mail::button>

If you meant to cancel, there's nothing more to do.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
