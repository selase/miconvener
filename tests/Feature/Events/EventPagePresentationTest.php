<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function (): void {
    $this->seed([Database\Seeders\RoleSeeder::class, Database\Seeders\PermissionsSeeder::class]);
});

function presentationHost(Tenant $tenant): string
{
    return $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');
}

test('public event presentation exposes an anonymous issued ticket count and only explicit public organiser fields', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'public-design', 'email' => 'private-billing@example.test', 'meta' => [
        'private_secret' => 'must-not-be-public',
        'public_profile' => ['description' => 'Our public introduction.', 'contact_email' => 'events@example.test', 'website' => 'https://example.test', 'instagram' => 'https://instagram.com/example', 'facebook' => 'javascript:alert(1)', 'unknown' => 'private-value'],
    ]]);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->confirmed()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'full_name' => 'Never Publish This Attendee']);
    EventRegistration::factory()->checkedIn()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    EventRegistration::factory()->confirmed()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'ticket_code' => null]);
    $other = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $other->id]);
    $host = presentationHost($tenant);
    $response = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host])->assertOk();
    $props = $response->viewData('page')['props'];
    expect($props['event']['going_count'])->toBe(2)
        ->and($props['org']['description'])->toBe('Our public introduction.')
        ->and($props['org']['contact_email'])->toBe('events@example.test')
        ->and($props['org']['links'])->toBe(['website' => 'https://example.test', 'instagram' => 'https://instagram.com/example']);
    $response->assertDontSee('Never Publish This Attendee')->assertDontSee('private-billing@example.test')->assertDontSee('must-not-be-public')->assertDontSee('javascript:alert(1)')->assertDontSee('private-value');
});

test('private events do not reveal attendance or synthesize public organiser contact information', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'private-design', 'email' => 'billing-private@example.test']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'visibility' => Event::VISIBILITY_PRIVATE]);
    EventRegistration::factory()->confirmed()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
    $host = presentationHost($tenant);
    $props = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host])->assertOk()->viewData('page')['props'];
    expect($props['event']['going_count'])->toBeNull()
        ->and($props['org']['contact_email'])->toBeNull()
        ->and($props['org']['links'])->toBe([]);
});

test('organisation administrators can publish edit and clear profile links without overwriting other metadata', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'profile-design', 'meta' => ['keep_me' => 'preserved']]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Admin');
    $host = presentationHost($tenant);
    $values = ['name' => 'Public Organiser', 'email' => 'billing@example.test', 'phone_number' => '233200000000', 'public_profile' => ['description' => 'About our organisation.', 'contact_email' => 'public@example.test', 'website' => 'https://example.test', 'linkedin' => 'https://linkedin.com/company/example']];
    $this->actingAs($user)->post("http://{$host}/settings", $values, ['HTTP_HOST' => $host])->assertSessionHasNoErrors()->assertRedirect();
    expect($tenant->fresh()->meta['public_profile'])->toEqual($values['public_profile'])
        ->and($tenant->fresh()->meta['keep_me'])->toBe('preserved');
    $this->get("http://{$host}/settings", ['HTTP_HOST' => $host])->assertInertia(fn ($page) => $page->where('org.public_profile.website', 'https://example.test'));
    $this->post("http://{$host}/settings", [...$values, 'public_profile' => ['website' => null]], ['HTTP_HOST' => $host])->assertSessionHasNoErrors();
    expect($tenant->fresh()->meta['public_profile'])->toBe(['website' => null]);
});

test('public profile updates reject unsafe urls unknown keys and unauthorised users', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'unsafe-profile']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Admin');
    $host = presentationHost($tenant);
    $values = ['name' => 'Public Organiser', 'email' => 'billing@example.test', 'phone_number' => '233200000000', 'public_profile' => ['website' => 'javascript:alert(1)', 'unknown' => 'not allowed']];
    $this->actingAs($user)->postJson("http://{$host}/settings", $values, ['HTTP_HOST' => $host])->assertUnprocessable()->assertJsonValidationErrors(['public_profile', 'public_profile.website']);
    $user->syncRoles([]);
    $this->postJson("http://{$host}/settings", [...$values, 'public_profile' => ['website' => 'https://example.test']], ['HTTP_HOST' => $host])->assertForbidden();
    expect($tenant->fresh()->meta['public_profile'] ?? null)->toBeNull();
});
