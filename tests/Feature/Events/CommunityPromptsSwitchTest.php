<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    Artisan::call('community:following-enable', ['tenant' => $this->tenant->slug]);
    $this->host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');
    $this->event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id]);
    $this->page = fn () => $this->get("http://{$this->host}/e/{$this->event->slug}", ['HTTP_HOST' => $this->host]);
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->admin->assignRole('Org Admin');
    $this->tenant->users()->attach($this->admin->id);
});

test('an event page shows the organiser follow and membership options by default', function (): void {
    expect($this->event->fresh()->showsCommunityPrompts())->toBeTrue();

    ($this->page)()->assertOk()->assertInertia(fn (Assert $page) => $page->where('community.organisation.slug', 'acme')->where('community.followingEnabled', true));
});

test('an event with the option switched off shows none of them, whatever the organisation offers', function (): void {
    $this->event->update(['show_community_prompts' => false]);

    ($this->page)()->assertOk()->assertInertia(fn (Assert $page) => $page->where('community', null));
});

test('switching it off on one event leaves the organiser\'s other events alone', function (): void {
    $other = Event::factory()->published()->create(['tenant_id' => $this->tenant->id]);
    $this->event->update(['show_community_prompts' => false]);

    $this->get("http://{$this->host}/e/{$other->slug}", ['HTTP_HOST' => $this->host])->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('community.organisation.slug', 'acme'));
});

test('an organiser changes the option from the event page and the choice is remembered', function (): void {
    $url = "http://{$this->host}/events/{$this->event->id}/community-prompts";

    $this->actingAs($this->admin)->patchJson($url, ['show_community_prompts' => false], ['HTTP_HOST' => $this->host])
        ->assertOk()->assertJsonPath('show_community_prompts', false);
    expect($this->event->fresh()->show_community_prompts)->toBeFalse();

    $this->actingAs($this->admin)->patchJson($url, ['show_community_prompts' => true], ['HTTP_HOST' => $this->host])->assertOk();
    expect($this->event->fresh()->show_community_prompts)->toBeTrue();
});

test('the option needs a yes or a no and the right to update the event, and stays inside the organisation', function (): void {
    $url = "http://{$this->host}/events/{$this->event->id}/community-prompts";
    $this->actingAs($this->admin)->patchJson($url, ['show_community_prompts' => 'maybe'], ['HTTP_HOST' => $this->host])->assertUnprocessable();
    $this->actingAs($this->admin)->patchJson($url, [], ['HTTP_HOST' => $this->host])->assertUnprocessable();

    $outsider = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
    $this->actingAs($outsider)->patchJson($url, ['show_community_prompts' => false], ['HTTP_HOST' => $this->host])->assertForbidden();
    expect($this->event->fresh()->show_community_prompts)->toBeTrue();
});

test('the organiser console reports the current option for the event', function (): void {
    $this->event->update(['show_community_prompts' => false]);

    $this->actingAs($this->admin)->get("http://{$this->host}/events/{$this->event->id}", ['HTTP_HOST' => $this->host])->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('event.show_community_prompts', false));
});
