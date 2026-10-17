<x-mail::message>
# Thank you for your contribution

{{ $receipt['organisation'] }} acknowledges your contribution to **{{ $receipt['fund'] }}**.

**{{ $receipt['currency'] }} {{ number_format($receipt['amount'] / 100, 2) }}**

Receipt: {{ $receipt['receipt_number'] }}

Recorded: {{ $receipt['paid_at'] ? \Carbon\CarbonImmutable::parse($receipt['paid_at'])->setTimezone($receipt['timezone'])->format('d M Y, H:i T') : 'Not recorded' }}

This voluntary contribution creates no dues, pledge or outstanding balance. This acknowledgement does not claim tax deductibility. Later refunds or corrections are recorded separately; the original receipt is unchanged.

<x-mail::button :url="$historyUrl">Your contributions and receipts</x-mail::button>

You can verify this email address in MiConvener’s **My → Money** to see your contribution history and download receipts and statements.

This receipt does not subscribe you to announcements or giving reminders.
</x-mail::message>
