<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use App\Services\Events\EventSocialCard;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$this->tenant, $this->admin] = eventHost('acme');
    $this->tenant->update(['meta' => ['public_profile' => ['instagram' => 'https://instagram.com/organisation']]]);
    $this->host = eventSubdomainHost('acme');
    $this->headers = ['HTTP_HOST' => $this->host];
    $this->event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id]);
    $this->url = "http://{$this->host}/events/{$this->event->id}/public-branding";
});

test('existing events keep the managing organisation and organisation social links', function (): void {
    $this->get("http://{$this->host}/e/{$this->event->slug}", $this->headers)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('event.show_managing_organisation', true)
            ->where('event.social_links_mode', 'organisation')->where('event.social_links', ['instagram' => 'https://instagram.com/organisation']));
});

test('event managers can independently hide branding and replace social links without changing the organisation', function (): void {
    $links = ['website' => 'https://example.org/conference', 'linkedin' => 'https://www.linkedin.com/posts/conference-123'];
    $this->actingAs($this->admin)->patchJson($this->url, [
        'show_managing_organisation' => false, 'social_links_mode' => 'event', 'social_links' => $links,
    ], $this->headers)->assertOk()->assertJsonPath('show_managing_organisation', false)->assertJsonPath('social_links', $links);
    $this->get("http://{$this->host}/e/{$this->event->slug}", $this->headers)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('event.show_managing_organisation', false)
            ->where('event.social_links', $links));
    $this->get("http://{$this->host}/events/{$this->event->id}", $this->headers)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('event.social_links', $links));
    expect($this->tenant->fresh()->publicEventProfile()['links'])->toBe(['instagram' => 'https://instagram.com/organisation']);
    expect($this->event->fresh()->tenant_id)->toBe($this->tenant->id);
    $other = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    expect($other->show_managing_organisation)->toBeTrue();
    expect($other->social_links_mode)->toBe('organisation');
});

test('hidden and empty event social links never fall back to organisation links', function (): void {
    foreach (['hidden', 'event'] as $mode) {
        $this->event->update(['social_links_mode' => $mode, 'social_links' => []]);
        $this->get("http://{$this->host}/e/{$this->event->slug}", $this->headers)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('event.social_links', []));
    }
});

test('hidden mode with saved event links exposes no links and invalid stored URLs are never rendered', function (): void {
    $this->event->update(['social_links_mode' => 'hidden', 'social_links' => ['instagram' => 'https://instagram.com/event']]);
    expect($this->event->publicSocialLinks())->toBe([]);
    $this->event->update(['social_links_mode' => 'event', 'social_links' => ['website' => 'javascript:alert(1)', 'instagram' => 'https://instagram.com/event']]);
    expect($this->event->publicSocialLinks())->toBe(['instagram' => 'https://instagram.com/event']);
});

test('branding validation refuses unsafe links, unknown fields and invalid choices', function (array $invalid): void {
    $this->actingAs($this->admin)->patchJson($this->url, array_replace([
        'show_managing_organisation' => false, 'social_links_mode' => 'event', 'social_links' => [],
    ], $invalid), $this->headers)->assertUnprocessable();
})->with([
    [['social_links' => ['instagram' => 'javascript:alert(1)']]],
    [['social_links' => ['website' => 'data:text/html,test']]],
    [['social_links' => ['unknown' => 'https://example.org']]],
    [['social_links_mode' => 'merge']],
    [['show_managing_organisation' => 'maybe']],
]);

test('only event managers in the owning organisation may change branding', function (): void {
    $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($viewer->id);
    $data = ['show_managing_organisation' => false, 'social_links_mode' => 'hidden', 'social_links' => []];
    $this->actingAs($viewer)->patchJson($this->url, $data, $this->headers)->assertForbidden();
    [$otherTenant, $otherAdmin] = eventHost('other');
    $this->actingAs($otherAdmin)->patchJson($this->url, $data, $this->headers)->assertForbidden();
    $otherEvent = Event::factory()->create(['tenant_id' => $otherTenant->id]);
    $this->actingAs($this->admin)->patchJson("http://{$this->host}/events/{$otherEvent->id}/public-branding", $data, $this->headers)->assertNotFound();
});

test('hidden managing credits use the event name in social share metadata', function (): void {
    $this->event->update(['show_managing_organisation' => false]);
    expect(app(EventSocialCard::class)->for($this->event, $this->tenant)['site_name'])->toBe($this->event->name);
});

test('private event-specific links remain withheld from the public event teaser', function (): void {
    $this->event->update(['visibility' => 'private', 'contact_email' => 'private@example.org', 'social_links_mode' => 'event', 'social_links' => ['website' => 'https://example.org/private-event']]);
    $this->get("http://{$this->host}/e/{$this->event->slug}", $this->headers)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('event.social_links', [])->where('event.contact_email', null));
});
