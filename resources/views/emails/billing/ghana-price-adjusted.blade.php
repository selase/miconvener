<x-mail::message>
# About the price you paid

You paid for **{{ $item }}** at the Ghana price using a card that was not issued in Ghana. The Ghana price is for organisations in Ghana, so the price for you is in US dollars, charged in cedis at the Bank of Ghana rate on the day.

@if ($outcome === 'topped_up')
We charged the difference, **{{ $amountDisplay }}**, to the same card. Nothing else is needed from you. From now on your prices are shown in US dollars.
@else
We tried to charge the difference, **{{ $amountDisplay }}**, to the same card and it did not go through. Your {{ $item }} stays active for the period you paid for. From now on your prices are shown in US dollars, and your next renewal will be at the US dollar price.
@endif

If you are an organisation in Ghana, you can pay with Ghana mobile money or a Ghana card, or add a verified Ghana payout account, and the Ghana price applies. Reply to this email and we will help.

<x-mail::button :url="$billingUrl">
View billing
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
