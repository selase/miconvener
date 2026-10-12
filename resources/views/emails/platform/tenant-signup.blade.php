<x-mail::message>
# {{ $onboarded ? 'Finished onboarding' : 'New signup' }}: {{ $tenant->name }}

@if ($onboarded)
**{{ $tenant->name }}** has completed onboarding and can start using MiConvener.
@else
**{{ $tenant->name }}** has signed up. They have not finished onboarding yet.
@endif

<x-mail::table>
| | |
|:--|:--|
| Organisation | {{ $tenant->name }} |
| Contact email | {{ $tenant->email ?: '—' }} |
| Phone | {{ $tenant->phone_number ?: '—' }} |
| Plan | {{ $plan ?: 'None yet' }} |
| Signed up | {{ $tenant->created_at?->format('j M Y, H:i') }} |
@if ($onboarded)
| Finished onboarding | {{ $tenant->onboarding_completed_at?->format('j M Y, H:i') ?? 'Just now' }} |
@endif
</x-mail::table>

<x-mail::button :url="$signupUrl">
Open signup
</x-mail::button>

Log a follow-up there so the team knows who has already reached out.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
