<?php

declare(strict_types=1);

use App\Models\CommercePaymentReference;
use App\Models\Shop;
use App\Models\StoreCommercialAgreement;
use App\Models\StoreListing;
use App\Models\StoreOrder;
use App\Models\StoreOrderLine;
use App\Models\StoreVariant;
use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialDecision;
use App\Models\VenueFinancialQuote;
use App\Models\VenuePaymentResolution;
use App\Models\VenueSettlementReserve;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    refreshTenantDatabases();
});

/**
 * Run a statement that must fail inside a savepoint so the test transaction survives it.
 */
function rejectedByDatabase(Closure $statement): void
{
    expect(fn () => DB::connection('landlord')->transaction($statement))->toThrow(QueryException::class);
}

describe('commercial agreements by scope', function (): void {
    test('existing agreements are purchase agreements and a reservation agreement is independent', function (): void {
        $purchase = StoreCommercialAgreement::factory()->create();
        $reservation = StoreCommercialAgreement::factory()->reservation()->create([
            'shop_id' => $purchase->shop_id, 'tenant_id' => $purchase->tenant_id, 'version' => 2, 'percentage' => '3.00',
        ]);

        expect($purchase->fresh()->offering_scope)->toBe('purchase')
            ->and($reservation->fresh()->offering_scope)->toBe('reservation')
            ->and(StoreCommercialAgreement::query()->where('shop_id', $purchase->shop_id)->where('offering_scope', 'purchase')->orderByDesc('version')->value('id'))->toBe($purchase->id);
    });

    test('a shop never reuses a version number across scopes', function (): void {
        $purchase = StoreCommercialAgreement::factory()->create();

        rejectedByDatabase(fn () => StoreCommercialAgreement::factory()->reservation()->create(['shop_id' => $purchase->shop_id, 'tenant_id' => $purchase->tenant_id, 'version' => $purchase->version]));
    });

    test('only the two known scopes exist and an agreement stays immutable', function (): void {
        rejectedByDatabase(fn () => StoreCommercialAgreement::factory()->create(['offering_scope' => 'subscription']));

        $agreement = StoreCommercialAgreement::factory()->reservation()->create();
        rejectedByDatabase(fn () => StoreCommercialAgreement::query()->whereKey($agreement->id)->update(['offering_scope' => 'purchase']));
    });

    test('a purchase order cannot rest on a reservation agreement and the reverse', function (): void {
        $reservation = StoreCommercialAgreement::factory()->reservation()->create();
        $purchase = StoreCommercialAgreement::factory()->create(['shop_id' => $reservation->shop_id, 'tenant_id' => $reservation->tenant_id, 'version' => 2]);

        rejectedByDatabase(fn () => StoreOrder::factory()->create(['agreement_id' => $reservation->id, 'offering_type' => 'purchase']));
        rejectedByDatabase(fn () => StoreOrder::factory()->create(['agreement_id' => $purchase->id, 'offering_type' => 'reservation']));
        expect(StoreOrder::factory()->create(['agreement_id' => $purchase->id])->offering_type)->toBe('purchase')
            ->and(StoreOrder::factory()->reservation()->create(['agreement_id' => $reservation->id])->offering_type)->toBe('reservation');
    });
});

