<x-mail::message>
# {{ $announcement->subject }}

{!! nl2br(e($announcement->body)) !!}

<x-mail::button :url="$unsubscribeUrl">Stop announcements</x-mail::button>

This changes announcements from {{ $announcement->tenant->name }} for this address only. Receipts and other service notices are unaffected.
</x-mail::message>
