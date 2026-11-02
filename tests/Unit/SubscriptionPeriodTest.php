<?php

declare(strict_types=1);

use App\Services\Billing\SubscriptionPeriod;
use Carbon\CarbonImmutable;

it('creates a bounded first period with its original calendar anchor', function (string $start, string $interval, string $expected): void {
    $date = CarbonImmutable::parse($start, 'America/New_York');
    $period = SubscriptionPeriod::starting($interval, $date);
    expect($period['current_period_end']->format('Y-m-d H:i:s.u'))->toBe($expected.' 17:23:45.123456')
        ->and($period['current_period_end']->timezoneName)->toBe('America/New_York')
        ->and($period['billing_anchor_day'])->toBe($date->day)
        ->and($period['billing_anchor_month'])->toBe($date->month)
        ->and($date->format('Y-m-d H:i:s.u'))->toBe($start);
})->with([
    ['2027-01-31 17:23:45.123456', 'month', '2027-02-28'],
    ['2028-01-31 17:23:45.123456', 'month', '2028-02-29'],
    ['2027-03-31 17:23:45.123456', 'month', '2027-04-30'],
    ['2027-04-30 17:23:45.123456', 'month', '2027-05-30'],
    ['2028-02-29 17:23:45.123456', 'year', '2029-02-28'],
]);

it('restores the intended monthly day and annual leap date after a clamp', function (): void {
    expect(SubscriptionPeriod::end(CarbonImmutable::parse('2027-02-28 17:23:45'), 'month', 31, 1)->toDateTimeString())->toBe('2027-03-31 17:23:45')
        ->and(SubscriptionPeriod::end(CarbonImmutable::parse('2031-02-28 17:23:45'), 'year', 29, 2)->toDateTimeString())->toBe('2032-02-29 17:23:45');
});

it('refuses unsupported calendar anchors', function (string $interval, int $day, int $month): void {
    expect(fn () => SubscriptionPeriod::end(CarbonImmutable::parse('2027-01-31'), $interval, $day, $month))->toThrow(InvalidArgumentException::class);
})->with([['week', 31, 1], ['month', 0, 1], ['month', 32, 1], ['year', 1, 0], ['year', 1, 13]]);