describe('reservation order lines', function (): void {
    test('a reservation line names the booked venue and carries no merchandise variant or stock', function (): void {
        $booking = VenueBooking::factory()->create();
        $line = StoreOrderLine::factory()->reservation($booking)->create();

        expect($line->fresh()->variant_id)->toBeNull()
            ->and($line->fresh()->booking_id)->toBe($booking->id)
            ->and($line->fresh()->listing_id)->toBe($booking->store_listing_id)
            ->and($line->fresh()->offering_type)->toBe('reservation')
            ->and(DB::connection('landlord')->table('store_stock_movements')->count())->toBe(0);
    });

    test('a reservation line needs its booking, a single quantity and the booking own listing', function (): void {
        $booking = VenueBooking::factory()->create();
        $factory = fn (array $overrides) => fn () => StoreOrderLine::factory()->reservation($booking)->create($overrides);

        rejectedByDatabase($factory(['booking_id' => null]));
        rejectedByDatabase($factory(['quantity' => 2, 'line_amount_pesewas' => 6000]));
        rejectedByDatabase($factory(['listing_id' => StoreListing::factory()->create(['shop_id' => $booking->shop_id])->id]));
        rejectedByDatabase($factory(['variant_id' => StoreVariant::factory()->create()->id]));
    });

    test('a purchase line still needs a genuine variant and cannot name a booking', function (): void {
        $variant = StoreVariant::factory()->create();

        $order = StoreOrder::factory()->create(['agreement_id' => StoreCommercialAgreement::factory()->create(['shop_id' => $variant->shop_id, 'tenant_id' => $variant->shop->tenant_id])->id]);

        rejectedByDatabase(fn () => StoreOrderLine::factory()->create(['order_id' => $order->id, 'variant_id' => null, 'shop_id' => $variant->shop_id, 'listing_id' => $variant->listing_id]));
        rejectedByDatabase(fn () => StoreOrderLine::factory()->create(['booking_id' => VenueBooking::factory()->create()->id]));
        expect(StoreOrderLine::factory()->create()->fresh()->offering_type)->toBe('purchase');
    });

    test('a reservation line is as immutable as any other order line', function (): void {
        $line = StoreOrderLine::factory()->reservation()->create();

        rejectedByDatabase(fn () => StoreOrderLine::query()->whereKey($line->id)->update(['unit_price_pesewas' => 1]));
        rejectedByDatabase(fn () => StoreOrderLine::query()->whereKey($line->id)->delete());
    });
});

