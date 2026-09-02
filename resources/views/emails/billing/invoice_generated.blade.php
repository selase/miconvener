<x-mail::message>
# Hello,

A new invoice has been generated for your account for the period **{{ $invoice->period_start->format('M d, Y') }}** to **{{ $invoice->period_end->format('M d, Y') }}**.

**Invoice Number:** {{ $invoice->number }}  
**Total Amount:** {{ number_format((float)$invoice->total, 2) }} {{ $invoice->currency }}  
**Due Date:** {{ $invoice->due_at->format('M d, Y') }}

<x-mail::button :url="route('billing.index')">
View & Pay Invoice
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
