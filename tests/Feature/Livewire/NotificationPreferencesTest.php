<?php

declare(strict_types=1);

use App\Livewire\Tenant\NotificationPreferences;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    setActiveTenantForTest($this->user);
});

it('renders the notification preferences component', function () {
    Livewire::test(NotificationPreferences::class)
        ->assertStatus(200);
});

it('shows all notification channels', function () {
    Livewire::test(NotificationPreferences::class)
        ->assertSee('Queue Turn Alerts')
        ->assertSee('Service Requests')
        ->assertSee('Billing & Usage')
        ->assertSee('Security & Access')
        ->assertSee('Weekly Digest');
});

it('defaults queue and account alerts to true and weekly digest to false', function () {
    Livewire::test(NotificationPreferences::class)
        ->assertSet('preferences.queue_turn_alerts', true)
        ->assertSet('preferences.service_requests', true)
        ->assertSet('preferences.billing_updates', true)
        ->assertSet('preferences.security_updates', true)
        ->assertSet('preferences.weekly_digest', false);
});

it('loads stored preferences from user model', function () {
    $this->user->update([
        'notification_preferences' => [
            'queue_turn_alerts' => false,
            'service_requests' => false,
            'billing_updates' => true,
            'security_updates' => true,
            'weekly_digest' => false,
        ],
    ]);

    Livewire::test(NotificationPreferences::class)
        ->assertSet('preferences.queue_turn_alerts', false)
        ->assertSet('preferences.service_requests', false)
        ->assertSet('preferences.weekly_digest', false)
        ->assertSet('preferences.billing_updates', true);
});

it('saves preferences to the user model', function () {
    Livewire::test(NotificationPreferences::class)
        ->set('preferences.weekly_digest', false)
        ->set('preferences.queue_turn_alerts', false)
        ->call('save');

    $prefs = $this->user->fresh()->notification_preferences;

    expect($prefs['weekly_digest'])->toBeFalse()
        ->and($prefs['queue_turn_alerts'])->toBeFalse();
});

it('persists multiple preference changes in a single save', function () {
    Livewire::test(NotificationPreferences::class)
        ->set('preferences.queue_turn_alerts', false)
        ->set('preferences.service_requests', false)
        ->set('preferences.weekly_digest', false)
        ->call('save');

    $prefs = $this->user->fresh()->notification_preferences;

    expect($prefs)->toMatchArray([
        'queue_turn_alerts' => false,
        'service_requests' => false,
        'weekly_digest' => false,
        'billing_updates' => true,
        'security_updates' => true,
    ]);
});

it('shows the browser push notification note', function () {
    Livewire::test(NotificationPreferences::class)
        ->assertSee('Browser Push Notifications');
});
