<?php

declare(strict_types=1);

use App\Mail\Billing\SubscriptionEndedMail;
use App\Mail\Events\EventBlastMail;
use App\Mail\Events\EventTicketLink;
use App\Models\Event;
use App\Models\EventBlast;
use App\Models\EventRegistration;
use App\Models\SentEmail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

/**
 * Event emails sent straight to attendees (tickets, invites...) used to leave
 * no trace, so support could not say whether someone got their ticket. Each
 * is now recorded as it is sent and shows up in Message delivery.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->tenant = Tenant::factory()->create(['name' => 'Accra Summit', 'isolation_mode' => 'shared']);
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->registration = EventRegistration::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'email' => 'Ama@Example.com',
    ]);
});

test('a ticket email is recorded against its organisation and event when it is sent', function (): void {
    Mail::to('Ama@Example.com')->send(new EventTicketLink($this->registration));

    $sent = SentEmail::query()->sole();

    expect($sent->tenant_id)->toBe($this->tenant->id)
        ->and($sent->event_id)->toBe($this->event->id)
        ->and($sent->recipient_email)->toBe('ama@example.com')
        ->and($sent->mailable)->toBe('EventTicketLink')
        ->and($sent->subject)->not->toBeEmpty();
});

test('announcements and mail without an organisation are not recorded twice or at all', function (): void {
    $blast = EventBlast::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id]);
    Illuminate\Support\Facades\URL::defaults(['subdomain' => $this->tenant->slug]);

    Mail::to('ama@example.com')->send(new EventBlastMail($blast, 'Ama', $this->registration->id));
    Mail::to('ama@example.com')->send(new SubscriptionEndedMail($this->tenant, 'Starter', 'https://acme.test/billing'));

    expect(SentEmail::query()->count())->toBe(0);
});

test('a recorded ticket email is found by Message delivery', function (): void {
    Mail::to('ama@example.com')->send(new EventTicketLink($this->registration));
    $superadmin = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $superadmin->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());

    $this->actingAs($superadmin)
        ->get(route('admin.messages.index', ['q' => 'AMA@example.com']))
        ->assertOk()
        ->assertSee('Accra Summit')
        ->assertSee('event ticket link');
});
