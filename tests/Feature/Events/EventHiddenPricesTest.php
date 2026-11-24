<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventFormField;
use App\Models\EventRegistration;
use App\Models\EventTicketType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * @param  array<string, mixed>  $attributes
 * @return array{0: Event, 1: EventTicketType, 2: EventTicketType, 3: string}
 */
function pricedApplicationEvent(array $attributes = []): array
{
    [$tenant] = eventHost('bootcamp');
    $host = eventSubdomainHost('bootcamp');
    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'ticket_price' => 70000,
        'currency' => 'GHS',
        'requires_approval' => true,
        'hide_prices_until_approved' => true,
        ...$attributes,
    ]);
    $mentorship = EventTicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'name' => 'Mentorship', 'price' => 70000, 'is_active' => true, 'access_code' => null, 'capacity' => null]);
    $thesis = EventTicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'name' => 'Mentorship + Thesis support', 'price' => 120000, 'is_active' => true, 'access_code' => null, 'capacity' => null]);

    return [$event, $mentorship, $thesis, $host];
}

test('an approval event that hides prices sends no amounts to the public page', function () {
    [$event, , , $host] = pricedApplicationEvent();
    EventFormField::create([
        'tenant_id' => $event->tenant_id,
        'event_id' => $event->id,
        'label' => 'Track',
        'field_key' => 'track',
        'field_type' => 'select',
        'is_required' => false,
        'sort_order' => 1,
        'options' => [['label' => 'Web', 'value' => 'web', 'price' => 5000, 'is_override' => false]],
    ]);

    $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->where('event.prices_hidden', true)
            ->where('event.ticket_price', null)
            ->where('event.ticket_types.0.price', null)
            ->where('event.ticket_types.1.price', null)
            ->where('event.form_fields.0.options', [['label' => 'Web', 'value' => 'web']]));
});

test('hiding prices has no effect on an event that does not review registrations', function () {
    [$event, , , $host] = pricedApplicationEvent(['requires_approval' => false]);

    $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->where('event.prices_hidden', false)
            ->where('event.ticket_price', 70000)
            ->where('event.ticket_types.0.price', 70000));
});

test('an applicant who never saw a price is still charged for the option they chose once approved', function () {
    Mail::fake();
    [$event, , $thesis, $host] = pricedApplicationEvent();

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'title' => 'Ms',
        'first_name' => 'Esi',
        'last_name' => 'Owusu',
        'email' => 'esi@example.com',
        'ticket_type_id' => $thesis->id,
    ], ['HTTP_HOST' => $host])->assertSessionHasNoErrors();

    $registration = EventRegistration::where('email', 'esi@example.com')->sole();
    expect($registration->status)->toBe(EventRegistration::STATUS_PENDING_APPROVAL)
        ->and($registration->amount)->toBe(120000)
        ->and($registration->ticket_type_id)->toBe($thesis->id);
});

test('organisers can turn hidden prices on from the event form', function () {
    [$tenant, $user] = eventHost('bootcamp');
    $host = eventSubdomainHost('bootcamp');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'requires_approval' => true]);

    $this->actingAs($user)->put("http://{$host}/events/{$event->id}", [
        'name' => $event->name,
        'status' => Event::STATUS_PUBLISHED,
        'starts_at' => $event->starts_at->toDateTimeString(),
        'ends_at' => $event->ends_at->toDateTimeString(),
        'timezone' => 'Africa/Accra',
        'location_type' => Event::LOCATION_VIRTUAL,
        'virtual_link' => 'https://meet.example.com/abc',
        'ticket_price' => 0,
        'currency' => 'GHS',
        'requires_approval' => '1',
        'hide_prices_until_approved' => '1',
    ], ['HTTP_HOST' => $host])->assertSessionHasNoErrors()->assertSessionMissing('error');

    expect($event->fresh()->hide_prices_until_approved)->toBeTrue()
        ->and($event->fresh()->pricesHiddenFromPublic())->toBeTrue();
});
