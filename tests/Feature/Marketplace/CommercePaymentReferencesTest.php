<?php

declare(strict_types=1);

use App\Models\CommercePaymentReference;
use App\Models\LedgerTransaction;
use App\Models\StoreOrder;
use App\Models\VenueBooking;
use App\Services\Marketplace\CommercePaymentReferences;
use App\Services\Marketplace\VenueBookingService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    refreshTenantDatabases();
    $this->references = app(CommercePaymentReferences::class);
});

test('claiming a reference twice for the same order returns the one claim', function (): void {
    $order = StoreOrder::factory()->create();

    $first = $this->references->claimForOrder($order, 'pstk_one');
    $again = $this->references->claimForOrder($order, ' pstk_one ');

    expect($again->id)->toBe($first->id)
        ->and(CommercePaymentReference::query()->count())->toBe(1)
        ->and($first->fresh())->source_type->toBe(CommercePaymentReference::SOURCE_ORDER)->tenant_id->toBe($order->tenant_id);
});

test('a reference owned by one order cannot be claimed for another', function (): void {
    $this->references->claimForOrder(StoreOrder::factory()->create(), 'pstk_shared');

    expect(fn () => $this->references->claimForOrder(StoreOrder::factory()->create(), 'pstk_shared'))->toThrow(RuntimeException::class)
        ->and(CommercePaymentReference::query()->where('reference', 'pstk_shared')->count())->toBe(1);
});

test('the same reference text under a different provider account is a different reference', function (): void {
    $this->references->claimForOrder(StoreOrder::factory()->create(), 'pstk_same_text');
    $other = StoreOrder::factory()->create(['account_fingerprint' => hash('sha256', 'another-account')]);

    expect($this->references->claimForOrder($other, 'pstk_same_text')->order_id)->toBe($other->id);
});

test('an order cannot take a reference already claimed against a legacy booking', function (): void {
    $legacy = VenueBooking::factory()->create();
    $this->references->claimForLegacyBooking($legacy, 'paystack', hash('sha256', 'test-account'), 'pstk_legacy_claim');

    expect(fn () => $this->references->claimForOrder(StoreOrder::factory()->create(), 'pstk_legacy_claim'))->toThrow(RuntimeException::class);
});

test('money the older venue path already took or posted can never be claimed as a new sale', function (): void {
    Mail::fake();
    $booking = VenueBooking::factory()->create([
        'status' => VenueBooking::STATUS_PENDING_PAYMENT, 'quote_valid_until' => now()->addHour(),
        'total_amount_pesewas' => 20000, 'deposit_required_pesewas' => 20000,
        'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHours(2),
    ]);
    app(VenueBookingService::class)->confirmBookingPayment('pstk_old_path', ['amount' => 20000, 'fees' => 0, 'metadata' => ['booking_reference' => $booking->booking_reference]]);

    expect(LedgerTransaction::query()->where('reference', "MKT-{$booking->booking_reference}-pstk_old_path")->exists())->toBeTrue()
        ->and($this->references->postedByLegacyVenuePath('pstk_old_path'))->toBeTrue()
        ->and($this->references->postedByLegacyVenuePath('pstk_never_seen'))->toBeFalse()
        ->and(fn () => $this->references->claimForOrder(StoreOrder::factory()->create(), 'pstk_old_path'))->toThrow(RuntimeException::class);
});

test('a reference containing pattern characters cannot match unrelated ledger history', function (): void {
    expect($this->references->postedByLegacyVenuePath('%'))->toBeFalse()
        ->and($this->references->postedByLegacyVenuePath('_'))->toBeFalse();
});

test('the first ledger link is recorded and repeated safely', function (): void {
    $claim = $this->references->claimForOrder(StoreOrder::factory()->create(), 'pstk_posted');

    $this->references->linkLedger($claim, 'COMMERCE-1');
    $this->references->linkLedger($claim->fresh(), 'COMMERCE-1');

    expect($claim->fresh()->ledger_reference)->toBe('COMMERCE-1')
        ->and(fn () => $this->references->linkLedger($claim->fresh(), 'COMMERCE-2'))->toThrow(Illuminate\Database\QueryException::class);
});

test('a blank reference is refused', function (): void {
    expect(fn () => $this->references->claimForOrder(StoreOrder::factory()->create(), '  '))->toThrow(RuntimeException::class);
});