describe('financial quotes', function (): void {
    test('a quote versions per booking and keeps exact dates, amounts, fee allocation and terms', function (): void {
        $first = VenueFinancialQuote::factory()->create();
        $second = VenueFinancialQuote::factory()->create(['booking_id' => $first->booking_id, 'shop_id' => $first->shop_id, 'tenant_id' => $first->tenant_id, 'agreement_id' => $first->agreement_id, 'version' => 2]);

        expect($first->fresh()->version)->toBe(1)
            ->and($second->fresh()->version)->toBe(2)
            ->and($first->fresh())
            ->rental_pesewas->toBe(100000)->extras_pesewas->toBe(5000)->security_pesewas->toBe(20000)
            ->initial_pesewas->toBe(30000)->balance_pesewas->toBe(75000)
            ->contract_fee_pesewas->toBe(3150)->initial_fee_pesewas->toBe(900)->balance_fee_pesewas->toBe(2250)
            ->currency->toBe('GHS')
            ->and($first->fresh()->booking->id)->toBe($first->booking_id);
        rejectedByDatabase(fn () => VenueFinancialQuote::factory()->create(['booking_id' => $first->booking_id, 'shop_id' => $first->shop_id, 'tenant_id' => $first->tenant_id, 'agreement_id' => $first->agreement_id, 'version' => 2]));
    });

    test('a repeated operation key cannot issue a second quote for the shop', function (): void {
        $first = VenueFinancialQuote::factory()->create();

        rejectedByDatabase(fn () => VenueFinancialQuote::factory()->create(['shop_id' => $first->shop_id, 'tenant_id' => $first->tenant_id, 'operation_key' => $first->operation_key]));
    });

    test('quote content cannot be edited or deleted, even in bulk', function (string $field, mixed $value): void {
        $quote = VenueFinancialQuote::factory()->create();

        rejectedByDatabase(fn () => VenueFinancialQuote::query()->whereKey($quote->id)->update([$field => $value]));
        rejectedByDatabase(fn () => VenueFinancialQuote::query()->whereKey($quote->id)->delete());
    })->with([['rental_pesewas', 1], ['security_pesewas', 0], ['initial_fee_pesewas', 0], ['expires_at', '2031-01-01 00:00:00'], ['cancellation_terms', 'Changed'], ['agreement_id', null]]);

    test('only internally consistent money is accepted', function (array $overrides): void {
        rejectedByDatabase(fn () => VenueFinancialQuote::factory()->create($overrides));
    })->with([
        'instalments differ from the rental total' => [['initial_pesewas' => 30000, 'balance_pesewas' => 70000]],
        'fee allocation differs from the contract fee' => [['initial_fee_pesewas' => 945]],
        'fee exceeds the charge it is taken from' => [['contract_fee_pesewas' => 200000, 'initial_fee_pesewas' => 100000, 'balance_fee_pesewas' => 100000]],
        'negative security' => [['security_pesewas' => -1]],
        'balance without a due date' => [['balance_due_at' => null]],
        'security without a due date' => [['security_due_at' => null]],
        'balance due after the event starts' => [['balance_due_at' => '2031-06-02 00:00:00']],
        'initial payment window ends after the hold' => [['expires_at' => '2031-05-01 00:00:00', 'hold_expires_at' => '2031-04-01 00:00:00']],
        'event ends before it starts' => [['event_ends_at' => '2031-06-01 08:00:00']],
        'not Ghana cedis' => [['currency' => 'USD']],
    ]);

    test('a deposit that equals the rental leaves no balance and needs no balance date', function (): void {
        $quote = VenueFinancialQuote::factory()->create([
            'initial_pesewas' => 105000, 'balance_pesewas' => 0, 'balance_due_at' => null,
            'contract_fee_pesewas' => 3150, 'initial_fee_pesewas' => 3150, 'balance_fee_pesewas' => 0,
        ]);

        expect($quote->fresh()->balance_pesewas)->toBe(0)->and($quote->fresh()->balance_due_at)->toBeNull();
    });

    test('a quote must belong to the same tenant and shop as its booking and use a reservation agreement', function (): void {
        $booking = VenueBooking::factory()->create();
        $foreign = Shop::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        rejectedByDatabase(fn () => VenueFinancialQuote::factory()->create(['booking_id' => $booking->id, 'shop_id' => $foreign->id, 'tenant_id' => $foreign->tenant_id]));
        rejectedByDatabase(fn () => VenueFinancialQuote::factory()->create([
            'booking_id' => $booking->id, 'shop_id' => $booking->shop_id, 'tenant_id' => $booking->tenant_id,
            'agreement_id' => StoreCommercialAgreement::factory()->create(['shop_id' => $booking->shop_id, 'tenant_id' => $booking->tenant_id])->id,
        ]));
    });

    test('a quote cites the host approval hold it was issued under', function (): void {
        $quote = VenueFinancialQuote::factory()->create();

        expect($quote->fresh()->holdAction->action)->toBe(VenueBookingAction::ACTION_APPROVED);
        rejectedByDatabase(fn () => VenueFinancialQuote::factory()->create(['hold_action_id' => (string) Str::uuid()]));
    });
});

