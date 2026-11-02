<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->tenant = \App\Models\Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme Events', 'isolation_mode' => 'shared']);
    $this->host = eventSubdomainHost('acme');
});

function publicEventHtml(Event $event): string
{
    return test()->get("http://{$event->tenant->slug}.".mb_ltrim((string) config('session.domain'), '.').'/e/'.$event->slug, ['HTTP_HOST' => test()->host])->getContent();
}

test('a shared event link previews with the event name, when and where, and its hero image', function (): void {
    $event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Sorkor at 70',
        'address' => 'Gaithersburg, Maryland, USA',
        'description' => 'Join us <b>to celebrate</b> a remarkable life.',
        'hero_image_path' => 'https://cdn.example.com/hero.jpg',
        'timezone' => 'America/New_York',
        'starts_at' => '2026-11-21 20:00:00',
    ]);

    $html = publicEventHtml($event);

    expect($html)
        ->toContain('<meta property="og:title" content="Sorkor at 70">')
        ->toContain('<meta property="og:site_name" content="Acme Events">')
        ->toContain('<meta property="og:image" content="https://cdn.example.com/hero.jpg">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->toContain('Sat 21 Nov 2026, 3:00 PM')
        ->toContain('Gaithersburg, Maryland, USA')
        ->toContain('Join us to celebrate a remarkable life.')
        ->not->toContain('<b>to celebrate</b>');
});

test('an event without a hero image falls back to the MiConvener card', function (): void {
    $event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id, 'hero_image_path' => null]);

    expect(publicEventHtml($event))->toContain('/assets/img/brand/og-default.png');
});

test('a private event previews without its details or image', function (): void {
    $event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Board retreat',
        'visibility' => 'private',
        'address' => 'Secret Location',
        'hero_image_path' => 'https://cdn.example.com/private.jpg',
    ]);

    preg_match_all('/<meta (?:property="og|name="twitter)[^>]*>/', publicEventHtml($event), $tags);
    $preview = implode("\n", $tags[0]);

    expect($preview)
        ->toContain('<meta property="og:title" content="Board retreat">')
        ->toContain('You are invited')
        ->not->toContain('Secret Location')
        ->not->toContain('private.jpg');
});

test('the default share image is a 1200 by 630 card', function (): void {
    [$width, $height] = getimagesize(public_path('assets/img/brand/og-default.png'));

    expect([$width, $height])->toBe([1200, 630]);
});
