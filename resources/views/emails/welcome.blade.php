<x-mail::message>
# Welcome to QNotify, {{ $user->first_name }}!

We're excited to have you on board. Your account is ready and your workspace is being set up.

Here's what you can do with QNotify:

- **Queue management** — create branch queues and route requests from one dashboard
- **Turn alerts** — keep customers and staff updated with live notifications
- **Branch visibility** — monitor queue volume, wait times, and service activity
- **Team coordination** — assign staff, track follow-ups, and keep service moving
- **Billing controls** — manage subscriptions, usage, and account settings in one place

<x-mail::button :url="$loginUrl">
Go to your dashboard
</x-mail::button>

If you have any questions, just reply to this email — we're happy to help.

Thanks,<br>
The QNotify Team
</x-mail::message>
