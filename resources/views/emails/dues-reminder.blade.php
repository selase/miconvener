<x-mail::message>
# {{ $member->tenant->name }} dues

Hello {{ $member->name }},

You have {{ $charges->count() }} open charges. Oldest due date: {{ $oldestDue }}.

@foreach ($totals as $currency => $amount)
**Total open: {{ $currency }} {{ number_format($amount / 100, 2) }}**
@endforeach

<x-mail::table>
| Period | Due date | Amount |
| :--- | :--- | ---: |
@foreach ($charges as $charge)
| {{ $charge->period_start->toDateString() }} – {{ $charge->period_end->toDateString() }} | {{ $charge->due_on->toDateString() }} | {{ $charge->currency }} {{ number_format($charge->amount / 100, 2) }} |
@endforeach
</x-mail::table>

<x-mail::button :url="$payLink">View charges and pay</x-mail::button>

{{ $member->tenant->name }}
</x-mail::message>
