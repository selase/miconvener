<?php

declare(strict_types=1);

use App\Contracts\Secrets\SecretsProvider;
use App\Models\Tenant;
use App\Services\Tenancy\TenantDatabaseManager;
use Illuminate\Support\Facades\Config;

test('it configures tenant database', function () {
    $tenant = new Tenant([
        'db_driver' => 'mysql',
        'db_secret_ref' => 'ref',
    ]);

    $this->mock(SecretsProvider::class, function ($mock) {
        $mock->shouldReceive('getSecret')->with('ref')->andReturn([
            'host' => '1.2.3.4',
            'port' => '3306',
            'database' => 'db',
            'username' => 'user',
            'password' => 'pass',
        ]);
    });

    $manager = app(TenantDatabaseManager::class);
    $manager->configure($tenant);

    expect(Config::get('database.connections.tenant.driver'))->toBe('mysql');
    expect(Config::get('database.connections.tenant.host'))->toBe('1.2.3.4');
});
