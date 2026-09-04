<x-mail::message>
# Your {{ $package->name }} plan is active!

Hi {{ $user->first_name }},

Your payment was successful and your **{{ $package->name }}** subscription for **{{ $tenant->name }}** is now active.

<x-mail::panel>
**Subscription details**

- **Plan:** {{ $package->name }}
- **Organization:** {{ $tenant->name }}
- **Status:** Active
</x-mail::panel>

You can now access all {{ $package->name }} features from your dashboard. Invite your team, configure roles, and start building.

<x-mail::button :url="$dashboardUrl">
Go to your dashboard
</x-mail::button>

**Need help getting started?** Check out our quick-start guide or reply to this email and we'll walk you through it.

Thanks,<br>
The {{ config('app.name') }} Team

---

<small>Manage your subscription any time from your billing settings.</small>
</x-mail::message>
