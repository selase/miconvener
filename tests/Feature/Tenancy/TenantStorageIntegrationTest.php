<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantStorageManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);
});

afterEach(function () {
    // Cleanup
    Storage::disk('s3')->deleteDirectory('tenants');
});

test('files are stored with tenant prefix in shared mode', function () {
    $tenant = Tenant::create(['name' => 'Shared Tenant', 'slug' => 'shared-tenant', 's3_mode' => 'shared']);

    // Mock the base S3 config to use local for testing
    Config::set('filesystems.default', 's3');
    Config::set('filesystems.disks.s3', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/s3'),
    ]);

    // Configure tenant storage
    app(TenantStorageManager::class)->configure($tenant);

    // Store a file on 'tenant' disk
    Storage::disk('tenant')->put('test.txt', 'content');

    // Verify it exists on the base 's3' disk at the correct path
    expect(Storage::disk('s3')->exists("tenants/{$tenant->id}/test.txt"))->toBeTrue();
});
