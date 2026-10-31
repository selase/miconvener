<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\LedgerTransaction;
use App\Models\Permission;
use App\Models\Shop;
use App\Models\StoreCommercialAgreement;
use App\Models\StoreListing;
use App\Models\StorePaymentAttempt;
use App\Models\StoreReceipt;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueBookingCharge;
use App\Services\Giving\GivingGateways;
use App\Services\Marketplace\StorePayments;
use App\Services\Marketplace\VenueCommercialQuotes;
use App\Services\Marketplace\VenuePaymentRollout;
use App\Services\Marketplace\VenueRequestRollout;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    expect((string) config('database.connections.landlord.database'))->toContain('testing');
    config(['services.settlement.paystack.secret_key' => 'sk_test_venue_race']);
    Http::preventStrayRequests();
    Http::fake([
        '*api.paystack.co/customer*' => Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_VENUE']]),
        '*api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/venue-race']]),
    ]);
});

afterEach(function (): void {
    $this->truncateDatabaseTables();
});

/**
 * A committed, accepted venue contract with a started initial payment, plus a
 * rival booking for the same dates and the host who may act on it.
 *
 * @return array{attempt: StorePaymentAttempt, booking: VenueBooking, rival: VenueBooking, host: User, evidence: array<string, mixed>}
 */
