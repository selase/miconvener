<?php

declare(strict_types=1);

use App\Contracts\Secrets\SecretsProvider;
use App\Jobs\Middleware\TenantAwareJob;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('database.connections.tenant', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);
});

test('it restores tenant context', function () {
    $tenant = Tenant::create(['name' => 'Job Tenant', 'slug' => 'job-tenant', 'isolation_mode' => 'shared']);
    $tenantId = $tenant->id;

    $job = new class
    {
        public $tenantId;
    };
    $job->tenantId = $tenantId;

    $middleware = new TenantAwareJob();

    $context = app(TenantContext::class);

    expect($context->activeTenantId())->toBeNull();

    $middleware->handle($job, function ($processedJob) use ($context, $tenantId) {
        expect($context->activeTenantId())->toBe($tenantId);
    });
});

test('it configures database for dedicated tenant', function () {
    $tenant = Tenant::create([
        'name' => 'Dedicated Job Tenant',
        'slug' => 'dedicated-job-tenant',
        'isolation_mode' => 'db_per_tenant',
        'db_driver' => 'mysql',
        'db_secret_ref' => 'job_db_secret',
    ]);

    $this->mock(SecretsProvider::class, function ($mock) {
        $mock->shouldReceive('getSecret')->with('job_db_secret')->andReturn([
            'type' => 'db',
            'host' => '1.2.3.4',
            'port' => '3306',
            'database' => 'job_db',
            'username' => 'job_user',
            'password' => 'job_pass',
        ]);
    });

    $job = new class
    {
        public $tenantId;
    };
    $job->tenantId = $tenant->id;

    $middleware = new TenantAwareJob();

    $middleware->handle($job, function ($processedJob) {
        expect(Config::get('database.connections.tenant.driver'))->toBe('mysql');
        expect(Config::get('database.connections.tenant.host'))->toBe('1.2.3.4');
        expect(Config::get('database.connections.tenant.database'))->toBe('job_db');
    });
});
