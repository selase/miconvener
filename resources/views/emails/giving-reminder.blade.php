<x-mail::message>
# Your optional giving reminder

You asked {{ $organisation }} to remind you about **{{ $fund }}**.

<x-mail::button :url="$giveUrl">View fund and choose whether to give</x-mail::button>

Giving is voluntary. Nothing is overdue, and no money has been charged automatically.

[Stop these giving reminders]({{ $unsubscribeUrl }}) at any time. You can also switch off organisation marketing emails in your MiConvener notification preferences.
</x-mail::message>
