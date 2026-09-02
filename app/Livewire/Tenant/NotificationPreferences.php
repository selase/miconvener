<?php

declare(strict_types=1);

namespace App\Livewire\Tenant;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class NotificationPreferences extends Component
{
    /** @var array<string, array{label: string, description: string}> */
    public const array CHANNELS = [
        'queue_turn_alerts' => [
            'label' => 'Queue Turn Alerts',
            'description' => 'Email when a queue turn is called or needs attention.',
        ],
        'service_requests' => [
            'label' => 'Service Requests',
            'description' => 'Updates when new requests or follow-ups are created.',
        ],
        'billing_updates' => [
            'label' => 'Billing & Usage',
            'description' => 'Invoices, balance changes, and usage threshold alerts.',
        ],
        'security_updates' => [
            'label' => 'Security & Access',
            'description' => 'Important sign-in, password, and access notifications.',
        ],
        'weekly_digest' => [
            'label' => 'Weekly Digest',
            'description' => 'A weekly summary of queue activity and account usage.',
        ],
    ];

    /** @var array<string, bool> */
    public array $preferences = [];

    public function mount(): void
    {
        $stored = auth()->user()->notification_preferences ?? [];

        foreach (array_keys(self::CHANNELS) as $key) {
            $this->preferences[$key] = $stored[$key] ?? ($key !== 'weekly_digest');
        }
    }

    public function save(): void
    {
        auth()->user()->update([
            'notification_preferences' => $this->preferences,
        ]);

        session()->flash('success', 'Notification preferences saved.');
    }

    public function render(): View
    {
        return view('livewire.tenant.notification-preferences');
    }
}
