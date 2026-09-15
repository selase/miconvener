<x-mail::message>
# Your plan has ended

@if ($previousPlan)
The **{{ $previousPlan }}** plan for **{{ $tenant->name }}** has ended, and your organization is now on the Free plan.
@else
The paid plan for **{{ $tenant->name }}** has ended, and your organization is now on the Free plan.
@endif

Your events, registrations and records are still here. Features the Free plan doesn't include, such as paid tickets, are switched off until you choose a plan again.

<x-mail::button :url="$billingUrl">
Choose a plan
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
