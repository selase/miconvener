<x-mail::message>
# New Enterprise Lead Captured

A new prospect has submitted their interest via the Enterprise landing page.

**Prospect Details:**
- **Name:** {{ $lead->name }}
- **Email:** {{ $lead->email }}
- **Company:** {{ $lead->company ?? 'N/A' }}
- **IP Address:** {{ $lead->ip_address }}

**Message:**
{{ $lead->message }}

<x-mail::button :url="config('app.url') . '/admin/leads/' . $lead->id">
View Lead in Admin
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} Automated System
</x-mail::message>
