<x-mail::message>
# Thank you, {{ $memberName }}

{{ $organisationName }} has received your dues payment.

<x-mail::table>
| Period | Amount |
| :----- | -----: |
@foreach ($charges as $charge)
| {{ $charge->period_start->format('j M Y') }} – {{ $charge->period_end->format('j M Y') }} | {{ $charge->currency }} {{ number_format($charge->amount / 100, 2) }} |
@endforeach
| **Total** | **{{ $currency }} {{ number_format($total / 100, 2) }}** |
</x-mail::table>

- **Payment reference:** {{ $reference }}
- **Paid on:** {{ optional($charges->first()->paid_at)->format('j F Y') ?? now()->format('j F Y') }}

Keep this email as your receipt. If anything here looks wrong, reply to this email to reach {{ $organisationName }}.

Thanks,<br>
{{ $organisationName }}
</x-mail::message>
