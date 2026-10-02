<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventContribution;
use App\Models\EventLedgerEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    Config::set('services.settlement.paystack.secret_key', 'sk_test_platform_settlement');
    Config::set('services.paystack.metadata_source', 'miconvener');
});

test('public payload includes contribution settings, goal, totals, and approved tributes', function (): void {
    [$tenant] = eventHost('church-gh');
    $host = eventSubdomainHost('church-gh');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_contributions' => true,
        'contribution_title' => 'Harvest & Building Fund',
        'contribution_description' => 'Support the youth hall expansion project.',
        'contribution_presets' => [2000, 5000, 10000, 20000],
        'contribution_min_amount_pesewas' => 500,
        'contribution_goal_amount_pesewas' => 1000000,
        'show_tribute_wall' => true,
        'show_contributor_amounts' => true,
    ]);

    // Create a completed approved contribution with tribute
    EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Kofi Annan',
        'contributor_email' => 'kofi@example.com',
        'amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'CONTRIB-REF-1',
        'tribute_message' => 'God bless this congregation!',
        'is_anonymous' => false,
        'is_approved' => true,
        'paid_at' => now(),
    ]);

    // Create an unapproved tribute (should not appear in public tributes)
    EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Spammer',
        'amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'CONTRIB-REF-2',
        'tribute_message' => 'Buy cheap watches here',
        'is_anonymous' => false,
        'is_approved' => false,
        'paid_at' => now(),
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}", ['HTTP_HOST' => $host]);
    $response->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('Public/Events/Show')
        ->where('event.allow_contributions', true)
        ->where('event.contribution_title', 'Harvest & Building Fund')
        ->where('event.contribution_presets', [2000, 5000, 10000, 20000])
        ->where('event.contribution_goal_amount_pesewas', 1000000)
        ->where('event.contributions_count', 2)
        ->where('event.contributions_total_pesewas', 10000)
        ->has('event.tributes', 1)
        ->where('event.tributes.0.contributor_name', 'Kofi Annan')
        ->where('event.tributes.0.tribute_message', 'God bless this congregation!')
        ->where('event.tributes.0.amount', 5000)
    );
});

test('public user cannot contribute if event does not allow contributions', function (): void {
    [$tenant] = eventHost('funeral-gh');
    $host = eventSubdomainHost('funeral-gh');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_contributions' => false,
    ]);

    $response = $this->post("http://{$host}/e/{$event->slug}/contribute", [
        'contributor_name' => 'Ama Mensah',
        'amount' => 50.00,
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(EventContribution::count())->toBe(0);
});

test('public user cannot contribute to a draft unpublished event', function (): void {
    [$tenant] = eventHost('funeral-gh-2');
    $host = eventSubdomainHost('funeral-gh-2');

    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => Event::STATUS_DRAFT,
        'allow_contributions' => true,
    ]);

    $response = $this->post("http://{$host}/e/{$event->slug}/contribute", [
        'contributor_name' => 'Ama Mensah',
        'amount' => 50.00,
    ], ['HTTP_HOST' => $host]);

    $response->assertNotFound();
    expect(EventContribution::count())->toBe(0);
});

test('contribute endpoint enforces input validation and minimum amounts', function (): void {
    [$tenant] = eventHost('church-accra');
    $host = eventSubdomainHost('church-accra');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_contributions' => true,
        'contribution_min_amount_pesewas' => 500, // 5.00 GHS
    ]);

    // Below min amount
    $response = $this->post("http://{$host}/e/{$event->slug}/contribute", [
        'contributor_name' => 'Kwesi',
        'amount' => 2.00,
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasErrors(['amount']);

    // Missing name
    $response = $this->post("http://{$host}/e/{$event->slug}/contribute", [
        'amount' => 10.00,
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasErrors(['contributor_name']);
    expect(EventContribution::count())->toBe(0);
});

test('contribute initiates Paystack checkout session and creates pending contribution', function (): void {
    [$tenant] = eventHost('memorial-service');
    $host = eventSubdomainHost('memorial-service');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_contributions' => true,
        'currency' => 'GHS',
        'contribution_min_amount_pesewas' => 100,
    ]);

    Http::fake([
        'https://api.paystack.co/customer*' => Http::response([
            'status' => true,
            'data' => [
                'customer_code' => 'CUS_mock_123',
                'email' => 'kwabena@example.com',
            ],
        ]),
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/auth_xyz789',
                'reference' => 'PAYSTACK_INIT_REF',
            ],
        ]),
    ]);

    $response = $this->withHeaders(['X-Inertia' => 'true'])->post("http://{$host}/e/{$event->slug}/contribute", [
        'contributor_name' => 'Nana Kwabena',
        'contributor_email' => 'kwabena@example.com',
        'contributor_phone' => '+233240001122',
        'amount' => 100.00, // 10,000 pesewas
        'tribute_message' => 'Rest well, Uncle. We will dearly miss you.',
        'is_anonymous' => false,
    ], ['HTTP_HOST' => $host]);

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toBe('https://checkout.paystack.com/auth_xyz789');

    $contribution = EventContribution::where('event_id', $event->id)->firstOrFail();
    expect($contribution->contributor_name)->toBe('Nana Kwabena')
        ->and($contribution->amount)->toBe(10000)
        ->and($contribution->currency)->toBe('GHS')
        ->and($contribution->status)->toBe(EventContribution::STATUS_PENDING_PAYMENT)
        ->and($contribution->tribute_message)->toBe('Rest well, Uncle. We will dearly miss you.')
        ->and($contribution->payment_reference)->toStartWith('CONTRIB-');
});

