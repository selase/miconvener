<?php

declare(strict_types=1);

use App\Contracts\Secrets\SecretsProvider;
use App\Jobs\Middleware\TenantAwareJob;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    useLandlordAsTenantConnection();
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
        'db_driver' => 'pgsql',
        'db_secret_ref' => 'job_db_secret',
    ]);

    $this->mock(SecretsProvider::class, function ($mock) {
        $mock->shouldReceive('getSecret')->with('job_db_secret')->andReturn([
            'type' => 'db',
            'host' => '1.2.3.4',
            'port' => '5432',
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
        expect(Config::get('database.connections.tenant.driver'))->toBe('pgsql');
        expect(Config::get('database.connections.tenant.host'))->toBe('1.2.3.4');
        expect(Config::get('database.connections.tenant.database'))->toBe('job_db');
    });
});

test('a job class name with a NUL byte is recorded without it', function () {
    /*
     * Anonymous classes are named "class@anonymous", a NUL byte, then a path.
     * Postgres refuses NUL in text and JSON, and usage is recorded in the job
     * middleware's finally block, so the failure replaced the job's outcome.
     */
    $tenant = Tenant::factory()->create();

    app(App\Services\Tenancy\UsageService::class)->recordJob($tenant, "class@anonymous\0/app/SomeFile.php:22\$0", true, 5);

    $keys = App\Models\UsageEvent::query()->where('tenant_id', $tenant->id)->pluck('key')->unique()->values()->all();

    expect($keys)->toBe(['class@anonymous']);
});
