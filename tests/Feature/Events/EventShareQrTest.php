<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('the overview carries a QR code for the public event page', function (): void {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page
            ->where('publicUrl', route('public.events.show', ['subdomain' => 'acme', 'event' => $event->slug]))
            ->where('publicQr', fn ($qr) => str_starts_with((string) $qr, 'data:image/svg+xml;base64,')));
});

test('the QR code encodes the public page link', function (): void {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $publicUrl = route('public.events.show', ['subdomain' => 'acme', 'event' => $event->slug]);

    $expected = \App\Services\Events\QrCodeGenerator::svgDataUri($publicUrl, 1024);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('publicQr', $expected));
});

test('other sections do not pay for generating the code', function (): void {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->get("http://{$host}/events/{$event->id}/guests", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('publicQr', null));
});
