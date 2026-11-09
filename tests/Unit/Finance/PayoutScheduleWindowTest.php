<?php

declare(strict_types=1);

use App\Models\OrganisationPayoutPolicy;
use App\Services\Finance\PayoutScheduleWindow;
use Carbon\CarbonImmutable;

test('payout windows use the first occurrence or first valid instant across clock changes', function (string $zone, string $time, string $now, string $expected) {
    $policy = new OrganisationPayoutPolicy(['timezone' => $zone, 'local_time' => $time, 'cadence' => 'daily']);
    expect(app(PayoutScheduleWindow::class)->due($policy, CarbonImmutable::parse($now))->utc()->format('Y-m-d H:i'))->toBe($expected);
})->with([
    ['America/New_York', '01:30', '2026-11-01T06:30:00Z', '2026-11-01 05:30'],
    ['America/New_York', '02:30', '2026-03-08T07:00:00Z', '2026-03-08 07:00'],
    ['Australia/Lord_Howe', '02:15', '2026-10-03T15:30:00Z', '2026-10-03 15:30'],
]);

test('a repeated-clock proposal expires after exactly 24 elapsed hours', function () {
    $due = CarbonImmutable::parse('2026-11-01T05:30:00Z')->setTimezone('America/New_York');
    expect(app(PayoutScheduleWindow::class)->expiresAt($due)->utc()->format('Y-m-d H:i'))->toBe('2026-11-02 05:30');
});
