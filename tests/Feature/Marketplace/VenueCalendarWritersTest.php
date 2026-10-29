<?php

declare(strict_types=1);

use App\Mail\Marketplace\BookingConfirmationGuest;
use App\Models\LedgerTransaction;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Services\Marketplace\VenueBookingService;
use App\Services\Venue\VenueBlackouts;
use App\Services\Venue\VenueCalendarLocks;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Mail::fake();
    $this->tenant = Tenant::factory()->create();
    $this->shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
    $this->listing = StoreListing::factory()->create(['shop_id' => $this->shop->id, 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED]);
    $this->from = Carbon::now()->addDays(5)->setTime(9, 0);
    $this->to = $this->from->copy()->addHours(2);
    $this->booking = fn (array $attributes = []): VenueBooking => VenueBooking::factory()->create($attributes + [
        'tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id,
        'starts_at' => $this->from, 'ends_at' => $this->to,
    ]);
    $this->service = app(VenueBookingService::class);
});

test('a host cannot hold or quote dates that another reservation now occupies', function (): void {
    ($this->booking)(['status' => VenueBooking::STATUS_CONFIRMED]);
    $enquiry = ($this->booking)(['status' => VenueBooking::STATUS_PENDING_QUOTE]);

    expect(fn () => $this->service->placeHostHold($this->shop, $enquiry->id))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->quoteAsHost($this->shop, $enquiry->id, 10000, 0, 5000, null))->toThrow(ValidationException::class)
        ->and($enquiry->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_QUOTE);
});

test('a host hold on free dates is payable for 48 hours and a quote for seven days', function (): void {
    $this->freezeTime();
    $enquiry = ($this->booking)(['status' => VenueBooking::STATUS_PENDING_QUOTE]);

    $held = $this->service->placeHostHold($this->shop, $enquiry->id);
    expect($held->status)->toBe(VenueBooking::STATUS_PENDING_PAYMENT)
        ->and($held->fresh()->quote_valid_until->toDateTimeString())->toBe(now()->addHours(48)->toDateTimeString());

    $quoted = $this->service->quoteAsHost($this->shop, $enquiry->id, 30000, 10000, 99999, 'Includes AV');
    expect($quoted->total_amount_pesewas)->toBe(40000)
        ->and($quoted->deposit_required_pesewas)->toBe(40000)
        ->and($quoted->fresh()->quote_valid_until->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString());
});

test('a host cannot change a paid reservation or act on another shop\'s booking', function (): void {
    $paid = ($this->booking)(['status' => VenueBooking::STATUS_CONFIRMED, 'payment_status' => VenueBooking::PAYMENT_DEPOSIT_PAID]);
    $foreignShop = Shop::factory()->create();

    expect(fn () => $this->service->placeHostHold($this->shop, $paid->id))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->rejectAsHost($this->shop, $paid->id, null))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->rejectAsHost($foreignShop, $paid->id, null))->toThrow(ModelNotFoundException::class);
});

test('declining frees the time for the next request', function (): void {
    $held = ($this->booking)(['status' => VenueBooking::STATUS_PENDING_PAYMENT, 'quote_valid_until' => now()->addDay()]);
    $blackout = app(VenueBlackouts::class);

    expect(fn () => $blackout->create($this->shop, $this->listing->id, $this->from, $this->to, 'Maintenance'))->toThrow(ValidationException::class);

    $this->service->rejectAsHost($this->shop, $held->id, 'Not available');
    $block = $blackout->create($this->shop, $this->listing->id, $this->from, $this->to, 'Maintenance');

    expect($block->status)->toBe(VenueBooking::STATUS_BLOCKED);
});

