<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Libraries\Helper;
use App\Models\Event;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Storage::fake('public');
    Storage::fake('s3');
    $this->tenant = Tenant::factory()->create(['slug' => 'media-test', 'isolation_mode' => 'shared']);
});

test('media route serves an uploaded event hero image with cache headers', function () {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "media-test.{$baseDomain}";

    $imageContent = 'fake-png-binary-data';
    Storage::disk('public')->put('events/hero/banner_test.png', $imageContent);

    $response = $this->get("http://{$host}/media/events/hero/banner_test.png", [
        'HTTP_HOST' => $host,
    ]);

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
});

test('media route serves an uploaded file from s3 storage when configured', function () {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "media-test.{$baseDomain}";

    $imageContent = 'fake-s3-binary-data';
    Storage::disk('s3')->put('events/hero/banner_s3.png', $imageContent);

    $response = $this->get("http://{$host}/media/events/hero/banner_s3.png", [
        'HTTP_HOST' => $host,
    ]);

    $response->assertOk();
});

test('storage fallback route serves file from storage when requested via storage path', function () {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "media-test.{$baseDomain}";

    $imageContent = 'fake-png-data';
    Storage::disk('public')->put('events/hero/legacy_banner.png', $imageContent);

    $response = $this->get("http://{$host}/storage/events/hero/legacy_banner.png", [
        'HTTP_HOST' => $host,
    ]);

    $response->assertOk();
});

test('media route returns 404 for path traversal attempts', function () {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "media-test.{$baseDomain}";

    $response = $this->get("http://{$host}/media/../.env", [
        'HTTP_HOST' => $host,
    ]);

    $response->assertNotFound();
});

test('media route returns 404 for unwhitelisted directories', function () {
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "media-test.{$baseDomain}";

    Storage::disk('public')->put('private_dir/secret.txt', 'secret data');

    $response = $this->get("http://{$host}/media/private_dir/secret.txt", [
        'HTTP_HOST' => $host,
    ]);

    $response->assertNotFound();
});

test('Helper::storageUrl generates accessible url and strips redundant storage prefix', function () {
    expect(Helper::storageUrl(null))->toBeNull();
    expect(Helper::storageUrl('https://example.com/image.png'))->toBe('https://example.com/image.png');

    $url = Helper::storageUrl('events/hero/test.png');
    expect($url)->toContain('/media/events/hero/test.png');

    $urlLegacy = Helper::storageUrl('storage/events/hero/test.png');
    expect($urlLegacy)->toContain('/media/events/hero/test.png');
    expect($urlLegacy)->not->toContain('/storage/');
});

test('public event page provides hero_image_url pointing to media route', function () {
    $event = Event::factory()->create([
        'tenant_id' => $this->tenant->id,
        'slug' => 'upcoming-event',
        'status' => Event::STATUS_PUBLISHED,
        'hero_image_path' => 'events/hero/event_hero_sample.png',
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "media-test.{$baseDomain}";

    $response = $this->get("http://{$host}/e/{$event->slug}", [
        'HTTP_HOST' => $host,
    ]);

    $response->assertOk();
    $page = $response->viewData('page');
    expect($page['props']['event']['hero_image_url'])->toContain('/media/events/hero/event_hero_sample.png');
});

test('media route still serves an admin profile photo', function () {
    Storage::disk('public')->put('users/profile/user_photo_abc.jpg', 'PHOTO BYTES');

    // Admin\UsersController and Admin\TeamController write here and the admin
    // header renders it on every page; narrowing the allowlist must not 404 it.
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "media-test.{$baseDomain}";

    $this->get("http://{$host}/media/users/profile/user_photo_abc.jpg", [
        'HTTP_HOST' => $host,
    ])->assertOk();
});
