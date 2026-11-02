<?php

declare(strict_types=1);

use App\Models\StoreCommercialAgreement;
use App\Models\StoreOrder;
use App\Models\VenueBookingCharge;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    expect((string) config('database.connections.landlord.database'))->toContain('testing');
});

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

/**
 * Starts every worker at the same moment, each holding its locks for the same
 * time, and returns when each held them. A deadlock or a lock taken in the wrong
 * order shows up as a worker error.
 *
 * @param  list<array<string, mixed>>  $jobs
 * @return list<array{action: string, enter: float, exit: float}>
 */
function commerceLockRace(array $jobs, int $holdMs = 350): array
{
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $startAt = microtime(true) + 3.0;
    $workers = array_map(function (array $job) use ($environment, $startAt, $holdMs): Process {
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/marketplace/commerce-lock-worker.php')], base_path(), $environment);

        return $worker->setTimeout(60)->setInput(json_encode($job + ['start_at' => $startAt, 'hold_ms' => $holdMs], JSON_THROW_ON_ERROR));
    }, $jobs);
    foreach ($workers as $worker) {
        $worker->start();
    }
    $results = [];
    foreach ($workers as $worker) {
        $worker->wait();
        $result = json_decode(mb_trim($worker->getOutput()), true);
        expect($result)->toBeArray($worker->getErrorOutput())->and($result)->not->toHaveKey('error', $result['error'] ?? '');
        $results[] = $result;
    }

    return $results;
}

function commerceOverlap(array $a, array $b): bool
{
    return $a['enter'] < $b['exit'] && $b['enter'] < $a['exit'];
}

/** @return array{charge: VenueBookingCharge, balance: VenueBookingCharge, purchase: StoreOrder} */
function commerceScenario(): array
{
    $charge = VenueBookingCharge::factory()->create();
    $balance = VenueBookingCharge::factory()->forQuote($charge->quote, 'rental_balance')->create();
    $agreement = StoreCommercialAgreement::factory()->create(['shop_id' => $charge->shop_id, 'tenant_id' => $charge->tenant_id, 'version' => 2]);

    return ['charge' => $charge, 'balance' => $balance, 'purchase' => StoreOrder::factory()->create(['agreement_id' => $agreement->id])];
}

it('serialises a venue money lock, a calendar writer and a second charge on the same booking without deadlock', function (): void {
    $s = commerceScenario();
    $listing = $s['charge']->booking->store_listing_id;

    [$initial, $calendar, $balance] = commerceLockRace([
        ['action' => 'order_lock', 'order' => $s['charge']->order_id],
        ['action' => 'calendar_listing', 'listing' => $listing],
        ['action' => 'order_lock', 'order' => $s['balance']->order_id],
    ]);

    expect(commerceOverlap($initial, $calendar))->toBeFalse()
        ->and(commerceOverlap($initial, $balance))->toBeFalse()
        ->and(commerceOverlap($calendar, $balance))->toBeFalse();
});

it('serialises a booking-level calendar writer against that booking money lock', function (): void {
    $s = commerceScenario();

    [$money, $calendar] = commerceLockRace([
        ['action' => 'order_lock', 'order' => $s['charge']->order_id],
        ['action' => 'calendar_booking', 'booking' => $s['charge']->booking_id],
    ]);

    expect(commerceOverlap($money, $calendar))->toBeFalse();
});

it('serialises tenant-wide payout work, venue money and purchase money of one organisation without deadlock', function (): void {
    $s = commerceScenario();

    $results = commerceLockRace([
        ['action' => 'tenant_lock', 'tenant' => $s['charge']->tenant_id],
        ['action' => 'order_lock', 'order' => $s['charge']->order_id],
        ['action' => 'order_lock', 'order' => $s['purchase']->id],
    ]);

    foreach ([[0, 1], [0, 2], [1, 2]] as [$a, $b]) {
        expect(commerceOverlap($results[$a], $results[$b]))->toBeFalse();
    }
});

it('lets a calendar writer run beside tenant-only work, since it never takes the tenant lock', function (): void {
    $s = commerceScenario();

    [$tenant, $calendar] = commerceLockRace([
        ['action' => 'tenant_lock', 'tenant' => $s['charge']->tenant_id],
        ['action' => 'calendar_listing', 'listing' => $s['charge']->booking->store_listing_id],
    ], 600);

    expect(commerceOverlap($tenant, $calendar))->toBeTrue();
});

it('does not delay money on another organisation or another venue', function (): void {
    $s = commerceScenario();
    $other = VenueBookingCharge::factory()->create();

    [$first, $second] = commerceLockRace([
        ['action' => 'order_lock', 'order' => $s['charge']->order_id],
        ['action' => 'order_lock', 'order' => $other->order_id],
    ], 600);

    expect(commerceOverlap($first, $second))->toBeTrue();
});