test('browser callback verifies payment with Paystack and records to double-entry ledger', function (): void {
    [$tenant] = eventHost('fundraiser-gh');
    $host = eventSubdomainHost('fundraiser-gh');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_contributions' => true,
        'currency' => 'GHS',
        'ticket_price' => 0,
    ]);

    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Akosua Serwaa',
        'contributor_email' => 'akosua@example.com',
        'amount' => 5000, // 50.00 GHS
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_PENDING_PAYMENT,
        'payment_reference' => 'CONTRIB-REF-CALLBACK-1',
        'tribute_message' => 'Keep up the good work.',
        'is_approved' => true,
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success',
                'id' => 987654,
                'reference' => 'PAYSTACK_VERIFY_REF_1',
                'amount' => 5000,
                'fees' => 95,
                'currency' => 'GHS',
                'metadata' => [
                    'type' => 'event_contribution',
                    'event_contribution_id' => $contribution->id,
                    'tenant_id' => $tenant->id,
                ],
            ],
        ]),
    ]);

    $callbackUrl = "http://{$host}/e/{$event->slug}/contributions/{$contribution->id}/callback?reference=PAYSTACK_VERIFY_REF_1";
    $response = $this->get($callbackUrl, ['HTTP_HOST' => $host]);

    $response->assertRedirect("http://{$host}/e/{$event->slug}");
    $response->assertSessionHas('success');

    $contribution->refresh();
    expect($contribution->status)->toBe(EventContribution::STATUS_COMPLETED)
        ->and($contribution->paystack_reference)->toBe('PAYSTACK_VERIFY_REF_1')
        ->and($contribution->gateway_fee_amount)->toBe(95)
        ->and($contribution->paid_at)->not->toBeNull();

    // Verify flat EventLedgerEntry
    $ledgerEntry = EventLedgerEntry::on('landlord')
        ->where('contribution_id', $contribution->id)
        ->where('type', EventLedgerEntry::TYPE_CHARGE)
        ->firstOrFail();

    expect($ledgerEntry->gross_amount)->toBe(5000)
        ->and($ledgerEntry->gateway_fee_amount)->toBe(95)
        ->and($ledgerEntry->provider_reference)->toBe('PAYSTACK_VERIFY_REF_1');

    // Verify double-entry ledger balance
    $account = LedgerAccount::on('landlord')
        ->where('event_id', $event->id)
        ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)
        ->firstOrFail();

    $payableBalance = (int) LedgerEntry::on('landlord')->where('account_id', $account->id)
        ->where('direction', LedgerEntry::DIRECTION_CREDIT)->sum('amount')
        - (int) LedgerEntry::on('landlord')->where('account_id', $account->id)
            ->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount');

    expect($payableBalance)->toBe($ledgerEntry->net_amount);
});

test('browser callback rejects verification if amount or currency mismatches', function (): void {
    [$tenant] = eventHost('mismatch-test');
    $host = eventSubdomainHost('mismatch-test');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_contributions' => true,
        'currency' => 'GHS',
    ]);

    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Attacker',
        'amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_PENDING_PAYMENT,
        'payment_reference' => 'CONTRIB-REF-CHEAT',
    ]);

    // Paystack reported only 100 pesewas paid instead of 5000
    Http::fake([
        'https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success',
                'id' => 999999,
                'reference' => 'PAYSTACK_UNDERPAID',
                'amount' => 100, // Less than required 5000
                'fees' => 2,
                'currency' => 'GHS',
                'metadata' => ['event_contribution_id' => $contribution->id],
            ],
        ]),
    ]);

    $callbackUrl = "http://{$host}/e/{$event->slug}/contributions/{$contribution->id}/callback?reference=PAYSTACK_UNDERPAID";
    $response = $this->get($callbackUrl, ['HTTP_HOST' => $host]);

    $response->assertRedirect("http://{$host}/e/{$event->slug}");
    $response->assertSessionHas('error');

    $contribution->refresh();
    expect($contribution->status)->toBe(EventContribution::STATUS_PENDING_PAYMENT);
    expect(EventLedgerEntry::where('contribution_id', $contribution->id)->count())->toBe(0);
});

