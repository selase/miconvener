<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventBadgeTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function badgeDesignerHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function validBadgeDesign(array $overrides = []): array
{
    return $overrides + [
        'width_mm' => 100,
        'height_mm' => 70,
        'orientation' => 'landscape',
        'layout' => [
            'attendee_name' => [
                'x' => 0.1, 'y' => 0.3, 'width' => 0.8, 'height' => 0.2,
                'align' => 'center', 'font_family' => 'Helvetica', 'font_size' => 22,
                'font_weight' => 700, 'color' => '#111827', 'visible' => true,
            ],
        ],
        'tier_styles' => ['vip' => ['background_color' => '#7C3AED', 'text_color' => '#FFFFFF']],
        'sheet_settings' => ['paper' => 'a4', 'margin_mm' => 8, 'gap_mm' => 3, 'crop_marks' => true],
    ];
}

test('badge index creates and returns the compatible default template', function (): void {
    [$tenant, $user] = badgeDesignerHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');

    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/data/badges", ['HTTP_HOST' => $host]);

    $response->assertOk()->assertJsonPath('template.width_mm', 100)->assertJsonPath('template.height_mm', 70)->assertJsonPath('template.layout.attendee_name.visible', true);
    expect(EventBadgeTemplate::query()->where('event_id', $event->id)->count())->toBe(1);
});

test('organizer can update badge dimensions layout tier styles and artwork', function (): void {
    Storage::fake('public');
    [$tenant, $user] = badgeDesignerHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $payload = validBadgeDesign(['background' => UploadedFile::fake()->image('badge.png', 1000, 700)]);
    $payload['layout'] = json_encode($payload['layout']);
    $payload['tier_styles'] = json_encode($payload['tier_styles']);
    $payload['sheet_settings'] = json_encode($payload['sheet_settings']);

    $response = $this->actingAs($user)->post("http://{$host}/events/{$event->id}/badges/template", $payload, ['HTTP_HOST' => $host]);

    $response->assertOk()->assertJsonPath('template.design_version', 2);
    $template = EventBadgeTemplate::query()->where('event_id', $event->id)->firstOrFail();
    Storage::disk('public')->assertExists($template->background_path);
    expect($template->tier_styles['vip']['background_color'])->toBe('#7C3AED');
    $this->actingAs($user)->get("http://{$host}/events/{$event->id}/badges/template/artwork", ['HTTP_HOST' => $host])
        ->assertOk()->assertHeader('content-type', 'image/png');
});

test('badge template validation rejects unsafe dimensions and unknown elements', function (array $overrides, string $field): void {
    [$tenant, $user] = badgeDesignerHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/template", validBadgeDesign($overrides), ['HTTP_HOST' => $host])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'too small' => [['width_mm' => 20], 'width_mm'],
    'unknown layout element' => [['layout' => ['private_html' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]]], 'layout'],
]);

test('badge template endpoint remains constrained to the tenant event', function (): void {
    [$tenant, $user] = badgeDesignerHost();
    $otherTenant = Tenant::factory()->create(['slug' => 'other', 'isolation_mode' => 'shared']);
    $otherEvent = Event::factory()->create(['tenant_id' => $otherTenant->id]);
    $host = eventSubdomainHost('acme');

    $this->actingAs($user)->postJson("http://{$host}/events/{$otherEvent->id}/badges/template", validBadgeDesign(), ['HTTP_HOST' => $host])
        ->assertNotFound();
});

test('badge console uses the server pdf instead of browser printing', function (): void {
    $panel = file_get_contents(resource_path('js/Pages/Tenant/Events/panels/BadgesPanel.jsx'));

    expect($panel)->toContain('tenant.events.badges.sheet')
        ->not->toContain('window.print');
});
