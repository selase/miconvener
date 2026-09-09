<x-mail::message>
# Your ticket has been transferred

The ticket for **{{ $event->name }}** previously held by {{ $previousName }} now belongs to:

- **Name:** {{ $newName }}
- **Email:** {{ $newEmail }}

You no longer have access to it, and the entry code issued to you will not admit you.

If this was not you, contact the organizer straight away.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
