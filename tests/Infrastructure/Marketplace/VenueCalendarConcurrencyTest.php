<?php

declare(strict_types=1);

use App\Models\Member;
use App\Models\Package;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    expect((string) config('database.connections.landlord.database'))->toContain('testing');
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
});

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

/**
 * One enterprise organisation with a bookable venue, a staff member and a team user.
 *
 * @return array{tenant: Tenant, shop: Shop, listing: StoreListing, member: Member, user: User, from: CarbonImmutable, to: CarbonImmutable}
 */
function venueRaceScenario(): array
{
    $tenant = Tenant::factory()->create(['package_id' => Package::query()->where('slug', 'enterprise')->value('id')]);
    $tenant->syncFeaturesFromPackage();
    $shop = Shop::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
    $listing = StoreListing::factory()->create([
        'shop_id' => $shop->id, 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_HOUR, 'rental_price_pesewas' => 10000, 'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'capacity_breakdown' => ['banquet' => 100], 'is_bookable' => true,
    ]);
    $member = Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'ama@race.example', 'is_staff' => true]);
    $from = CarbonImmutable::now()->addDays(3)->setTime(9, 0);

    Artisan::call('venue:enable-requests', ['tenant' => $tenant->slug]);
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage venue', 'guard_name' => 'web'], ['category' => 'venue']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo('manage venue');

    return ['tenant' => $tenant, 'shop' => $shop, 'listing' => $listing, 'member' => $member, 'user' => $user, 'from' => $from, 'to' => $from->addHours(2)];
}

/**
 * Starts every worker while the listing row is locked, so all of them queue on
 * the same lock, then releases them together. The first started is granted first.
 *
 * @param  list<array<string, mixed>>  $jobs
 * @return list<string>
 */
function raceVenueWorkers(StoreListing $listing, array $jobs): array
{
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $workers = [];
    foreach ($jobs as $job) {
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/marketplace/venue-calendar-worker.php')], base_path(), $environment);
        $worker->setTimeout(90)->setInput(json_encode($job, JSON_THROW_ON_ERROR));
        $workers[] = $worker;
    }
    $db = $listing->getConnection();
    $db->beginTransaction();
    StoreListing::query()->withStaffOnly()->lockForUpdate()->findOrFail($listing->id);
    $results = [];
    try {
        foreach ($workers as $index => $worker) {
            $worker->start();
            $deadline = microtime(true) + 30;
            do {
                $db->select('select pg_stat_clear_snapshot()');
                $waiting = (int) $db->selectOne("select count(*) as waiting from pg_stat_activity where datname=current_database() and application_name='venue-calendar-worker' and wait_event_type='Lock'")->waiting;
                usleep(20000);
            } while ($waiting < $index + 1 && microtime(true) < $deadline);
            expect($waiting)->toBeGreaterThanOrEqual($index + 1, 'worker '.$index.' did not queue on the listing lock');
        }
        $db->commit();
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $results[] = mb_trim(str_replace('READY', '', $worker->getOutput()));
        }
    } finally {
        if ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    return $results;
}

function venueJob(array $scenario, string $action, array $extra = []): array
{
    return $extra + ['action' => $action, 'shop' => $scenario['shop']->id, 'listing' => $scenario['listing']->id, 'from' => $scenario['from']->toIso8601String(), 'to' => $scenario['to']->toIso8601String(), 'member' => $scenario['member']->id, 'actor' => (string) $scenario['user']->id];
}

function occupying(array $scenario): int
{
    return VenueBooking::query()->where('store_listing_id', $scenario['listing']->id)->blockingCalendar()
        ->overlapping($scenario['from'], $scenario['to'])->count();
}

it('lets exactly one of a public request and a blackout take the slot', function (array $order, array $expected): void {
    $s = venueRaceScenario();
    $jobs = array_map(fn (string $action) => venueJob($s, $action), $order);

    expect(raceVenueWorkers($s['listing'], $jobs))->toBe($expected)
        ->and(occupying($s))->toBe(1);
})->with([
    'public wins' => [['public_create', 'blackout'], ['CREATED', 'UNAVAILABLE']],
    'blackout wins' => [['blackout', 'public_create'], ['BLACKOUT', 'UNAVAILABLE']],
]);

it('lets exactly one of a public request and a staff request take the slot', function (array $order, array $expected): void {
    $s = venueRaceScenario();
    $jobs = array_map(fn (string $action) => venueJob($s, $action), $order);

    expect(raceVenueWorkers($s['listing'], $jobs))->toBe($expected)
        ->and(occupying($s))->toBe(1);
})->with([
    'public first' => [['public_create', 'internal_request'], ['CREATED', 'UNAVAILABLE']],
    'staff first' => [['internal_request', 'public_create'], ['REQUESTED', 'UNAVAILABLE']],
]);

