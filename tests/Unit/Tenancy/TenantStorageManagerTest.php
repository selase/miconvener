<?php

declare(strict_types=1);

use App\Contracts\Secrets\SecretsProvider;
use App\Models\Tenant;
use App\Services\Tenancy\TenantStorageManager;
use Illuminate\Support\Facades\Config;

test('it configures shared storage', function () {
    $tenantId = '019bb884-3060-70f0-906a-ed7cc8e994ef';
    $tenant = new Tenant([
        'id' => $tenantId,
        's3_mode' => 'shared',
    ]);

    Config::set('filesystems.default', 's3');
    Config::set('filesystems.disks.s3', [
        'driver' => 's3',
        'key' => 'shared_key',
        'secret' => 'shared_secret',
        'region' => 'us-east-1',
        'bucket' => 'shared_bucket',
    ]);

    $manager = app(TenantStorageManager::class);
    $manager->configure($tenant);

    $config = Config::get('filesystems.disks.tenant');
    expect($config['bucket'])->toBe('shared_bucket');
    expect($config['root'])->toBe("tenants/{$tenantId}");
});

test('it configures byo storage', function () {
    $tenant = new Tenant([
        'id' => '019bb884-3060-70f0-906a-ed7cc8e994ef',
        's3_mode' => 'byo',
        's3_secret_ref' => 'tenant_s3_ref',
    ]);

    $this->mock(SecretsProvider::class, function ($mock) {
        $mock->shouldReceive('getSecret')->with('tenant_s3_ref')->andReturn([
            'type' => 's3',
            'key' => 'tenant_key',
            'secret' => 'tenant_secret',
            'region' => 'us-west-2',
            'bucket' => 'tenant_bucket',
        ]);
    });

    $manager = app(TenantStorageManager::class);
    $manager->configure($tenant);

    $config = Config::get('filesystems.disks.tenant');
    expect($config['bucket'])->toBe('tenant_bucket');
    expect($config['region'])->toBe('us-west-2');
    expect($config)->not->toHaveKey('root');
});
