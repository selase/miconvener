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

    $response->assertOk()->assertJsonPath('template.design_version', 1)->assertJsonPath('template.width_mm', 100)->assertJsonPath('template.height_mm', 70)->assertJsonPath('template.layout.attendee_name.visible', true);
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

test('badge saves advance the latest design version when another save follows the initial read', function (): void {
    [$tenant, $user] = badgeDesignerHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $template = app(\App\Services\Badges\BadgeTemplateService::class)->forEvent($event);
    $interleaved = false;
    EventBadgeTemplate::retrieved(function (EventBadgeTemplate $retrieved) use ($template, &$interleaved): void {
        if ($retrieved->id === $template->id && ! $interleaved) {
            $interleaved = true;
            EventBadgeTemplate::query()->whereKey($template->id)->update(['design_version' => 2]);
        }
    });
    $host = eventSubdomainHost('acme');

    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/template", validBadgeDesign(), ['HTTP_HOST' => $host])
        ->assertOk()->assertJsonPath('template.design_version', 3);
    expect($interleaved)->toBeTrue();
});

test('badge console uses the server pdf instead of browser printing', function (): void {
    $panel = file_get_contents(resource_path('js/Pages/Tenant/Events/panels/BadgesPanel.jsx'));

    expect($panel)->toContain('tenant.events.badges.sheet')
        ->not->toContain('window.print');
});