describe('financial decisions', function (): void {
    test('acceptance, withdrawal and completion are separate append-only records', function (): void {
        $quote = VenueFinancialQuote::factory()->create();
        $accepted = VenueFinancialDecision::factory()->accepted($quote)->create();

        expect($accepted->fresh()->decision)->toBe('accepted')
            ->and($accepted->fresh()->buyer_email)->toBe('buyer@planner.test')
            ->and($quote->fresh()->rental_pesewas)->toBe(100000);
        rejectedByDatabase(fn () => VenueFinancialDecision::query()->whereKey($accepted->id)->update(['reason' => 'edited']));
        rejectedByDatabase(fn () => VenueFinancialDecision::query()->whereKey($accepted->id)->delete());
    });

    test('a booking has one accepted contract and a quote one outcome', function (): void {
        $first = VenueFinancialQuote::factory()->create();
        $second = VenueFinancialQuote::factory()->create(['booking_id' => $first->booking_id, 'shop_id' => $first->shop_id, 'tenant_id' => $first->tenant_id, 'agreement_id' => $first->agreement_id, 'version' => 2]);
        VenueFinancialDecision::factory()->accepted($first)->create();

        rejectedByDatabase(fn () => VenueFinancialDecision::factory()->accepted($second)->create());
        rejectedByDatabase(fn () => VenueFinancialDecision::factory()->withdrawn($first)->create());
        expect(VenueFinancialDecision::factory()->withdrawn($second)->create()->decision)->toBe('withdrawn');
    });

    test('each decision type needs its own actor and evidence', function (): void {
        $quote = VenueFinancialQuote::factory()->create();

        rejectedByDatabase(fn () => VenueFinancialDecision::factory()->accepted($quote)->create(['buyer_email' => null]));
        rejectedByDatabase(fn () => VenueFinancialDecision::factory()->accepted($quote)->create(['terms_hash' => null]));
        rejectedByDatabase(fn () => VenueFinancialDecision::factory()->accepted($quote)->create(['actor_type' => 'host']));
        rejectedByDatabase(fn () => VenueFinancialDecision::factory()->withdrawn($quote)->create(['reason' => null]));
        rejectedByDatabase(fn () => VenueFinancialDecision::factory()->create(['quote_id' => $quote->id, 'booking_id' => $quote->booking_id, 'shop_id' => $quote->shop_id, 'tenant_id' => $quote->tenant_id, 'decision' => 'refunded']));
    });

    test('an operation key can be used once per quote and a decision must match its quote owner', function (): void {
        $quote = VenueFinancialQuote::factory()->create();
        $other = VenueFinancialQuote::factory()->create();
        $key = (string) Str::uuid();
        VenueFinancialDecision::factory()->completed($quote)->create(['operation_key' => $key]);

        rejectedByDatabase(fn () => VenueFinancialDecision::factory()->completed($quote)->create(['operation_key' => $key]));
        rejectedByDatabase(fn () => VenueFinancialDecision::factory()->accepted($quote)->create(['booking_id' => $other->booking_id, 'shop_id' => $other->shop_id, 'tenant_id' => $other->tenant_id]));
    });
});

describe('booking charges', function (): void {
    test('each purpose has one charge per quote, linked to one reservation order', function (): void {
        $charge = VenueBookingCharge::factory()->create();

        expect($charge->fresh()->purpose)->toBe('rental_initial')
            ->and($charge->fresh()->order->offering_type)->toBe('reservation')
            ->and($charge->fresh()->order->principal_pesewas)->toBe($charge->amount_pesewas)
            ->and($charge->fresh()->quote->id)->toBe($charge->quote_id);
        rejectedByDatabase(fn () => VenueBookingCharge::factory()->forQuote($charge->quote)->create());
        rejectedByDatabase(fn () => VenueBookingCharge::factory()->create(['order_id' => $charge->order_id, 'quote_id' => $charge->quote_id, 'booking_id' => $charge->booking_id, 'shop_id' => $charge->shop_id, 'tenant_id' => $charge->tenant_id, 'purpose' => 'security']));
    });

    test('rental balance and security may sit beside the initial charge', function (): void {
        $initial = VenueBookingCharge::factory()->create();

        foreach (['rental_balance', 'security'] as $purpose) {
            expect(VenueBookingCharge::factory()->forQuote($initial->quote, $purpose)->create()->purpose)->toBe($purpose);
        }
        expect(VenueBookingCharge::query()->where('quote_id', $initial->quote_id)->count())->toBe(3);
    });

    test('a charge is a full amount that matches its order and quote', function (): void {
        $quote = VenueFinancialQuote::factory()->create();

        rejectedByDatabase(fn () => VenueBookingCharge::factory()->forQuote($quote)->create(['amount_pesewas' => 100]));
        rejectedByDatabase(fn () => VenueBookingCharge::factory()->forQuote($quote)->create(['fee_pesewas' => 1]));
        rejectedByDatabase(fn () => VenueBookingCharge::factory()->forQuote($quote)->create(['amount_pesewas' => 0, 'fee_pesewas' => 0]));
        rejectedByDatabase(fn () => VenueBookingCharge::factory()->forQuote($quote)->create(['purpose' => 'deposit']));
    });

    test('a charge cannot rest on a purchase order or another tenant order', function (): void {
        $quote = VenueFinancialQuote::factory()->create();
        $purchase = StoreOrder::factory()->create();
        $foreign = StoreOrder::factory()->reservation()->create();

        foreach ([$purchase, $foreign] as $order) {
            rejectedByDatabase(fn () => VenueBookingCharge::factory()->forQuote($quote)->create(['order_id' => $order->id]));
        }
    });

    test('only a legacy review charge exists without a quote', function (): void {
        $legacy = VenueBookingCharge::factory()->legacyReview()->create();

        expect($legacy->fresh()->quote_id)->toBeNull()->and($legacy->fresh()->purpose)->toBe('legacy_review');
        rejectedByDatabase(fn () => VenueBookingCharge::factory()->legacyReview()->create(['purpose' => 'rental_initial']));
        rejectedByDatabase(fn () => VenueBookingCharge::factory()->forQuote(VenueFinancialQuote::factory()->create())->create(['purpose' => 'legacy_review']));
    });

    test('a charge is retained and immutable', function (): void {
        $charge = VenueBookingCharge::factory()->create();

        rejectedByDatabase(fn () => VenueBookingCharge::query()->whereKey($charge->id)->update(['amount_pesewas' => 1]));
        rejectedByDatabase(fn () => VenueBookingCharge::query()->whereKey($charge->id)->delete());
    });
});

