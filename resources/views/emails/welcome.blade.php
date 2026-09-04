<x-mail::message>
# Welcome to {{ config('app.name') }}, {{ $user->first_name }}!

We're excited to have you on board. Your account is ready and your workspace is being set up.

Here's what you can do next:

- **Invite your team** — add members and assign tenant-scoped roles
- **Configure billing** — pick a plan, manage invoices, and track usage
- **Customise roles** — duplicate platform roles and tune permissions for your workflow
- **Watch usage** — see token, request, and active-user limits in real time
- **Audit everything** — review activity logs for compliance and forensics

<x-mail::button :url="$loginUrl">
Go to your dashboard
</x-mail::button>

If you have any questions, just reply to this email — we're happy to help.

Thanks,<br>
The {{ config('app.name') }} Team
</x-mail::message>