function venuePaymentScenario(): array
{
    $tenant = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    $shop = Shop::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
    $listing = StoreListing::factory()->create(['shop_id' => $shop->id, 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED, 'is_bookable' => true]);
    StoreCommercialAgreement::factory()->reservation()->create(['shop_id' => $shop->id, 'tenant_id' => $tenant->id, 'percentage' => '3.00', 'cap_pesewas' => null,
        'account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity())]);
    foreach ([VenueRequestRollout::FEATURE, VenuePaymentRollout::FEATURE] as $slug) {
        Feature::query()->firstOrCreate(['slug' => $slug], ['name' => $slug, 'type' => 'boolean', 'description' => 'test']);
        $tenant->features()->updateOrCreate(['feature_key' => $slug], ['enabled' => true, 'meta' => ['source' => 'admin_override']]);
        Cache::forget('tenant_'.$tenant->id.'_feature_'.$slug);
    }
    Permission::query()->firstOrCreate(['name' => 'manage venue', 'guard_name' => 'web'], ['category' => 'venue']);
    $host = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $host->givePermissionTo('manage venue');
    app(App\Services\Tenancy\TenantContext::class)->setTenant($tenant);

    $start = CarbonImmutable::now()->addDays(30)->setTime(9, 0);
    $make = function (string $email, string $status, ?CarbonImmutable $hold) use ($tenant, $shop, $listing, $start): VenueBooking {
        $booking = VenueBooking::factory()->create([
            'tenant_id' => $tenant->id, 'shop_id' => $shop->id, 'store_listing_id' => $listing->id, 'status' => $status, 'approval_flow_version' => 1,
            'hold_expires_at' => $hold, 'starts_at' => $start, 'ends_at' => $start->addHours(8), 'planner_email' => $email,
        ]);
        if ($hold !== null) {
            VenueBookingAction::factory()->create(['booking_id' => $booking->id, 'shop_id' => $shop->id, 'tenant_id' => $tenant->id, 'action' => VenueBookingAction::ACTION_APPROVED, 'hold_expires_at' => $hold]);
        }

        return $booking;
    };
    $booking = $make('buyer@planner.test', VenueBooking::STATUS_APPROVED_HOLD, CarbonImmutable::now()->addDays(3)->setMicroseconds(0));
    $rival = $make('rival@planner.test', VenueBooking::STATUS_PENDING_HOST_APPROVAL, null);

    $session = tap(new Store('venue-race', new ArraySessionHandler(120)), fn (Store $s) => $s->put(proofFor('buyer@planner.test')));
    $quotes = app(VenueCommercialQuotes::class);
    $quote = $quotes->issue($booking, $host, [
        'rental_pesewas' => 100000, 'extras_pesewas' => 0, 'security_pesewas' => 0, 'payment_plan' => 'deposit', 'deposit_pesewas' => 30000,
        'balance_due_at' => $start->subDays(7)->toIso8601String(), 'security_due_at' => null,
        'cancellation_terms' => 'Full refund up to 14 days before.', 'support_contact' => 'bookings@host.example',
    ], (string) Str::uuid());
    $quotes->accept($quote, $session, $quote->terms_hash, (string) Str::uuid());
    $charge = VenueBookingCharge::query()->where('quote_id', $quote->id)->where('purpose', VenueBookingCharge::RENTAL_INITIAL)->firstOrFail();
    $attempt = app(StorePayments::class)->start($charge->order, $session, (string) Str::uuid(), $quote->terms_hash);
    $evidence = ['status' => 'success', 'provider' => 'paystack', 'reference' => $attempt->reference, 'reference_reported' => true,
        'account_fingerprint' => $attempt->account_fingerprint, 'amount' => $attempt->amount_pesewas, 'currency' => 'GHS', 'fees' => 150,
        'paid_at' => now()->toIso8601String(), 'transaction_id' => 4242,
        'metadata' => ['source' => 'miconvener_venue', 'venue_charge_id' => $charge->id, 'store_order_id' => $attempt->order_id, 'store_attempt_id' => $attempt->id, 'seller_id' => $attempt->tenant_id]];

    return ['attempt' => $attempt, 'booking' => $booking, 'rival' => $rival, 'host' => $host, 'evidence' => $evidence];
}

/**
 * Starts every worker, lets each queue behind a lock the test holds on the
 * venue, then releases them together.
 *
 * @param  list<array<string, mixed>>  $jobs
 * @return list<string>
 */
function venuePaymentRace(string $listingId, array $jobs): array
{
    $environment = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $connection = DB::connection('landlord');
    $workers = array_map(fn (array $job) => (new Process([PHP_BINARY, base_path('tests/Fixtures/marketplace/venue-payment-worker.php')], base_path(), $environment))
        ->setTimeout(90)->setInput(json_encode($job, JSON_THROW_ON_ERROR)), $jobs);
    $connection->beginTransaction();
    StoreListing::query()->lockForUpdate()->findOrFail($listingId);
    try {
        foreach ($workers as $index => $worker) {
            $worker->start();
            $deadline = microtime(true) + 30;
            do {
                $connection->select('select pg_stat_clear_snapshot()');
                $waiting = (int) $connection->selectOne("select count(*) as waiting from pg_stat_activity where datname=current_database() and application_name='venue-payment-worker' and wait_event_type='Lock'")->waiting;
                usleep(20000);
            } while ($waiting < $index + 1 && microtime(true) < $deadline);
            expect($waiting)->toBeGreaterThanOrEqual($index + 1);
        }
        $connection->commit();
        $outputs = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
            $outputs[] = mb_trim(str_replace("READY\n", '', $worker->getOutput()));
        }

        return $outputs;
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
}

function venueSales(): int
{
    return LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_sale')->count();
}

it('posts and confirms once when the callback and the webhook deliver the same payment together', function (): void {
    $s = venuePaymentScenario();
    $job = ['action' => 'record_confirm', 'attempt' => $s['attempt']->id, 'evidence' => $s['evidence']];

    $outputs = venuePaymentRace($s['booking']->store_listing_id, [$job, $job]);

    expect($outputs)->toBe(['CONFIRMED', 'CONFIRMED'])->and(venueSales())->toBe(1)
        ->and(StoreReceipt::query()->count())->toBe(1)
        ->and($s['booking']->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)
        ->and(DB::connection('landlord')->table('store_payment_observations')->count())->toBe(1);
});

it('never confirms a paid booking and a rival on the same dates, whoever wins', function (): void {
    $s = venuePaymentScenario();

    $outputs = venuePaymentRace($s['booking']->store_listing_id, [
        ['action' => 'record_confirm', 'attempt' => $s['attempt']->id, 'evidence' => $s['evidence']],
        ['action' => 'occupy', 'booking' => $s['rival']->id],
    ]);

    $paid = $s['booking']->fresh()->status === VenueBooking::STATUS_CONFIRMED;
    $rivalWon = $s['rival']->fresh()->status === VenueBooking::STATUS_CONFIRMED;
    expect($paid xor $rivalWon)->toBeTrue()->and(venueSales())->toBe($paid ? 1 : 0)
        ->and($s['attempt']->order->fresh()->payment_state)->toBe($paid ? 'paid' : 'captured_review')
        ->and($outputs)->toContain($paid ? 'TAKEN' : 'OCCUPIED');
});

it('either confirms the paid booking or keeps the money for review when the host releases at the same time', function (): void {
    $s = venuePaymentScenario();

    $outputs = venuePaymentRace($s['booking']->store_listing_id, [
        ['action' => 'record_confirm', 'attempt' => $s['attempt']->id, 'evidence' => $s['evidence']],
        ['action' => 'release', 'booking' => $s['booking']->id, 'host' => $s['host']->id],
    ]);

    $status = $s['booking']->fresh()->status;
    expect($status)->toBeIn([VenueBooking::STATUS_CONFIRMED, VenueBooking::STATUS_CANCELLED]);
    if ($status === VenueBooking::STATUS_CONFIRMED) {
        expect(venueSales())->toBe(1)->and($outputs)->toContain('REFUSED');
    } else {
        expect(venueSales())->toBe(0)->and($s['attempt']->order->fresh()->payment_state)->toBe('captured_review')->and($outputs)->toContain('RELEASED');
    }
});

it('review legacy conflict recording obeys the money tenant then listing lock order', function (): void {
    $s = venuePaymentScenario();
    $db = DB::connection('landlord');
    $env = ['DATABASE_URL' => '', 'DB_DATABASE' => (string) config('database.connections.landlord.database'), 'DB_HOST' => (string) config('database.connections.landlord.host'), 'APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false', 'PAO_DISABLE' => '1'];
    $worker = (new Process([PHP_BINARY, base_path('tests/Fixtures/marketplace/venue-payment-worker.php')], base_path(), $env))->setTimeout(30)->setInput(json_encode(['action' => 'legacy_conflict', 'booking' => $s['rival']->id], JSON_THROW_ON_ERROR));
    $error = null;
    $db->beginTransaction();
    try {
        Tenant::query()->lockForUpdate()->findOrFail($s['booking']->tenant_id);
        $worker->start();
        $deadline = microtime(true) + 10;
        do {
            $db->select('select pg_stat_clear_snapshot()');
            $waiting = (int) $db->selectOne("select count(*) as n from pg_stat_activity where datname=current_database() and application_name='venue-payment-worker' and wait_event_type='Lock'")->n;
            usleep(20000);
        } while ($waiting < 1 && microtime(true) < $deadline);
        expect($waiting)->toBe(1);
        app(App\Services\Venue\VenueCalendarLocks::class)->listing($s['booking']->store_listing_id);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    } finally {
        if ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
    }
    $worker->wait();
    fwrite(STDERR, json_encode(['review_parent_error' => $error, 'review_worker_output' => $worker->getOutput()], JSON_THROW_ON_ERROR).PHP_EOL);
    expect($error)->toBeNull()
        ->and($worker->getOutput())->not->toContain('ERROR');
});

it('records one full refund when two staff request a refund of the same paid rental together', function (): void {
    $s = venuePaymentScenario();
    Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $s['host']->givePermissionTo('manage store finance');
    $capture = app(App\Services\Marketplace\StorePaymentEvidence::class)->record($s['attempt'], $s['evidence']);
    app(App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($capture);
    $orderId = $s['attempt']->order_id;

    $outputs = venuePaymentRace($s['booking']->store_listing_id, [
        ['action' => 'refund_request', 'order' => $orderId, 'host' => $s['host']->id, 'key' => (string) Str::uuid()],
        ['action' => 'refund_request', 'order' => $orderId, 'host' => $s['host']->id, 'key' => (string) Str::uuid()],
    ]);

    expect($outputs)->toBe(['REFUND_needs_funding', 'REFUND_needs_funding'])->and(DB::connection('landlord')->table('store_refunds')->where('order_id', $orderId)->count())->toBe(1)
        ->and(LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_refund')->count())->toBe(0);
});
