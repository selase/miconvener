<x-mail::message>
# Ghana price needs a look

**{{ $tenant->name }}** paid the Ghana price with a card issued outside Ghana.

- **Payment:** {{ $reference }}
- **What happened:** {{ $outcome === 'top_up_failed' ? 'the difference to the USD price could not be charged to the same card' : 'there was no USD price to compare to, so nothing was charged or changed' }}
- **Detail:** {{ $detail }}

The organisation keeps what it paid for.@if ($outcome === 'top_up_failed') Its pricing is now USD, and its next renewal will be at the USD price.@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
