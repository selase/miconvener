<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantHostMatcher;

beforeEach(function (): void {
    config(['session.domain' => '.miconvener.test']);
    $this->matcher = new TenantHostMatcher();
    $this->tenant = new Tenant(['slug' => 'acme']);
});

test('the exact tenant subdomain names the tenant', function (): void {
    expect($this->matcher->matches($this->tenant, 'acme.miconvener.test'))->toBeTrue();
});

test('dns host spelling is normalized', function (): void {
    expect($this->matcher->matches($this->tenant, 'ACME.MICONVENER.TEST.'))->toBeTrue();
});

test('hosts that do not exactly name the tenant are refused', function (string $host): void {
    expect($this->matcher->matches($this->tenant, $host))->toBeFalse();
})->with([
    'apex' => 'miconvener.test',
    'reserved platform host' => 'www.miconvener.test',
    'another tenant' => 'other.miconvener.test',
    'nested label' => 'acme.extra.miconvener.test',
    'suffix confusion' => 'acme.miconvener.test.example.com',
    'external custom domain' => 'events.acme.example',
]);

test('an empty platform domain fails closed', function (): void {
    config(['session.domain' => null]);

    expect($this->matcher->matches($this->tenant, 'acme.miconvener.test'))->toBeFalse();
});
