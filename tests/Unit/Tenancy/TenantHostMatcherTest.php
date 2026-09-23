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

test('the canonical tenant slug can be read before route matching', function (): void {
    expect($this->matcher->tenantSlug('ACME.MICONVENER.TEST.'))->toBe('acme');
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
    'more than one terminal dot' => 'acme.miconvener.test..',
]);

test('hosts without a canonical tenant label have no tenant slug', function (string $host): void {
    expect($this->matcher->tenantSlug($host))->toBeNull();
})->with([
    'apex' => 'miconvener.test',
    'reserved platform host' => 'www.miconvener.test',
    'nested label' => 'acme.extra.miconvener.test',
    'suffix confusion' => 'acme.miconvener.test.example.com',
    'external custom domain' => 'events.acme.example',
    'more than one terminal dot' => 'acme.miconvener.test..',
]);

test('an empty platform domain fails closed', function (): void {
    config(['session.domain' => null]);

    expect($this->matcher->matches($this->tenant, 'acme.miconvener.test'))->toBeFalse()
        ->and($this->matcher->isPlatformHost('miconvener.test'))->toBeFalse()
        ->and($this->matcher->isWwwHost('www.miconvener.test'))->toBeFalse();
});

test('the exact base domain is recognized as the platform host', function (): void {
    expect($this->matcher->isPlatformHost('miconvener.test'))->toBeTrue()
        ->and($this->matcher->isPlatformHost('MICONVENER.TEST.'))->toBeTrue()
        ->and($this->matcher->isPlatformHost('www.miconvener.test'))->toBeFalse()
        ->and($this->matcher->isPlatformHost('acme.miconvener.test'))->toBeFalse()
        ->and($this->matcher->isPlatformHost('miconvener.test..'))->toBeFalse()
        ->and($this->matcher->isPlatformHost('miconvener.test.example.com'))->toBeFalse();
});

test('the www host is recognized for redirection', function (): void {
    expect($this->matcher->isWwwHost('www.miconvener.test'))->toBeTrue()
        ->and($this->matcher->isWwwHost('WWW.MICONVENER.TEST.'))->toBeTrue()
        ->and($this->matcher->isWwwHost('miconvener.test'))->toBeFalse()
        ->and($this->matcher->isWwwHost('acme.miconvener.test'))->toBeFalse()
        ->and($this->matcher->isWwwHost('www.miconvener.test..'))->toBeFalse()
        ->and($this->matcher->isWwwHost('www.other.test'))->toBeFalse();
});
