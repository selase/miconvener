<?php

declare(strict_types=1);

use App\Enum\DuesPeriodEnum;
use App\Models\CommunitySettings;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Tenant;
use App\Services\Dues\DuesCharges;
use Carbon\CarbonImmutable;

test('periods use calendar anchors and inclusive ends', function (string $period, string $date, string $start, string $next, string $end) {
    $settings = new CommunitySettings;
    $period = DuesPeriodEnum::from($period);
    $date = CarbonImmutable::parse($date, 'Africa/Accra');
    $anchor = $period->periodStartFor($date, $settings);

    expect($anchor->toDateString())->toBe($start)
        ->and($period->nextStart($anchor, $settings)->toDateString())->toBe($next)
        ->and($period->periodEnd($anchor, $settings)->toDateString())->toBe($end)
        ->and($date->format('H:i:s'))->toBe('13:45:00')
        ->and($anchor->format('H:i:s'))->toBe('00:00:00');
})->with([
    ['daily', '2026-10-15 13:45', '2026-10-15', '2026-10-16', '2026-10-15'],
    ['weekly', '2026-10-15 13:45', '2026-10-12', '2026-10-19', '2026-10-18'],
    ['monthly', '2026-01-31 13:45', '2026-01-01', '2026-02-01', '2026-01-31'],
    ['monthly', '2028-02-29 13:45', '2028-02-01', '2028-03-01', '2028-02-29'],
    ['quarterly', '2026-11-20 13:45', '2026-10-01', '2027-01-01', '2026-12-31'],
    ['yearly', '2026-10-15 13:45', '2026-01-01', '2027-01-01', '2026-12-31'],
]);

test('financial years anchor on either side of the chosen month', function (string $date, string $start, string $next) {
    $settings = new CommunitySettings(['financial_year_start_month' => 4]);
    $anchor = DuesPeriodEnum::Yearly->periodStartFor(CarbonImmutable::parse($date), $settings);

    expect($anchor->toDateString())->toBe($start)
        ->and(DuesPeriodEnum::Yearly->nextStart($anchor, $settings)->toDateString())->toBe($next);
})->with([
    ['2026-10-15', '2026-04-01', '2027-04-01'],
    ['2026-03-31', '2025-04-01', '2026-04-01'],
    ['2026-04-01', '2026-04-01', '2027-04-01'],
]);

test('weekly periods include their chosen start day', function (string $date) {
    $settings = new CommunitySettings(['week_starts_on' => 7]);
    $anchor = DuesPeriodEnum::Weekly->periodStartFor(CarbonImmutable::parse($date), $settings);

    expect($anchor->toDateString())->toBe('2026-10-11');
})->with(['2026-10-15', '2026-10-11']);

test('calendar periods retain the local timezone across daylight saving', function () {
    $settings = new CommunitySettings(['timezone' => 'America/New_York']);
    $date = CarbonImmutable::parse('2026-03-08 14:00', 'America/New_York');
    $start = DuesPeriodEnum::Daily->periodStartFor($date, $settings);
    $next = DuesPeriodEnum::Daily->nextStart($start, $settings);

    expect($start->format('Y-m-d H:i P'))->toBe('2026-03-08 00:00 -05:00')
        ->and($next->format('Y-m-d H:i P'))->toBe('2026-03-09 00:00 -04:00')
        ->and($date->format('Y-m-d H:i P'))->toBe('2026-03-08 14:00 -04:00');
});

test('settings return unsaved defaults without creating a row', function () {
    $tenant = Tenant::factory()->create();
    $settings = CommunitySettings::for($tenant);

    expect($settings->exists)->toBeFalse()
        ->and($settings->tenant_id)->toBe($tenant->id)
        ->and($settings->timezone)->toBe('Africa/Accra')
        ->and($settings->week_starts_on)->toBe(1)
        ->and($settings->financial_year_start_month)->toBe(1)
        ->and($settings->generate_days_ahead)->toBe(7)
        ->and($settings->charge_current_period_on_join)->toBeFalse()
        ->and(CommunitySettings::query()->count())->toBe(0);
});

test('settings are persisted and resolved for the requested organisation', function () {
    $settings = CommunitySettings::factory()->create(['timezone' => 'Pacific/Auckland', 'week_starts_on' => 7]);
    $other = Tenant::factory()->create();

    expect(CommunitySettings::for($settings->tenant)->id)->toBe($settings->id)
        ->and(CommunitySettings::for($settings->tenant)->timezone)->toBe('Pacific/Auckland')
        ->and(CommunitySettings::for($other)->exists)->toBeFalse();
});

test('manual charges use the organisations configured calendar anchors', function (string $period, string $start, string $end) {
    $settings = CommunitySettings::factory()->create(['week_starts_on' => 7, 'financial_year_start_month' => 4]);
    $type = MembershipType::factory()->create(['tenant_id' => $settings->tenant_id, 'period' => $period]);
    $member = Member::factory()->create(['tenant_id' => $settings->tenant_id, 'membership_type_id' => $type->id]);
    $charge = app(DuesCharges::class)->createFor($member, CarbonImmutable::parse('2026-10-15'));

    expect($charge->period_start->toDateString())->toBe($start)
        ->and($charge->period_end->toDateString())->toBe($end);
})->with([
    ['daily', '2026-10-15', '2026-10-15'],
    ['weekly', '2026-10-11', '2026-10-17'],
    ['yearly', '2026-04-01', '2027-03-31'],
]);
