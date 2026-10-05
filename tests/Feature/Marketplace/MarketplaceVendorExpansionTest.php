<?php

declare(strict_types=1);

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\MarketplaceQuote;
use App\Models\MarketplaceReview;
use App\Models\Role;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionsSeeder::class);
});

test('attendees and planners can submit an itemized RFQ on vendor and equipment listings', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 'sound-pros',
        'name' => 'Sound Pros Ghana',
    ]);

    $shop = Shop::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Sound Pros Ghana',
        'slug' => 'sound-pros-ghana',
        'email' => 'soundpros@example.com',
        'vendor_categories' => [StoreListing::CATEGORY_PA_SOUND, StoreListing::CATEGORY_GENERATORS],
        'is_active' => true,
    ]);

    $listing = StoreListing::factory()->create([
        'shop_id' => $shop->id,
        'title' => 'Concert Line Array PA Sound System',
        'slug' => 'concert-line-array-pa-sound-system',
        'listing_kind' => StoreListing::KIND_RENTAL,
        'category' => StoreListing::CATEGORY_PA_SOUND,
        'status' => StoreListing::STATUS_PUBLISHED,
        'rental_price_pesewas' => 500000, // 5,000 GHS
    ]);

    $response = $this->post(route('marketplace.quotes.rfq', ['slug' => $listing->slug]), [
        'planner_name' => 'Kofi Planner',
        'planner_email' => 'kofi.planner@example.com',
        'planner_phone' => '+233241234567',
        'event_title' => 'Accra Tech Summit 2026',
        'event_date' => now()->addWeeks(3)->format('Y-m-d'),
        'guest_count' => 1200,
        'location_address' => 'Accra International Conference Centre',
        'requirements_description' => 'Need 12 line array boxes, 4 subwoofers, digital mixer, and wireless microphones for 2 days.',
    ]);

    $quote = MarketplaceQuote::where('shop_id', $shop->id)->first();
    expect($quote)->not->toBeNull()
        ->and($quote->status)->toBe(MarketplaceQuote::STATUS_PENDING_QUOTE)
        ->and($quote->planner_name)->toBe('Kofi Planner')
        ->and($quote->guest_count)->toBe(1200)
        ->and($quote->quote_reference)->toStartWith('RFQ-');

    $response->assertRedirect(route('marketplace.quotes.show', ['reference' => $quote->quote_reference]));
});

test('vendors can view RFQs, build itemized proposals with rigging fees and validity, and send them to clients', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'sound-masters']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->tenants()->attach($tenant->id);
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo('manage venue');

    $shop = Shop::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Sound Masters',
        'slug' => 'sound-masters',
        'email' => 'soundmasters@example.com',
    ]);

    $listing = StoreListing::factory()->create([
        'shop_id' => $shop->id,
        'listing_kind' => StoreListing::KIND_RENTAL,
        'category' => StoreListing::CATEGORY_PA_SOUND,
        'status' => StoreListing::STATUS_PUBLISHED,
    ]);

    $quote = MarketplaceQuote::create([
        'quote_reference' => 'RFQ-'.mb_strtoupper(Str::random(10)),
        'tenant_id' => $tenant->id,
        'shop_id' => $shop->id,
        'store_listing_id' => $listing->id,
        'planner_name' => 'Jane Planner',
        'planner_email' => 'jane@example.com',
        'requirements_description' => 'Sound setup for corporate gala',
        'status' => MarketplaceQuote::STATUS_PENDING_QUOTE,
    ]);

    $this->actingAs($user);

    // Vendor accesses quote show page in console
    $showResponse = $this->get(route('tenant.venue.quotes.show', [
        'subdomain' => $tenant->slug,
        'quote' => $quote->id,
    ]));
    $showResponse->assertOk();

    // Vendor submits structured proposal
    $proposalResponse = $this->post(route('tenant.venue.quotes.proposal', [
        'subdomain' => $tenant->slug,
        'quote' => $quote->id,
    ]), [
        'items' => [
            [
                'description' => 'JBL VTX Line Array (12 Elements)',
                'quantity' => 1,
                'unit_price_pesewas' => 800000, // 8,000 GHS
            ],
            [
                'description' => 'Dual 18" Subwoofers',
                'quantity' => 4,
                'unit_price_pesewas' => 50000, // 500 GHS each = 2,000 GHS
            ],
            [
                'description' => 'Sound Engineer & Rigging Crew',
                'quantity' => 2,
                'unit_price_pesewas' => 50000, // 500 GHS each = 1,000 GHS
            ],
        ],
        'delivery_fee_pesewas' => 50000, // 500 GHS delivery & setup
        'tax_pesewas' => 0,
        'deposit_required_pesewas' => 550000, // 50% deposit = 5,750 GHS
        'valid_until' => now()->addDays(5)->format('Y-m-d H:i:s'),
        'vendor_notes' => 'Generator power will be required on site by 08:00 GMT for sound check.',
    ]);

    $proposalResponse->assertRedirect(route('tenant.venue.quotes.show', [
        'subdomain' => $tenant->slug,
        'quote' => $quote->id,
    ]));

    $quote->refresh();
    expect($quote->status)->toBe(MarketplaceQuote::STATUS_QUOTED)
        ->and($quote->subtotal_pesewas)->toBe(1100000) // 800000 + 200000 + 100000
        ->and($quote->delivery_fee_pesewas)->toBe(50000)
        ->and($quote->total_amount_pesewas)->toBe(1150000)
        ->and($quote->deposit_required_pesewas)->toBe(550000)
        ->and($quote->items)->toHaveCount(3);
});

