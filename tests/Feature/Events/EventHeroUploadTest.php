<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('hero image upload accepts images up to 20MB', function () {
    Storage::fake('public');

    $tenant = Tenant::factory()->create(['slug' => 'hero-test', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    $host = 'hero-test.'.mb_ltrim((string) config('session.domain'), '.');

    // 10MB image (10240 KB)
    $file = UploadedFile::fake()->image('banner.png')->size(10240);

    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'High Res Event',
        'description' => 'Event with high-res banner',
        'status' => Event::STATUS_DRAFT,
        'starts_at' => now()->addWeek()->toDateTimeString(),
        'ends_at' => now()->addWeek()->addHours(3)->toDateTimeString(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => 'Accra International Conference Centre',
        'ticket_price' => 0,
        'currency' => 'GHS',
        'hero_image' => $file,
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasNoErrors();
    $event = Event::where('tenant_id', $tenant->id)->firstOrFail();
    expect($event->hero_image_path)->not->toBeNull();
});

test('hero image upload rejects images greater than 20MB with friendly error', function () {
    Storage::fake('public');

    $tenant = Tenant::factory()->create(['slug' => 'hero-test-fail', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    $host = 'hero-test-fail.'.mb_ltrim((string) config('session.domain'), '.');

    // 25MB image (25600 KB)
    $file = UploadedFile::fake()->image('giant-banner.png')->size(25600);

    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'Giant Banner Event',
        'status' => Event::STATUS_DRAFT,
        'starts_at' => now()->addWeek()->toDateTimeString(),
        'ends_at' => now()->addWeek()->addHours(3)->toDateTimeString(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => 'Accra',
        'ticket_price' => 0,
        'currency' => 'GHS',
        'hero_image' => $file,
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasErrors([
        'hero_image' => 'The hero image must not be greater than 20MB.',
    ]);
});
