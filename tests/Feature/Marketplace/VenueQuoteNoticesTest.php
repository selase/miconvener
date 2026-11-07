<?php

declare(strict_types=1);

use App\Mail\Marketplace\VenueQuoteNoticeMail;
use App\Models\Feature;
use App\Models\LedgerTransaction;
use App\Models\Permission;
use App\Models\Shop;
use App\Models\StoreCommercialAgreement;
use App\Models\StoreListing;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialQuote;
use App\Models\VenueQuoteNotice;
use App\Services\Giving\GivingGateways;
use App\Services\Marketplace\StorePaymentConfirmer;
use App\Services\Marketplace\StorePaymentEvidence;
use App\Services\Marketplace\StorePayments;
use App\Services\Marketplace\VenueCommercialQuotes;
use App\Services\Marketplace\VenuePaymentRollout;
use App\Services\Marketplace\VenueQuoteNotices;
use App\Services\Marketplace\VenueRequestRollout;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    refreshTenantDatabases();
    config(['services.settlement.paystack.secret_key' => 'sk_test_venue_payments']);
    Http::preventStrayRequests();
    Http::fake([
        '*api.paystack.co/customer*' => Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_VENUE', 'email' => 'buyer@planner.test']]),
        '*api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/venue-test']]),
    ]);
    $this->tenant = Tenant::factory()->create(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
    $this->shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
    $this->listing = StoreListing::factory()->create(['shop_id' => $this->shop->id, 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED, 'is_bookable' => true]);
    StoreCommercialAgreement::factory()->reservation()->create([
        'shop_id' => $this->shop->id, 'tenant_id' => $this->tenant->id, 'percentage' => '3.00', 'cap_pesewas' => null,
        'account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity()),
    ]);
    foreach ([VenueRequestRollout::FEATURE, VenuePaymentRollout::FEATURE] as $slug) {
        Feature::query()->firstOrCreate(['slug' => $slug], ['name' => $slug, 'type' => 'boolean', 'description' => 'test']);
        $this->tenant->features()->updateOrCreate(['feature_key' => $slug], ['enabled' => true, 'meta' => ['source' => 'admin_override']]);
        Cache::forget('tenant_'.$this->tenant->id.'_feature_'.$slug);
    }
    Permission::query()->firstOrCreate(['name' => 'manage venue', 'guard_name' => 'web'], ['category' => 'venue']);
    $this->host = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->host->givePermissionTo('manage venue');
    app(TenantContext::class)->setTenant($this->tenant);

    $this->eventStart = CarbonImmutable::now()->addDays(30)->setTime(9, 0);
    $this->heldBooking = function (array $overrides = []): VenueBooking {
        $booking = VenueBooking::factory()->create($overrides + [
            'tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id,
            'status' => VenueBooking::STATUS_APPROVED_HOLD, 'approval_flow_version' => 1, 'hold_expires_at' => CarbonImmutable::now()->addDays(3)->setMicroseconds(0),
            'starts_at' => $this->eventStart, 'ends_at' => $this->eventStart->addHours(8), 'planner_email' => 'buyer@planner.test',
        ]);
        VenueBookingAction::factory()->create(['booking_id' => $booking->id, 'shop_id' => $booking->shop_id, 'tenant_id' => $booking->tenant_id, 'action' => VenueBookingAction::ACTION_APPROVED, 'hold_expires_at' => $booking->hold_expires_at]);

        return $booking;
    };
    $this->booking = ($this->heldBooking)();
    $this->session = fn (string $email = 'buyer@planner.test'): Store => tap(new Store('venue-pay', new ArraySessionHandler(120)), fn (Store $s) => $s->put(proofFor($email)));
    $this->input = fn (array $overrides = []): array => $overrides + [
        'rental_pesewas' => 100000, 'extras_pesewas' => 5000, 'security_pesewas' => 20000,
        'payment_plan' => 'deposit', 'deposit_pesewas' => 30000,
        'balance_due_at' => $this->eventStart->subDays(7)->toIso8601String(), 'security_due_at' => $this->eventStart->subDays(2)->toIso8601String(),
        'cancellation_terms' => 'Full refund of any instalment up to 14 days before the event.', 'support_contact' => 'bookings@host.example',
    ];
    $this->accepted = function (array $overrides = [], ?VenueBooking $booking = null): VenueFinancialQuote {
        $quotes = app(VenueCommercialQuotes::class);
        $quote = $quotes->issue($booking ?? $this->booking, $this->host, ($this->input)($overrides), (string) Str::uuid());
        $quotes->accept($quote, ($this->session)(), $quote->terms_hash, (string) Str::uuid());

        return $quote;
    };
    $this->attemptFor = function (VenueFinancialQuote $quote, string $purpose = VenueBookingCharge::RENTAL_INITIAL): StorePaymentAttempt {
        $charge = VenueBookingCharge::query()->where('quote_id', $quote->id)->where('purpose', $purpose)->firstOrFail();

        return app(StorePayments::class)->start($charge->order, ($this->session)(), (string) Str::uuid(), $quote->terms_hash);
    };
    $this->evidence = fn (StorePaymentAttempt $attempt, array $changes = []): array => array_replace([
        'status' => 'success', 'provider' => 'paystack', 'reference' => $attempt->reference, 'reference_reported' => true,
        'account_fingerprint' => $attempt->account_fingerprint, 'amount' => $attempt->amount_pesewas, 'currency' => 'GHS', 'fees' => 150,
        'paid_at' => now()->toIso8601String(), 'transaction_id' => 4242,
        'metadata' => ['source' => 'miconvener_venue', 'venue_charge_id' => VenueBookingCharge::query()->where('order_id', $attempt->order_id)->value('id'),
            'store_order_id' => $attempt->order_id, 'store_attempt_id' => $attempt->id, 'seller_id' => $attempt->tenant_id],
    ], $changes);
    $this->capture = function (StorePaymentAttempt $attempt, array $changes = []): StorePaymentObservation {
        $capture = app(StorePaymentEvidence::class)->record($attempt, ($this->evidence)($attempt, $changes));
        app(StorePaymentConfirmer::class)->confirm($capture);

        return $capture->fresh();
    };
    $this->sales = fn (): int => LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_sale')->count();
});