test('clients can review proposals, initiate Paystack deposit checkout, and verify payment', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'tent-rentals']);
    $shop = Shop::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Canopy Kingdom',
        'slug' => 'canopy-kingdom',
        'email' => 'canopy@example.com',
    ]);

    $quote = MarketplaceQuote::create([
        'quote_reference' => 'RFQ-'.mb_strtoupper(Str::random(10)),
        'tenant_id' => $tenant->id,
        'shop_id' => $shop->id,
        'planner_name' => 'Akosua Mensah',
        'planner_email' => 'akosua@example.com',
        'requirements_description' => 'Canopies for graduation',
        'status' => MarketplaceQuote::STATUS_QUOTED,
        'subtotal_pesewas' => 1000000,
        'delivery_fee_pesewas' => 100000,
        'total_amount_pesewas' => 1100000,
        'deposit_required_pesewas' => 550000,
        'valid_until' => now()->addDays(7),
    ]);

    // 1. Client views proposal publicly
    $publicView = $this->get(route('marketplace.quotes.show', ['reference' => $quote->quote_reference]));
    $publicView->assertOk();

    // 2. Client clicks Accept & Pay Deposit -> triggers Paystack checkout
    Http::fake([
        'https://api.paystack.co/customer*' => Http::response([
            'status' => true,
            'data' => ['customer_code' => 'CUST_123', 'id' => 999, 'email' => 'akosua@example.com'],
        ], 200),
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/auth_quote_test_123',
                'access_code' => 'ACC_test_123',
                'reference' => 'PAY_QUOTE_REF_777',
            ],
        ], 200),
        'https://api.paystack.co/transaction/verify/PAY_QUOTE_REF_777' => Http::response([
            'status' => true,
            'data' => [
                'id' => 1234567,
                'status' => 'success',
                'reference' => 'PAY_QUOTE_REF_777',
                // Deposit 550000 + the guest buyer's 3% service fee (16500).
                'amount' => 566500,
                'fees' => 11000, // 110 GHS Paystack fee (2%)
                'currency' => 'GHS',
                'metadata' => [
                    'marketplace_quote_id' => $quote->id,
                    'quote_reference' => $quote->quote_reference,
                    'tenant_id' => $tenant->id,
                    'type' => 'marketplace_quote',
                    'source' => config('services.paystack.metadata_source', 'miconvener'),
                ],
            ],
        ], 200),
    ]);

    $checkoutResponse = $this->withHeaders(['X-Inertia' => 'true'])
        ->post(route('marketplace.quotes.checkout', ['reference' => $quote->quote_reference]));
    $checkoutResponse->assertStatus(409);
    expect($checkoutResponse->headers->get('X-Inertia-Location'))->toBe('https://checkout.paystack.com/auth_quote_test_123');

    // 3. Paystack callback returns with successful payment verification
    $this->flushHeaders();

    $callbackResponse = $this->get(route('marketplace.quotes.callback', [
        'reference' => $quote->quote_reference,
        'trxref' => 'PAY_QUOTE_REF_777',
    ]));

    $callbackResponse->assertRedirect(route('marketplace.quotes.show', ['reference' => $quote->quote_reference]));
    $callbackResponse->assertSessionHas('success');

    $quote->refresh();
    expect($quote->status)->toBe(MarketplaceQuote::STATUS_ACCEPTED)
        ->and($quote->isPaid())->toBeTrue()
        ->and($quote->paystack_reference)->toBe('PAY_QUOTE_REF_777')
        ->and($quote->amount_paid_pesewas)->toBe(566500)
        ->and($quote->buyer_fee_pesewas)->toBe(16500);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'transaction/initialize')
        && (int) $request['amount'] === 566500);

    // 4. Verify balanced double-entry ledger transaction
    $ledgerTx = LedgerTransaction::where('tenant_id', $tenant->id)
        ->where('reference', "MKT-{$quote->quote_reference}-PAY_QUOTE_REF_777")
        ->first();

    expect($ledgerTx)->not->toBeNull()
        ->and($ledgerTx->transaction_type)->toBe('marketplace_booking');

    $entries = LedgerEntry::where('transaction_id', $ledgerTx->id)->get();
    expect($entries)->toHaveCount(3);

    $debitSum = $entries->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount');
    $creditSum = $entries->where('direction', LedgerEntry::DIRECTION_CREDIT)->sum('amount');
    expect($debitSum)->toBe($creditSum)
        ->and($debitSum)->toBe(555500); // 566500 - 11000 = 555500 pesewas net cash clearing

    // 10% commission on the 550000 deposit (55000) plus the 16500 service fee
    $platformRev = $entries->where('account_id', LedgerAccount::where('tenant_id', $tenant->id)->where('code', LedgerAccount::CODE_PLATFORM_REVENUE)->first()->id)->first();
    expect($platformRev->amount)->toBe(71500);
});