test('a blackout cannot be made on another shop\'s listing or removed from another shop', function (): void {
    $foreign = Shop::factory()->create();
    $block = app(VenueBlackouts::class)->create($this->shop, $this->listing->id, $this->from, $this->to, 'Maintenance');

    expect(fn () => app(VenueBlackouts::class)->create($foreign, $this->listing->id, $this->from, $this->to, 'x'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(VenueBlackouts::class)->remove($foreign, $block->id))->toThrow(ModelNotFoundException::class)
        ->and(VenueBooking::query()->whereKey($block->id)->exists())->toBeTrue();

    app(VenueBlackouts::class)->remove($this->shop, $block->id);
    expect(VenueBooking::query()->whereKey($block->id)->exists())->toBeFalse();
});

test('a booking that is not a blackout cannot be removed as one', function (): void {
    $confirmed = ($this->booking)(['status' => VenueBooking::STATUS_CONFIRMED]);

    expect(fn () => app(VenueBlackouts::class)->remove($this->shop, $confirmed->id))->toThrow(ModelNotFoundException::class)
        ->and($confirmed->fresh())->not->toBeNull();
});

test('the lock helpers refuse to run outside a transaction and bind a booking to its listing', function (): void {
    $locks = app(VenueCalendarLocks::class);
    $other = StoreListing::factory()->create(['shop_id' => $this->shop->id]);
    $booking = ($this->booking)();

    $connection = DB::connection('landlord');
    $level = $connection->transactionLevel();
    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }
    try {
        expect(fn () => $locks->listing($this->listing->id))->toThrow(LogicException::class);
    } finally {
        while ($connection->transactionLevel() < $level) {
            $connection->beginTransaction();
        }
    }

    expect(fn () => $locks->forListing($other->id, fn (StoreListing $listing) => $locks->booking($listing, $booking->id)))
        ->toThrow(ModelNotFoundException::class);
});

describe('a late legacy payment', function (): void {
    beforeEach(function (): void {
        $this->late = ($this->booking)([
            'status' => VenueBooking::STATUS_PENDING_PAYMENT, 'quote_valid_until' => now()->subHour(),
            'total_amount_pesewas' => 20000, 'deposit_required_pesewas' => 20000,
        ]);
        $this->pay = fn (string $reference = 'pstk_late_1') => $this->service->confirmBookingPayment($reference, [
            'amount' => 20000, 'fees' => 100, 'metadata' => ['booking_reference' => $this->late->booking_reference],
        ]);
    });

    test('still confirms when its dates remain free', function (): void {
        $booking = ($this->pay)();

        expect($booking->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)
            ->and($booking->fresh()->paystack_reference)->toBe('pstk_late_1')
            ->and(VenueBookingAction::query()->count())->toBe(0);
        Mail::assertQueued(BookingConfirmationGuest::class);
    });

    test('keeps evidence and displaces nobody when another booking took the dates', function (): void {
        $winner = ($this->booking)(['status' => VenueBooking::STATUS_CONFIRMED]);

        $booking = ($this->pay)();
        ($this->pay)();

        $action = VenueBookingAction::query()->where('booking_id', $this->late->id)->sole();
        expect($booking->status)->toBe(VenueBooking::STATUS_PENDING_PAYMENT)
            ->and($booking->paystack_reference)->toBeNull()
            ->and($winner->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)
            ->and($action->action)->toBe(VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT)
            ->and($action->decision_reason)->toBe('calendar_taken')
            ->and($action->evidence)->toEqualCanonicalizing(['provider_reference' => 'pstk_late_1', 'amount_pesewas' => 20000, 'currency' => 'GHS', 'fees_pesewas' => 100])
            ->and(LedgerTransaction::query()->where('reference', 'like', 'MKT-%')->count())->toBe(0);
        Mail::assertNothingQueued();
    });

    test('is a separate piece of evidence for each provider reference', function (): void {
        ($this->booking)(['status' => VenueBooking::STATUS_CONFIRMED]);

        ($this->pay)('pstk_a');
        ($this->pay)('pstk_b');

        expect(VenueBookingAction::query()->where('booking_id', $this->late->id)->count())->toBe(2);
    });

    test('never reopens a booking the host declined', function (): void {
        $this->late->update(['status' => VenueBooking::STATUS_REJECTED]);

        $booking = ($this->pay)();

        expect($booking->status)->toBe(VenueBooking::STATUS_REJECTED)
            ->and(VenueBookingAction::query()->where('booking_id', $this->late->id)->sole()->decision_reason)->toBe('booking_closed');
    });

    test('is idempotent once confirmed', function (): void {
        ($this->pay)();
        ($this->pay)();

        expect(LedgerTransaction::query()->where('reference', 'like', 'MKT-%')->count())->toBe(1);
    });
});
