<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Tenant;
use App\Services\Finance\FeeCalculator;

/**
 * @param  int|null  $cap  Commission ceiling in minor units; null is uncapped.
 */
function eventWithFee(float $percentage, ?int $cap = 2000, string $bearer = 'organizer'): Event
{
    $tenant = Tenant::factory()->create(['package_id' => null]);

    return Event::factory()->create([
        'tenant_id' => $tenant->id,
        'platform_fee_percentage' => $percentage,
        'platform_fee_cap_amount' => $cap,
        'fee_bearer' => $bearer,
        'currency' => 'GHS',
    ]);
}

test('a GHS 100 ticket at 2 percent splits as agreed', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0), 10000);

    expect($breakdown->ticketAmount)->toBe(10000)
        ->and($breakdown->platformFee)->toBe(200)
        ->and($breakdown->gatewayFeeEstimate)->toBe(195)
        ->and($breakdown->chargedAmount)->toBe(10000)
        ->and($breakdown->organizerNet)->toBe(9605)
        ->and($breakdown->capApplied)->toBeFalse();
});

test('the cap binds on a GHS 1500 ticket', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0), 150000);

    expect($breakdown->platformFee)->toBe(2000)
        ->and($breakdown->capApplied)->toBeTrue();
});

test('the cap does not bind at exactly GHS 1000', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0), 100000);

    expect($breakdown->platformFee)->toBe(2000)
        ->and($breakdown->capApplied)->toBeFalse();
});

test('a null cap leaves the commission uncapped', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0, cap: null), 500000);

    expect($breakdown->platformFee)->toBe(10000)
        ->and($breakdown->capApplied)->toBeFalse();
});

test('a waived event charges no commission at all', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(0.0), 10000);

    expect($breakdown->platformFee)->toBe(0)
        ->and($breakdown->organizerNet)->toBe(9805);
});

test('an attendee-borne fee is added to what the buyer is charged', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0, bearer: 'attendee'), 10000);

    // The attendee absorbs the GHS 2 commission. The organizer still bears
    // Paystack's 1.95%, which it nets out of the larger charge regardless of
    // who agreed to pay the commission.
    expect($breakdown->chargedAmount)->toBe(10200)
        ->and($breakdown->platformFee)->toBe(200)
        ->and($breakdown->gatewayFeeEstimate)->toBe(199)
        ->and($breakdown->organizerNet)->toBe(9801);
});

test('a free ticket moves no money and incurs no fee', function () {
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0), 0);

    expect($breakdown->platformFee)->toBe(0)
        ->and($breakdown->chargedAmount)->toBe(0)
        ->and($breakdown->organizerNet)->toBe(0)
        ->and($breakdown->gatewayFeeEstimate)->toBe(0);
});

test('a negative ticket amount is rejected rather than silently coerced', function () {
    app(FeeCalculator::class)->for(eventWithFee(2.0), -1);
})->throws(InvalidArgumentException::class);

test('rounding is half-up on a half-pesewa commission', function () {
    // 2% of 2525 pesewas is 50.5, which must round to 51 rather than down.
    $breakdown = app(FeeCalculator::class)->for(eventWithFee(2.0), 2525);

    expect($breakdown->platformFee)->toBe(51);
});

test('an enterprise package inherits no ceiling so negotiated terms are not capped', function () {
    $package = App\Models\Package::factory()->create([
        'default_platform_fee_percentage' => 2.0,
        'default_platform_fee_cap_amount' => null,
    ]);
    $tenant = Tenant::factory()->create([
        'package_id' => $package->id,
        'platform_fee_cap_amount' => null,
    ]);
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'platform_fee_percentage' => null,
        'platform_fee_cap_amount' => null,
        'currency' => 'GHS',
    ]);

    $breakdown = app(FeeCalculator::class)->for($event, 500000);

    expect($breakdown->platformFee)->toBe(10000)
        ->and($breakdown->capApplied)->toBeFalse();
});