test('verified clients can submit 1 to 5 star ratings and reviews which recalculate vendor shop stats', function (): void {
    $tenant = Tenant::factory()->create();
    $shop = Shop::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Elite Catering',
        'slug' => 'elite-catering',
        'email' => 'catering@example.com',
        'average_rating' => 0.00,
        'reviews_count' => 0,
    ]);

    $quote = MarketplaceQuote::create([
        'quote_reference' => 'RFQ-'.mb_strtoupper(Str::random(10)),
        'tenant_id' => $tenant->id,
        'shop_id' => $shop->id,
        'planner_email' => 'client@acme.com',
        'planner_name' => 'Acme Planners',
        'requirements_description' => 'Catering for awards dinner',
        'status' => MarketplaceQuote::STATUS_ACCEPTED,
        'amount_paid_pesewas' => 200000,
        'paid_at' => now(),
    ]);

    $response = $this->post(route('marketplace.quotes.review', ['reference' => $quote->quote_reference]), [
        'rating' => 5,
        'title' => 'Outstanding gourmet setup and warm service',
        'comment' => 'The food was exceptionally delicious and the buffet was replenished promptly throughout our 800-person gala.',
    ]);

    $response->assertRedirect(route('marketplace.quotes.show', ['reference' => $quote->quote_reference]));

    $review = MarketplaceReview::where('marketplace_quote_id', $quote->id)->first();
    expect($review)->not->toBeNull()
        ->and($review->rating)->toBe(5)
        ->and($review->is_verified_booking)->toBeTrue();

    $shop->refresh();
    expect($shop->reviews_count)->toBe(1)
        ->and((float) $shop->average_rating)->toBe(5.00);
});

test('shops can store business registration credentials and past clients for verification', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'apex-av']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->tenants()->attach($tenant->id);
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo('manage venue');

    $shop = Shop::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Apex AV',
        'slug' => 'apex-av',
        'email' => 'contact@apexav.com',
    ]);

    $this->actingAs($user);

    $response = $this->put(route('tenant.venue.profile.update', ['subdomain' => $tenant->slug]), [
        'name' => 'Apex AV Ghana',
        'email' => 'contact@apexav.com',
        'phone' => '+233201234567',
        'address' => 'Osu Ring Road, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'vendor_categories' => [
            StoreListing::CATEGORY_PA_SOUND,
            StoreListing::CATEGORY_VIDEOGRAPHY,
            StoreListing::CATEGORY_GENERATORS,
        ],
        'business_registration_number' => 'CS123452024',
        'tax_id' => 'P0001234567',
        'past_clients' => [
            ['name' => 'MTN Ghana', 'event' => 'Annual Partners Summit'],
            ['name' => 'Stanbic Bank', 'event' => 'Jazz Festival 2025'],
        ],
    ]);

    $response->assertRedirect();

    $shop->refresh();
    expect($shop->business_registration_number)->toBe('CS123452024')
        ->and($shop->tax_id)->toBe('P0001234567')
        ->and($shop->vendor_categories)->toContain(StoreListing::CATEGORY_PA_SOUND)
        ->and($shop->past_clients)->toHaveCount(2);
});

test('superadmin can review and verify vendor credentials', function (): void {
    $superadmin = User::factory()->create(['tenant_id' => null]);
    $superRole = Role::firstOrCreate(['name' => 'Superadmin'], ['guard_name' => 'web']);
    $superadmin->roles()->attach($superRole);

    $tenant = Tenant::factory()->create();
    $shop = Shop::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Verified Gear',
        'slug' => 'verified-gear',
        'email' => 'gear@example.com',
        'verification_status' => Shop::VERIFICATION_PENDING,
        'business_registration_number' => 'BN987654321',
        'tax_id' => 'T1234567890',
    ]);

    $this->actingAs($superadmin);

    $verifyResponse = $this->post(route('admin.marketplace-verifications.approve', $shop));
    $verifyResponse->assertRedirect();

    $shop->refresh();
    expect($shop->verification_status)->toBe(Shop::VERIFICATION_VERIFIED)
        ->and($shop->isVerified())->toBeTrue()
        ->and($shop->verified_at)->not->toBeNull();
});

