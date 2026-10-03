<x-mail::message>
# Speaker Invitation: {{ $event->name }}

Hello {{ $speaker->name }},

You have been invited as a featured speaker for **{{ $event->name }}**.

Please use your dedicated Speaker Portal to confirm your attendance, review your assigned sessions, update your bio and headshot, and upload your presentation slides.

<x-mail::button :url="$portalUrl">
Open Speaker Portal
</x-mail::button>

If you have any questions, you can reply directly to this email to reach the organizers.

Thanks,<br>
{{ $event->tenant?->name ?? config('app.name') }}
</x-mail::message>