beforeEach(function (): void {
    Mail::fake();
    $this->quotes = app(VenueCommercialQuotes::class);
    $this->mailDown = function (): void {
        app()->forgetInstance('mail.manager');
        Illuminate\Support\Facades\Facade::clearResolvedInstance('mail.manager');
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
        Mail::extend('failing', fn () => new class extends Symfony\Component\Mailer\Transport\AbstractTransport
        {
            public function __toString(): string
            {
                return 'failing';
            }

            protected function doSend(Symfony\Component\Mailer\SentMessage $message): void
            {
                throw new RuntimeException('SMTP down');
            }
        });
    };
    $this->issueQuote = fn () => $this->quotes->issue($this->booking, $this->host, ($this->input)(), (string) Str::uuid());
});

describe('buyer quote emails', function (): void {
    test('are recorded with the quote and sent once, without the commission', function (): void {
        $quote = ($this->issueQuote)();

        $notice = VenueQuoteNotice::query()->sole();
        expect($notice->kind)->toBe('issued')->and($notice->state)->toBe('sent')->and($notice->attempts)->toBe(1)->and($notice->quote_id)->toBe($quote->id);
        Mail::assertSent(VenueQuoteNoticeMail::class, function (VenueQuoteNoticeMail $mail) use ($quote): bool {
            $html = $mail->render();

            return $mail->hasTo('buyer@planner.test') && str_contains($html, 'GHS 1,050.00') && ! str_contains(mb_strtolower($html), 'commission')
                && str_contains($html, $quote->cancellation_terms) && str_contains($html, route('marketplace.bookings.show', $this->booking->booking_reference));
        });
        Mail::assertSentCount(1);
    });

    test('a replacement quote sends its own email and a withdrawal sends one with the reason', function (): void {
        ($this->issueQuote)();
        $second = ($this->issueQuote)();
        $this->quotes->withdraw($second, $this->host, 'Dates changed again.', (string) Str::uuid());

        expect(VenueQuoteNotice::query()->orderBy('created_at')->get()->map(fn ($n) => $n->kind)->all())->toBe(['issued', 'issued', 'withdrawn']);
        Mail::assertSent(VenueQuoteNoticeMail::class, fn (VenueQuoteNoticeMail $mail): bool => str_contains($mail->render(), 'Dates changed again.'));
        Mail::assertSentCount(3);
    });

    test('a retried request or a second delivery never sends twice', function (): void {
        $key = (string) Str::uuid();
        $first = $this->quotes->issue($this->booking, $this->host, ($this->input)(), $key);
        $again = $this->quotes->issue($this->booking, $this->host, ($this->input)(), $key);
        app(VenueQuoteNotices::class)->deliver(VenueQuoteNotice::query()->sole()->id);

        expect($again->id)->toBe($first->id)->and(VenueQuoteNotice::query()->count())->toBe(1);
        Mail::assertSentCount(1);
    });

    test('a failed send is kept, retried after a delay and finally sent', function (): void {
        ($this->mailDown)();
        ($this->issueQuote)();

        $notice = VenueQuoteNotice::query()->sole();
        expect($notice->state)->toBe('retry')->and($notice->attempts)->toBe(1)->and($notice->error)->toBe('SMTP down')->and($notice->next_attempt_at->isFuture())->toBeTrue();
        expect(app(VenueQuoteNotices::class)->retryDue())->toBe(0);

        Mail::fake();
        $this->travel(6)->minutes();
        expect(app(VenueQuoteNotices::class)->retryDue())->toBe(1);

        $notice->refresh();
        expect($notice->state)->toBe('sent')->and($notice->attempts)->toBe(2)->and($notice->error)->toBeNull();
    });

    test('stops after the last attempt and shows the host that the buyer was not emailed', function (): void {
        ($this->mailDown)();
        ($this->issueQuote)();
        foreach ([6, 40, 200] as $minutes) {
            $this->travel($minutes)->minutes();
            app(VenueQuoteNotices::class)->retryDue();
        }

        $notice = VenueQuoteNotice::query()->sole();
        expect($notice->state)->toBe('failed')->and($notice->attempts)->toBe(VenueQuoteNotice::MAX_ATTEMPTS)->and($notice->completed_at)->not->toBeNull();
        $payload = app(App\Services\Marketplace\VenueFinancialJourney::class)->forHost($this->booking->fresh());
        expect($payload['notices'][0])->toMatchArray(['kind' => 'issued', 'state' => 'failed']);
        expect(app(App\Services\Marketplace\VenueFinancialJourney::class)->forBuyer($this->booking->fresh()))->not->toHaveKey('notices');
    });

    test('recovers a send that never reported back and runs from the scheduled command', function (): void {
        $quote = ($this->issueQuote)();
        $notice = VenueQuoteNotice::query()->sole();
        $notice->update(['state' => 'sending', 'claimed_at' => now()->subMinutes(20)]);
        Mail::fake();

        $this->artisan('venue:deliver-quote-notices')->assertSuccessful();

        expect($notice->fresh()->state)->toBe('sent')->and($notice->fresh()->attempts)->toBe(2);
        Mail::assertSentCount(1);
    });
});

it('a queue submission failure does not fail an already committed quote', function (): void {
    Illuminate\Support\Facades\Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('queue unavailable'));
    $error = null;
    try {
        ($this->issueQuote)();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    expect(VenueFinancialQuote::query()->count())->toBe(1)
        ->and(VenueQuoteNotice::query()->sole()->state)->toBe('queued')
        ->and($error)->toBeNull();
});

it('the scheduled sweep recovers a queued notice whose job was never submitted', function (): void {
    Illuminate\Support\Facades\Bus::fake();
    ($this->issueQuote)();
    expect(VenueQuoteNotice::query()->sole()->state)->toBe('queued');
    expect(app(VenueQuoteNotices::class)->retryDue())->toBe(1);
});