test('cross-tenant quotation isolation: unauthorized tenants cannot view, build proposals for, or decline another shop quotes', function (): void {
    $tenantA = Tenant::factory()->create(['slug' => 'shop-a']);
    $tenantB = Tenant::factory()->create(['slug' => 'shop-b']);

    $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
    $userB->tenants()->attach($tenantB->id);
    setPermissionsTeamId($tenantB->id);
    $userB->givePermissionTo('manage venue');

    $shopA = Shop::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Shop A',
        'slug' => 'shop-a',
        'email' => 'shopa@example.com',
    ]);
    $quoteA = MarketplaceQuote::create([
        'quote_reference' => 'RFQ-'.mb_strtoupper(Str::random(10)),
        'tenant_id' => $tenantA->id,
        'shop_id' => $shopA->id,
        'planner_name' => 'Client A',
        'planner_email' => 'clienta@example.com',
        'requirements_description' => 'Audio setup',
        'status' => MarketplaceQuote::STATUS_PENDING_QUOTE,
    ]);

    $this->actingAs($userB);

    // Tenant B attempts to view Tenant A's quote -> 403 Forbidden
    $viewResponse = $this->get(route('tenant.venue.quotes.show', [
        'subdomain' => $tenantB->slug,
        'quote' => $quoteA->id,
    ]));
    $viewResponse->assertForbidden();

    // Tenant B attempts to submit a proposal for Tenant A's quote -> 403 Forbidden
    $proposalResponse = $this->post(route('tenant.venue.quotes.proposal', [
        'subdomain' => $tenantB->slug,
        'quote' => $quoteA->id,
    ]), [
        'items' => [['description' => 'Malicious Item', 'quantity' => 1, 'unit_price_pesewas' => 1000]],
    ]);
    $proposalResponse->assertForbidden();

    // Tenant B attempts to reject Tenant A's quote -> 403 Forbidden
    $rejectResponse = $this->post(route('tenant.venue.quotes.reject', [
        'subdomain' => $tenantB->slug,
        'quote' => $quoteA->id,
    ]), ['reason' => 'Hack attempt']);
    $rejectResponse->assertForbidden();
});

test('unpaid quotes cannot receive reviews and expired quotes cannot be checked out', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'rental-hub']);
    $shop = Shop::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Rental Hub',
        'slug' => 'rental-hub',
        'email' => 'rentalhub@example.com',
    ]);

    $quote = MarketplaceQuote::create([
        'quote_reference' => 'RFQ-'.mb_strtoupper(Str::random(10)),
        'tenant_id' => $tenant->id,
        'shop_id' => $shop->id,
        'planner_name' => 'Planner Unpaid',
        'planner_email' => 'unpaid@example.com',
        'requirements_description' => 'Decor inquiry',
        'status' => MarketplaceQuote::STATUS_PENDING_QUOTE,
        'amount_paid_pesewas' => 0,
        'paid_at' => null,
    ]);

    // Review on unpaid quote must be denied
    $reviewResponse = $this->post(route('marketplace.quotes.review', ['reference' => $quote->quote_reference]), [
        'rating' => 5,
        'comment' => 'Fake review before paying',
    ]);
    $reviewResponse->assertRedirect(route('marketplace.quotes.show', ['reference' => $quote->quote_reference]));
    $reviewResponse->assertSessionHas('error');

    expect(MarketplaceReview::where('marketplace_quote_id', $quote->id)->exists())->toBeFalse();

    // Expired quote cannot checkout
    $expiredQuote = MarketplaceQuote::create([
        'quote_reference' => 'RFQ-'.mb_strtoupper(Str::random(10)),
        'tenant_id' => $tenant->id,
        'shop_id' => $shop->id,
        'planner_name' => 'Planner Expired',
        'planner_email' => 'expired@example.com',
        'requirements_description' => 'Expired proposal inquiry',
        'status' => MarketplaceQuote::STATUS_QUOTED,
        'valid_until' => now()->subDay(), // Expired
    ]);

    $checkoutResponse = $this->post(route('marketplace.quotes.checkout', ['reference' => $expiredQuote->quote_reference]));
    $checkoutResponse->assertRedirect(route('marketplace.quotes.show', ['reference' => $expiredQuote->quote_reference]));
    $checkoutResponse->assertSessionHas('error');
});