test('badge save rejects sheet overflow before storing uploaded artwork', function (): void {
    Storage::fake('public');
    [$tenant, $user] = badgeDesignerHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $payload = validBadgeDesign(['width_mm' => 210, 'background' => UploadedFile::fake()->image('badge.png', 1000, 700)]);
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/template", $payload, ['HTTP_HOST' => $host])
        ->assertUnprocessable()->assertJsonValidationErrors('sheet_settings');
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('invalid badge layout does not leak uploaded artwork', function (): void {
    Storage::fake('public');
    [$tenant, $user] = badgeDesignerHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $payload = validBadgeDesign(['layout' => ['unsafe' => []], 'background' => UploadedFile::fake()->image('badge.png', 1000, 700)]);
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/template", $payload, ['HTTP_HOST' => $host])
        ->assertUnprocessable()->assertJsonValidationErrors('layout');
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('crop marks require a clear gap between badges', function (): void {
    [$tenant, $user] = badgeDesignerHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $payload = validBadgeDesign();
    $payload['sheet_settings']['gap_mm'] = 0;
    $url = "http://{$host}/events/{$event->id}/badges/template";
    $this->actingAs($user)->postJson($url, $payload, ['HTTP_HOST' => $host])
        ->assertUnprocessable()->assertJsonValidationErrors('sheet_settings.gap_mm');
    $payload['sheet_settings']['crop_marks'] = false;
    $this->postJson($url, $payload, ['HTTP_HOST' => $host])->assertOk();
});

test('badge sheet fit follows selected paper and dimensions determine orientation', function (): void {
    [$tenant, $user] = badgeDesignerHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $url = "http://{$host}/events/{$event->id}/badges/template";
    $payload = validBadgeDesign(['width_mm' => 195, 'height_mm' => 70]);
    $this->actingAs($user)->postJson($url, $payload, ['HTTP_HOST' => $host])
        ->assertUnprocessable()->assertJsonValidationErrors('sheet_settings');
    $payload['sheet_settings']['paper'] = 'letter';
    $payload['sheet_settings']['crop_marks'] = false;
    $this->postJson($url, $payload, ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('template.sheet_settings.crop_marks', false);
    $payload['width_mm'] = 70;
    $payload['height_mm'] = 100;
    $this->postJson($url, $payload, ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('template.orientation', 'portrait');
});

test('badge saves fitting position and underline settings', function (): void {
    [$tenant, $user] = badgeDesignerHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $payload = validBadgeDesign(['background_settings' => json_encode(['fit' => 'contain', 'position' => 'bottom-right'])]);
    $payload['layout']['attendee_name']['underline'] = true;
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/template", $payload, ['HTTP_HOST' => $host])
        ->assertOk()->assertJsonPath('template.background_settings.fit', 'contain')
        ->assertJsonPath('template.background_settings.position', 'bottom-right')
        ->assertJsonPath('template.layout.attendee_name.underline', true);
});

test('badge rejects unsupported fitting and non boolean underline', function (array $settings, mixed $underline, string $field): void {
    [$tenant, $user] = badgeDesignerHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $payload = validBadgeDesign(['background_settings' => $settings]);
    $payload['layout']['attendee_name']['underline'] = $underline;
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/template", $payload, ['HTTP_HOST' => $host])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['fit' => 'arbitrary', 'position' => 'center'], false, 'background_settings.fit'],
    [['fit' => 'cover', 'position' => 'arbitrary'], false, 'background_settings.position'],
    [['fit' => 'cover', 'position' => 'center'], 'false', 'layout'],
]);

test('badge designer stores its own logo and removed elements independently from organization branding', function (): void {
    Storage::fake('public');
    [$tenant, $user] = badgeDesignerHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $payload = validBadgeDesign(['logo' => UploadedFile::fake()->image('badge-logo.png', 180, 90), 'logo_source' => 'custom']);
    $payload['layout']['qr'] = ['x' => 0.01, 'y' => 0.02, 'width' => 0.2, 'height' => 0.3, 'removed' => true, 'visible' => false];
    $payload['layout']['tenant_logo'] = ['x' => 0.5, 'y' => 0.8, 'width' => 0.4, 'height' => 0.15];
    $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/badges/template", $payload, ['HTTP_HOST' => $host])
        ->assertOk()->assertJsonPath('template.logo_source', 'custom')->assertJsonPath('template.layout.qr.removed', true);
    $template = EventBadgeTemplate::query()->where('event_id', $event->id)->firstOrFail();
    Storage::disk('public')->assertExists($template->logo_path);
    expect($tenant->fresh()->logo)->toBeNull();
    $this->getJson("http://{$host}/events/{$event->id}/data/badges", ['HTTP_HOST' => $host])
        ->assertOk()->assertJsonPath('badge_logo', fn ($value) => str_starts_with($value, 'data:image/png;base64,'));
    $path = $template->logo_path;
    $payload = validBadgeDesign(['remove_logo' => true, 'logo_source' => 'none']);
    $this->postJson("http://{$host}/events/{$event->id}/badges/template", $payload, ['HTTP_HOST' => $host])->assertOk();
    Storage::disk('public')->assertMissing($path);
});

test('removed backgrounds survive save and reopen and can be restored', function (): void {
    Storage::fake('public');
    [$tenant, $user] = badgeDesignerHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $host = eventSubdomainHost('acme');
    $url = "http://{$host}/events/{$event->id}/badges/template";
    $this->actingAs($user)->postJson($url, validBadgeDesign(['background' => UploadedFile::fake()->image('badge.png')]), ['HTTP_HOST' => $host])->assertOk();
    $template = EventBadgeTemplate::query()->where('event_id', $event->id)->firstOrFail();
    $path = $template->background_path;
    $this->postJson($url, validBadgeDesign(['background_settings' => ['fit' => 'contain', 'position' => 'center', 'removed' => true, 'visible' => false]]), ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('template.background_path', $path)->assertJsonPath('template.background_settings.removed', true);
    Storage::disk('public')->assertExists($path);
    expect($template->fresh()->backgroundSettings()['visible'])->toBeFalse();
    $this->postJson($url, validBadgeDesign(['background_settings' => ['fit' => 'contain', 'position' => 'center', 'removed' => false, 'visible' => true]]), ['HTTP_HOST' => $host])->assertOk();
    expect($template->fresh()->backgroundSettings()['visible'])->toBeTrue();
});