describe('provider reference ownership', function (): void {
    test('a provider reference belongs to exactly one source', function (): void {
        $claim = CommercePaymentReference::factory()->create();

        expect($claim->fresh()->order->id)->toBe($claim->order_id);
        rejectedByDatabase(fn () => CommercePaymentReference::factory()->create(['provider' => $claim->provider, 'account_fingerprint' => $claim->account_fingerprint, 'reference' => $claim->reference]));
        expect(CommercePaymentReference::factory()->create(['provider' => $claim->provider, 'account_fingerprint' => hash('sha256', 'other-account'), 'reference' => $claim->reference])->exists)->toBeTrue();
    });

    test('a legacy booking payment is claimed against its booking and never an order', function (): void {
        $legacy = CommercePaymentReference::factory()->legacyBooking()->create();

        expect($legacy->fresh()->booking->id)->toBe($legacy->booking_id)->and($legacy->fresh()->order_id)->toBeNull();
        rejectedByDatabase(fn () => CommercePaymentReference::factory()->legacyBooking()->create(['order_id' => StoreOrder::factory()->create()->id]));
        rejectedByDatabase(fn () => CommercePaymentReference::factory()->create(['order_id' => null]));
        rejectedByDatabase(fn () => CommercePaymentReference::factory()->create(['source_type' => 'screenshot']));
    });

    test('the ledger link is set once and nothing else about a claim can change', function (): void {
        $claim = CommercePaymentReference::factory()->create();

        CommercePaymentReference::query()->whereKey($claim->id)->update(['ledger_reference' => 'COMMERCE-1']);
        expect($claim->fresh()->ledger_reference)->toBe('COMMERCE-1');
        rejectedByDatabase(fn () => CommercePaymentReference::query()->whereKey($claim->id)->update(['ledger_reference' => 'COMMERCE-2']));
        rejectedByDatabase(fn () => CommercePaymentReference::query()->whereKey($claim->id)->update(['reference' => 'pstk_other']));
        rejectedByDatabase(fn () => CommercePaymentReference::query()->whereKey($claim->id)->delete());
    });
});

