<x-mail::message>
# {{ $event->name }}

Dear {{ $recipientName }},

{!! nl2br(e($renderedBody)) !!}

@if($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionLabel ?? 'View Details' }}
</x-mail::button>
@endif

Best regards,  
**{{ $event->name }} Organizing Committee**  

<small style="color: #64748b; font-size: 11px;">
You are receiving this official conference notification regarding {{ $event->name }}. If you no longer wish to receive reminder notifications, you can manage your preferences on the attendee portal.
</small>
</x-mail::message>
