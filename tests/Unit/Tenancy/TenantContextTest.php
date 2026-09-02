<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;

beforeEach(function () {
    $this->context = new TenantContext();
});

test('it can set and get active tenant', function () {
    $tenant = new Tenant(['id' => '019bb884-3060-70f0-906a-ed7cc8e994ef', 'name' => 'Test Tenant']);

    $this->context->setTenant($tenant);

    expect($this->context->getTenant())->toBe($tenant);
    expect($this->context->activeTenantId())->toBe($tenant->id);
});

test('it can set and get active tenant id', function () {
    $tenantId = '019bb884-3060-70f0-906a-ed7cc8e994ef';

    $this->context->setActiveTenantId($tenantId);

    expect($this->context->activeTenantId())->toBe($tenantId);
});

test('it returns null when no tenant is set', function () {
    expect($this->context->getTenant())->toBeNull();
    expect($this->context->activeTenantId())->toBeNull();
});

test('it is a singleton in the container', function () {
    $instance1 = app(TenantContext::class);
    $instance2 = app(TenantContext::class);

    expect($instance1)->toBe($instance2);
});
