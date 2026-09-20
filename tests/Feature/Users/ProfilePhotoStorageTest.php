<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Libraries\Helper;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;

/**
 * A deployed instance's filesystem is rebuilt from the repository on every
 * deploy. Anything written to it between deploys is gone by the next one --
 * while users.photo still points at it, leaving a broken avatar and no file
 * to restore from, because a backup of that disk is wiped along with it.
 *
 * Profile photos therefore have to be written to object storage, and the
 * upload helpers default to the local disk, so every call site must say so.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('photos go to object storage in production, and stay local elsewhere', function () {
    Config::set('app.env', 'production');
    expect(User::uploadDisk())->toBe('s3');

    Config::set('app.env', 'local');
    expect(User::uploadDisk())->toBe('public');
});

test('creating a user writes the photo to the configured disk, not the default one', function () {
    Mail::fake();
    Storage::fake('public');
    Storage::fake('s3');
    Config::set('app.env', 'production');

    // The admin users console lives on the main domain; a tenant subdomain's
    // /users is the team screen, which is a different controller.
    $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    $admin->assignRole('Superadmin');

    $role = \Spatie\Permission\Models\Role::where('name', 'Org Superadmin')->firstOrFail();

    $this->actingAs($admin)->post('/user-management/users', [
        'first_name' => 'Ama',
        'last_name' => 'Mensah',
        'email' => 'ama@example.com',
        'phone_no' => '+233201234567',
        'status' => User::STATUS_ACTIVE,
        'roles' => [$role->id],
        'photo' => UploadedFile::fake()->image('ama.jpg'),
    ])->assertSessionHasNoErrors();

    $created = User::where('email', 'ama@example.com')->first();

    expect($created)->not->toBeNull();
    expect($created->photo)->not->toBeNull();

    Storage::disk('s3')->assertExists($created->photo);
    // The local disk is what the helper falls back to when no disk is named.
    Storage::disk('public')->assertMissing($created->photo);
});

test('the upload helper still defaults to the local disk, so call sites must name one', function () {
    // Pinning the hazard this guards: if the default ever becomes s3, these
    // call sites stop being load-bearing and this test should be revisited
    // rather than silently passing for a new reason.
    $upload = new ReflectionMethod(Helper::class, 'processUploadedFile');
    $delete = new ReflectionMethod(Helper::class, 'deleteFile');

    expect($upload->getParameters()[4]->getDefaultValue())->toBe('public');
    expect($delete->getParameters()[1]->getDefaultValue())->toBe('public');
});
