<x-mail::message>
# Your quote is ready

Dear {{ $quote->planner_name }},

**{{ $quote->shop->name }}** has prepared an itemized quote for **{{ $quote->listing->title }}**.

**Reference:** `{{ $quote->quote_reference }}`

- **Total:** GHS {{ number_format($quote->total_amount_pesewas / 100, 2) }}
- **Deposit to confirm:** GHS {{ number_format($quote->deposit_required_pesewas / 100, 2) }}
@if($quote->valid_until)
- **Valid until:** {{ $quote->valid_until->format('F j, Y g:i A') }}
@endif

@if($quote->vendor_notes)
### Notes from the vendor
> {{ $quote->vendor_notes }}
@endif

<x-mail::button :url="$quoteUrl">
Review the quote
</x-mail::button>

Questions about the quote can go to {{ $quote->shop->email }}@if($quote->shop->phone) or {{ $quote->shop->phone }}@endif.

</x-mail::message>
