<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/landlord',
        '--realpath' => true,
    ]);
});

test('tenant encryption at rest defaults to false', function () {
    $tenant = Tenant::create([
        'name' => 'Test Tenant',
        'slug' => 'test-tenant',
    ]);

    $tenant->refresh();

    expect($tenant->encryption_at_rest)->toBeFalse();
    expect($tenant->encryptionAtRestEnabled())->toBeFalse();
});

test('tenant encryption can be enabled', function () {
    $tenant = Tenant::create([
        'name' => 'Secure Tenant',
        'slug' => 'secure-tenant',
        'encryption_at_rest' => true,
        'kms_key_ref' => 'alias/secure-key',
    ]);

    expect($tenant->encryption_at_rest)->toBeTrue();
    expect($tenant->encryptionAtRestEnabled())->toBeTrue();
    expect($tenant->kms_key_ref)->toBe('alias/secure-key');
});