it('lets only one of two host holds on overlapping enquiries win', function (): void {
    $s = venueRaceScenario();
    $enquiry = fn (): VenueBooking => VenueBooking::factory()->create([
        'tenant_id' => $s['tenant']->id, 'shop_id' => $s['shop']->id, 'store_listing_id' => $s['listing']->id,
        'status' => VenueBooking::STATUS_PENDING_QUOTE, 'starts_at' => $s['from'], 'ends_at' => $s['to'],
    ]);
    $first = $enquiry();
    $second = $enquiry();

    $results = raceVenueWorkers($s['listing'], [venueJob($s, 'host_hold', ['booking' => $first->id]), venueJob($s, 'host_hold', ['booking' => $second->id])]);

    expect($results)->toBe(['HELD', 'UNAVAILABLE'])
        ->and($first->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_PAYMENT)
        ->and($second->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_QUOTE)
        ->and(occupying($s))->toBe(1);
});

it('serialises internal approval and cancellation against a blackout', function (array $order, array $expected, int $occupying): void {
    $s = venueRaceScenario();
    $request = VenueBooking::factory()->internal()->create([
        'tenant_id' => $s['tenant']->id, 'shop_id' => $s['shop']->id, 'store_listing_id' => $s['listing']->id, 'starts_at' => $s['from'], 'ends_at' => $s['to'],
    ]);
    $jobs = array_map(fn (string $action) => venueJob($s, $action, ['booking' => $request->id]), $order);

    expect(raceVenueWorkers($s['listing'], $jobs))->toBe($expected)
        ->and(occupying($s))->toBe($occupying);
})->with([
    'cancel then blackout' => [['internal_cancel', 'blackout'], ['CANCELLED', 'BLACKOUT'], 1],
    'blackout cannot overlay a pending request' => [['blackout', 'internal_cancel'], ['UNAVAILABLE', 'CANCELLED'], 0],
    'approval keeps the slot against a blackout' => [['internal_approve', 'blackout'], ['APPROVED', 'UNAVAILABLE'], 1],
]);

it('serialises blackout removal against new occupancy', function (array $order, array $expected, int $occupying): void {
    $s = venueRaceScenario();
    $block = VenueBooking::factory()->create([
        'tenant_id' => $s['tenant']->id, 'shop_id' => $s['shop']->id, 'store_listing_id' => $s['listing']->id,
        'status' => VenueBooking::STATUS_BLOCKED, 'starts_at' => $s['from'], 'ends_at' => $s['to'],
    ]);
    $jobs = array_map(fn (string $action) => venueJob($s, $action, ['booking' => $block->id]), $order);

    expect(raceVenueWorkers($s['listing'], $jobs))->toBe($expected)
        ->and(occupying($s))->toBe($occupying);
})->with([
    'removal first frees the slot for the request' => [['blackout_remove', 'public_create'], ['REMOVED', 'CREATED'], 1],
    'request first finds the blackout' => [['public_create', 'blackout_remove'], ['UNAVAILABLE', 'REMOVED'], 0],
]);

it('lets a late legacy payment confirm only while its slot is still free', function (array $order, array $expected, string $status, int $conflicts): void {
    $s = venueRaceScenario();
    $legacy = VenueBooking::factory()->create([
        'tenant_id' => $s['tenant']->id, 'shop_id' => $s['shop']->id, 'store_listing_id' => $s['listing']->id,
        'status' => VenueBooking::STATUS_PENDING_PAYMENT, 'quote_valid_until' => now()->subHour(),
        'deposit_required_pesewas' => 20000, 'total_amount_pesewas' => 20000, 'starts_at' => $s['from'], 'ends_at' => $s['to'],
    ]);
    $jobs = array_map(fn (string $action) => venueJob($s, $action, ['booking_reference' => $legacy->booking_reference, 'reference' => 'pstk_late_1', 'amount' => 20000]), $order);

    expect(raceVenueWorkers($s['listing'], $jobs))->toBe($expected)
        ->and($legacy->fresh()->status)->toBe($status)
        ->and(VenueBookingAction::query()->where('booking_id', $legacy->id)->where('action', VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT)->count())->toBe($conflicts)
        ->and(occupying($s))->toBe(1);
})->with([
    'blackout won the slot first' => [['blackout', 'confirm_payment'], ['BLACKOUT', 'CONFLICT'], VenueBooking::STATUS_PENDING_PAYMENT, 1],
    'payment confirms first' => [['confirm_payment', 'blackout'], ['CONFIRMED', 'UNAVAILABLE'], VenueBooking::STATUS_CONFIRMED, 0],
]);

