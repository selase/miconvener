<x-mail::message>
# Your seller marked your order ready

{{ $notice['seller'] }} has marked your order ready. Check your order for its current status before travelling or arranging handover.

@if (($notice['method'] ?? 'collection') === 'delivery')
## Seller-arranged delivery
Your order is ready for dispatch. Confirm delivery arrangements with the seller. When the recipient is present, open your order to request a delivery verification code.
@else
## Collect from the seller
Your order remains available until collected. Open your order to request a collection pass when you are ready for handover.
@endif

{{ $notice['instructions'] ?? '' }}

<x-mail::button :url="$orderUrl">Check your order</x-mail::button>

A verification code lasts 15 minutes after you request it. Expiry does not cancel your order. This email does not contain a handover code.

{{ $notice['support_contact'] ?? '' }}
</x-mail::message>
