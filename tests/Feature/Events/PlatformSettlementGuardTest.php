<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Config;

/**
 * New tenants take the settlement_mode column default, which is
 * platform_default. Nothing forces them through the payment settings screen
 * first, so the guard cannot assume the platform is ready to collect: it has to
 * check that the platform actually holds settlement credentials.
 */
test('a platform default tenant cannot accept payments without platform credentials', function (): void {
    Config::set('services.settlement.paystack.secret_key', null);

    $tenant = Tenant::factory()->create([
        'settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT,
    ]);

    expect($tenant->canAcceptPayments())->toBeFalse();
});

test('a platform default tenant can accept payments once the platform is configured', function (): void {
    Config::set('services.settlement.paystack.secret_key', 'sk_test_platform');

    $tenant = Tenant::factory()->create([
        'settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT,
    ]);

    expect($tenant->canAcceptPayments())->toBeTrue();
});

test('platform credentials do not let an own gateway tenant accept payments', function (): void {
    // An own_gateway tenant collects through its own Paystack account, so the
    // platform's credentials say nothing about whether it is ready.
    Config::set('services.settlement.paystack.secret_key', 'sk_test_platform');

    $tenant = Tenant::factory()->create([
        'settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY,
    ]);

    expect($tenant->canAcceptPayments())->toBe($tenant->hasActivePaymentGateway());
});
