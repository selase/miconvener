<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Tests\Unit\Tenancy\TestTenantModel;

beforeEach(function () {
    Config::set('database.connections.tenant', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);
});

test('model using trait uses tenant connection', function () {
    $model = new TestTenantModel();

    expect($model->getConnectionName())->toBe('tenant');
});

test('model using trait sets tenant id on creating', function () {
    $tenantId = '019bb884-3060-70f0-906a-ed7cc8e994ef';
    $tenant = new Tenant(['id' => $tenantId]);
    $context = app(TenantContext::class);
    $context->setTenant($tenant);

    $model = new TestTenantModel();

    // Use reflection to call the protected fireModelEvent
    $reflection = new ReflectionClass(Model::class);
    $method = $reflection->getMethod('fireModelEvent');
    $method->invoke($model, 'creating');

    expect($model->tenant_id)->toBe($tenantId);
});