describe('payment resolutions', function (): void {
    test('a resolution is an append-only decision on one piece of conflict evidence', function (): void {
        $resolution = VenuePaymentResolution::factory()->create();

        expect($resolution->fresh()->action->action)->toBe(VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT)
            ->and($resolution->fresh()->step)->toBe('retained');
        rejectedByDatabase(fn () => VenuePaymentResolution::query()->whereKey($resolution->id)->update(['reason' => 'edited']));
        rejectedByDatabase(fn () => VenuePaymentResolution::query()->whereKey($resolution->id)->delete());
    });

    test('evidence has one terminal outcome but may be retained, then verified, then refunded', function (): void {
        $first = VenuePaymentResolution::factory()->create();
        $evidence = $first->action;
        $next = fn (string $step) => fn () => VenuePaymentResolution::factory()->forAction($evidence)->create(['step' => $step]);

        $next('verified')();
        $next('refunded')();
        rejectedByDatabase($next('confirmed'));
        rejectedByDatabase($next('refunded'));
        expect(VenuePaymentResolution::query()->where('action_id', $evidence->id)->count())->toBe(3);
    });

    test('a resolution must match its evidence owner and a known step', function (): void {
        $evidence = VenueBookingAction::factory()->create(['action' => VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT, 'actor_type' => 'system']);
        $foreign = Shop::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        rejectedByDatabase(fn () => VenuePaymentResolution::factory()->forAction($evidence)->create(['shop_id' => $foreign->id, 'tenant_id' => $foreign->tenant_id]));
        rejectedByDatabase(fn () => VenuePaymentResolution::factory()->forAction($evidence)->create(['step' => 'marked_paid']));
        rejectedByDatabase(fn () => VenuePaymentResolution::factory()->forAction(VenueBookingAction::factory()->create())->create(['step' => 'retained']));
    });
});

describe('settlement reserves', function (): void {
    test('a reserve holds one charge of seller earnings until a completion decision releases it once', function (): void {
        $reserve = VenueSettlementReserve::factory()->create();

        expect($reserve->fresh()->state)->toBe('held')->and($reserve->fresh()->released_at)->toBeNull();
        $decision = VenueFinancialDecision::factory()->completed($reserve->charge->quote)->create();
        $reserve->update(['state' => 'released', 'released_at' => now(), 'release_decision_id' => $decision->id]);

        expect($reserve->fresh()->state)->toBe('released');
        rejectedByDatabase(fn () => VenueSettlementReserve::query()->whereKey($reserve->id)->update(['state' => 'held', 'released_at' => null, 'release_decision_id' => null]));
        rejectedByDatabase(fn () => VenueSettlementReserve::query()->whereKey($reserve->id)->update(['reserved_pesewas' => 1]));
        rejectedByDatabase(fn () => VenueSettlementReserve::query()->whereKey($reserve->id)->delete());
    });

    test('a charge has one reserve and a release needs its decision', function (): void {
        $reserve = VenueSettlementReserve::factory()->create();

        rejectedByDatabase(fn () => VenueSettlementReserve::factory()->forCharge($reserve->charge)->create());
        rejectedByDatabase(fn () => VenueSettlementReserve::query()->whereKey($reserve->id)->update(['state' => 'released', 'released_at' => now()]));
        rejectedByDatabase(fn () => VenueSettlementReserve::factory()->create(['reserved_pesewas' => -1]));
    });
});

describe('existing purchases and rollback', function (): void {
    test('purchase and made-to-order shapes are unchanged', function (): void {
        $line = StoreOrderLine::factory()->create();

        expect($line->order->offering_type)->toBe('purchase')
            ->and($line->order->agreement->offering_scope)->toBe('purchase')
            ->and($line->variant_id)->not->toBeNull()
            ->and($line->booking_id)->toBeNull();
    });

    test('the migrations refuse to roll back once venue contract records exist', function (): void {
        VenueFinancialQuote::factory()->create();
        $migrations = [
            'database/migrations/landlord/2026_10_30_000200_create_venue_financial_contract_tables.php',
            'database/migrations/landlord/2026_10_30_000100_add_reservation_scope_to_commerce.php',
        ];

        foreach ($migrations as $path) {
            expect(fn () => (require base_path($path))->down())->toThrow(RuntimeException::class);
        }
    });
});