test('settlement webhook idempotently confirms contribution charge and is re-run safe', function (): void {
    [$tenant] = eventHost('webhook-test');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_contributions' => true,
        'currency' => 'GHS',
    ]);

    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Grace Bediako',
        'amount' => 20000, // 200.00 GHS
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_PENDING_PAYMENT,
        'payment_reference' => 'CONTRIB-WEBHOOK-REF',
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'id' => 11223344,
            'reference' => 'PAYSTACK_WEBHOOK_TX_1',
            'amount' => 20000,
            'fees' => 390,
            'currency' => 'GHS',
            'metadata' => [
                'source' => 'miconvener',
                'type' => 'event_contribution',
                'event_contribution_id' => $contribution->id,
                'tenant_id' => $tenant->id,
                'event_id' => $event->id,
            ],
        ],
    ];

    $body = (string) json_encode($payload);
    $platformSecret = config('services.settlement.paystack.secret_key');
    $signature = hash_hmac('sha512', $body, $platformSecret);

    // First webhook delivery
    $response = $this->call('POST', '/webhooks/settlement/paystack', [], [], [], [
        'HTTP_x-paystack-signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertOk();

    $contribution->refresh();
    expect($contribution->status)->toBe(EventContribution::STATUS_COMPLETED)
        ->and($contribution->paystack_reference)->toBe('PAYSTACK_WEBHOOK_TX_1')
        ->and($contribution->gateway_fee_amount)->toBe(390)
        ->and($contribution->amount)->toBe(20000);

    expect(EventLedgerEntry::where('contribution_id', $contribution->id)->count())->toBe(1);

    // Second duplicate webhook delivery (idempotency check)
    $responseSecond = $this->call('POST', '/webhooks/settlement/paystack', [], [], [], [
        'HTTP_x-paystack-signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $responseSecond->assertOk();
    expect(EventLedgerEntry::where('contribution_id', $contribution->id)->count())->toBe(1);
});

test('organizer can update contribution settings and toggle tribute moderation in console', function (): void {
    [$tenant, $user] = eventHost('console-test');
    $host = eventSubdomainHost('console-test');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_contributions' => false,
    ]);

    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Abena Osei',
        'amount' => 10000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'CONTRIB-MODERATE-1',
        'tribute_message' => 'Heartfelt condolences from the Osei family.',
        'is_approved' => true,
    ]);

    // 1. Update settings
    $settingsUrl = "http://{$host}/events/{$event->id}/contributions/settings";
    $patchResponse = $this->actingAs($user)->patch($settingsUrl, [
        'allow_contributions' => true,
        'contribution_title' => 'Memorial Donations (Nsawa)',
        'contribution_description' => 'In memory of our matriarch.',
        'contribution_presets' => [5000, 10000, 20000, 50000],
        'contribution_min_amount_pesewas' => 1000,
        'contribution_goal_amount_pesewas' => 5000000,
        'show_tribute_wall' => true,
        'show_contributor_amounts' => false,
    ], ['HTTP_HOST' => $host]);

    $patchResponse->assertRedirect();
    $patchResponse->assertSessionHas('success');

    $event->refresh();
    expect($event->allow_contributions)->toBeTrue()
        ->and($event->contribution_title)->toBe('Memorial Donations (Nsawa)')
        ->and($event->contribution_min_amount_pesewas)->toBe(1000);

    // 2. Toggle approval (hide tribute)
    $toggleUrl = "http://{$host}/events/{$event->id}/contributions/{$contribution->id}/toggle-approval";
    $toggleResponse = $this->actingAs($user)->patch($toggleUrl, [], ['HTTP_HOST' => $host]);
    $toggleResponse->assertRedirect();

    $contribution->refresh();
    expect($contribution->is_approved)->toBeFalse();

    // 3. Export CSV
    $exportUrl = "http://{$host}/events/{$event->id}/contributions/export";
    $exportResponse = $this->actingAs($user)->get($exportUrl, ['HTTP_HOST' => $host]);
    $exportResponse->assertOk();
    $exportResponse->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('unauthorized user cannot update contribution settings or toggle approvals', function (): void {
    [$tenant] = eventHost('unauth-test');
    $host = eventSubdomainHost('unauth-test');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'allow_contributions' => false,
    ]);

    // Unauthenticated request to settings
    $response = $this->patch("http://{$host}/events/{$event->id}/contributions/settings", [
        'allow_contributions' => true,
        'show_tribute_wall' => true,
        'show_contributor_amounts' => false,
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect('/login');

    // Normal user without 'update event' permission
    $randomUser = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($randomUser->id);

    $forbiddenResponse = $this->actingAs($randomUser)
        ->patch("http://{$host}/events/{$event->id}/contributions/settings", [
            'allow_contributions' => true,
            'show_tribute_wall' => true,
            'show_contributor_amounts' => false,
        ], ['HTTP_HOST' => $host]);

    $forbiddenResponse->assertForbidden();
});