it('keeps a retried conflicting payment as one evidence row', function (): void {
    $s = venueRaceScenario();
    $legacy = VenueBooking::factory()->create([
        'tenant_id' => $s['tenant']->id, 'shop_id' => $s['shop']->id, 'store_listing_id' => $s['listing']->id,
        'status' => VenueBooking::STATUS_PENDING_PAYMENT, 'quote_valid_until' => now()->subHour(), 'total_amount_pesewas' => 20000, 'starts_at' => $s['from'], 'ends_at' => $s['to'],
    ]);
    $confirm = fn () => venueJob($s, 'confirm_payment', ['booking_reference' => $legacy->booking_reference, 'reference' => 'pstk_retry_1', 'amount' => 20000]);

    expect(raceVenueWorkers($s['listing'], [venueJob($s, 'blackout'), $confirm(), $confirm()]))->toBe(['BLACKOUT', 'CONFLICT', 'CONFLICT'])
        ->and(VenueBookingAction::query()->where('booking_id', $legacy->id)->count())->toBe(1)
        ->and(VenueBookingAction::query()->where('booking_id', $legacy->id)->sole()->evidence)->toMatchArray(['provider_reference' => 'pstk_retry_1', 'amount_pesewas' => 20000, 'currency' => 'GHS']);
});

it('does not delay a request for a different time on the same venue', function (): void {
    $s = venueRaceScenario();
    $later = ['from' => $s['from']->addDays(1)->toIso8601String(), 'to' => $s['from']->addDays(1)->addHours(2)->toIso8601String()];

    expect(raceVenueWorkers($s['listing'], [venueJob($s, 'public_create'), venueJob($s, 'blackout', $later)]))->toBe(['CREATED', 'BLACKOUT'])
        ->and(VenueBooking::query()->where('store_listing_id', $s['listing']->id)->blockingCalendar()->count())->toBe(2);
});

function approvalRequest(array $s, array $attributes = []): VenueBooking
{
    return VenueBooking::factory()->create($attributes + [
        'tenant_id' => $s['tenant']->id, 'shop_id' => $s['shop']->id, 'store_listing_id' => $s['listing']->id,
        'status' => VenueBooking::STATUS_PENDING_HOST_APPROVAL, 'approval_flow_version' => 1, 'starts_at' => $s['from'], 'ends_at' => $s['to'],
        'contract_terms_snapshot' => ['policy' => ['default_hold_hours' => 48, 'max_hold_hours' => 168, 'timezone' => 'UTC']],
    ]);
}

it('lets only one of two host approvals hold the same dates', function (): void {
    $s = venueRaceScenario();
    $first = approvalRequest($s);
    $second = approvalRequest($s);

    $results = raceVenueWorkers($s['listing'], [venueJob($s, 'approve', ['booking' => $first->id]), venueJob($s, 'approve', ['booking' => $second->id])]);

    expect($results)->toBe(['APPROVED', 'UNAVAILABLE'])
        ->and($first->fresh()->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD)
        ->and($second->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL)
        ->and(VenueBookingAction::query()->where('action', VenueBookingAction::ACTION_APPROVED)->count())->toBe(1)
        ->and(occupying($s))->toBe(1);
});

it('lets exactly one of an approval and another writer take the slot', function (array $order, array $expected): void {
    $s = venueRaceScenario();
    $request = approvalRequest($s);
    $jobs = array_map(fn (string $action) => venueJob($s, $action, ['booking' => $request->id]), $order);

    expect(raceVenueWorkers($s['listing'], $jobs))->toBe($expected)
        ->and(occupying($s))->toBe(1);
})->with([
    'approval then blackout' => [['approve', 'blackout'], ['APPROVED', 'UNAVAILABLE']],
    'blackout then approval' => [['blackout', 'approve'], ['BLACKOUT', 'UNAVAILABLE']],
    'approval then staff request' => [['approve', 'internal_request'], ['APPROVED', 'UNAVAILABLE']],
    'staff request then approval' => [['internal_request', 'approve'], ['REQUESTED', 'UNAVAILABLE']],
    'approval then legacy public request' => [['approve', 'public_create'], ['APPROVED', 'UNAVAILABLE']],
    'legacy public request then approval' => [['public_create', 'approve'], ['CREATED', 'UNAVAILABLE']],
]);

it('lets an expired hold be renewed only if nobody took the dates first', function (array $order, array $expected, string $holdStatus): void {
    $s = venueRaceScenario();
    $hold = approvalRequest($s, ['status' => VenueBooking::STATUS_APPROVED_HOLD, 'hold_expires_at' => now()->subMinute()]);
    $jobs = array_map(fn (string $action) => venueJob($s, $action, ['booking' => $hold->id]), $order);

    expect(raceVenueWorkers($s['listing'], $jobs))->toBe($expected)
        ->and($hold->fresh()->effectiveStatus())->toBe($holdStatus)
        ->and(occupying($s))->toBe(1);
})->with([
    'renewal first' => [['renew', 'public_create'], ['RENEWED', 'UNAVAILABLE'], VenueBooking::STATUS_APPROVED_HOLD],
    'someone else first' => [['public_create', 'renew'], ['CREATED', 'UNAVAILABLE'], VenueBooking::STATUS_HOLD_EXPIRED],
]);
