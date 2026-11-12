<?php

declare(strict_types=1);

use App\Models\BadgeTemplatePurchase;
use App\Models\StoreCommercialAgreement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Badges\BadgeTemplatePurchases;
use App\Services\Giving\GivingGateways;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $fixture = BadgeTemplatePurchase::factory()->create();
    $this->event = $fixture->event;
    $this->version = $fixture->version;
    $this->seller = $fixture->order->tenant;
    $this->buyer = Tenant::query()->findOrFail($fixture->buyer_tenant_id);
    $this->actor = User::factory()->create(['tenant_id' => $this->buyer->id]);
    $this->buyer->users()->attach($this->actor->id);
    setPermissionsTeamId($this->buyer->id);
    $this->actor->assignRole('Org Superadmin');
    $fixture->update(['state' => 'cancelled']);
    config(['services.settlement.paystack.secret_key' => 'sk_test_badge', 'services.badge_templates.seller_tenant_id' => $this->seller->id,
        'services.badge_templates.commercial_approval' => 'owner-approved-test-policy']);
    $this->buyer->features()->create(['feature_key' => 'paid_badge_templates', 'enabled' => true]);
    app(App\Services\Finance\LedgerService::class)->postTransaction($this->seller, null, 'badge_template_reserve_funding', 'Synthetic reserve funding', 'checkout-funding', [['code' => '1020', 'direction' => 'debit', 'amount' => 50000], ['code' => '1010', 'direction' => 'credit', 'amount' => 50000]], null, 'GHS', App\Enum\LedgerCategoryEnum::Commerce);
    StoreCommercialAgreement::factory()->create(['tenant_id' => $this->seller->id, 'shop_id' => $fixture->order->shop_id, 'version' => 2, 'offering_scope' => 'badge_template', 'percentage' => '0.00', 'refund_gateway_fee_bearer' => 'platform', 'account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity())]);
});

test('a paid template quote freezes the event licence and charges exactly fifty cedis', function (): void {
    $purchase = app(BadgeTemplatePurchases::class)->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    expect($purchase->order->charged_pesewas)->toBe(5000)
        ->and($purchase->order->platform_fee_pesewas)->toBe(0)
        ->and($purchase->order->tenant_id)->toBe($this->seller->id)
        ->and($purchase->buyer_tenant_id)->toBe($this->buyer->id)
        ->and($purchase->terms_snapshot['refund_days'])->toBe(7)
        ->and($purchase->order->lines()->first()->badge_purchase_id)->toBe($purchase->id);
});

test('a changed request key reuses the same pending event licence and original deadline', function (): void {
    $service = app(BadgeTemplatePurchases::class);
    $first = $service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $this->travel(2)->minutes();
    $again = $service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    expect($again->id)->toBe($first->id)->and($again->order->expires_at->equalTo($first->order->expires_at))->toBeTrue();
});

test('paid quotes remain off without explicit organisation approval', function (): void {
    $this->buyer->features()->where('feature_key', 'paid_badge_templates')->update(['enabled' => false]);
    expect(fn () => app(BadgeTemplatePurchases::class)->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid()))->toThrow(ValidationException::class);
});

test('another organisation cannot purchase against the event even with a loaded event copy', function (): void {
    $foreign = User::factory()->create();
    expect(fn () => app(BadgeTemplatePurchases::class)->quote($this->event, $this->version, $foreign, 'buyer@example.test', (string) Str::uuid()))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('organisation admins do not acquire paid purchase permission by managing badges', function (): void {
    $this->actor->syncRoles(['Org Admin']);
    expect(fn () => app(BadgeTemplatePurchases::class)->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid()))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('an unknown payment prevents a second purchase after the quote expires', function (): void {
    $service = app(BadgeTemplatePurchases::class);
    $first = $service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    App\Models\StorePaymentAttempt::factory()->create(['order_id' => $first->order_id, 'status' => 'unknown']);
    $this->travel(11)->minutes();
    expect(fn () => $service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid()))->toThrow(ValidationException::class);
});

test('buyers with their own gateway still purchase from the approved platform seller', function (): void {
    $this->buyer->update(['settlement_mode' => 'own_gateway']);
    $purchase = app(BadgeTemplatePurchases::class)->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    expect($purchase->order->settlement_mode)->toBe('platform_default')->and($purchase->order->tenant_id)->toBe($this->seller->id);
});

test('the quote endpoint requires an independently verified payer and hides foreign events', function (): void {
    $host = eventSubdomainHost($this->buyer->slug);
    $url = "http://{$host}/events/{$this->event->id}/badges/library/purchases";
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host]);
    $input = ['version_id' => $this->version->id, 'operation_key' => (string) Str::uuid()];
    $this->postJson($url, $input)->assertForbidden();
    $this->withSession(proofFor('buyer@example.test'))->postJson($url, $input)->assertOk()->assertJsonPath('terms.total_pesewas', 5000);
    $foreign = App\Models\Event::factory()->create();
    $this->postJson("http://{$host}/events/{$foreign->id}/badges/library/purchases", $input)->assertNotFound();
});

test('checkout refuses a different accepted licence hash without contacting the provider', function (): void {
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $purchase = app(BadgeTemplatePurchases::class)->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $host = eventSubdomainHost($this->buyer->slug);
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host])->withSession(proofFor('buyer@example.test'))
        ->postJson("http://{$host}/events/{$this->event->id}/badges/library/purchases/{$purchase->id}/checkout", [
            'version_id' => $this->version->id, 'operation_key' => (string) Str::uuid(), 'accepted_terms_hash' => str_repeat('0', 64),
        ])->assertUnprocessable();
    expect($purchase->order->attempts()->count())->toBe(0);
});

test('an active event licence returns the existing purchase without another order', function (): void {
    $service = app(BadgeTemplatePurchases::class);
    $first = $service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $first->update(['state' => 'paid']);
    $first->licence->update(['state' => 'active', 'current_purchase_id' => $first->id]);
    expect($service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid())->id)->toBe($first->id);
});

test('the central platform price book owns the fixed template licence price', function (): void {
    $price = app(App\Services\Pricing\PriceBook::class)->badgeTemplate();
    expect($price->minor)->toBe(5000)->and($price->currency)->toBe('GHS');
});

test('a later family version reuses the original pending licence checkout and deadline', function (): void {
    $service = app(BadgeTemplatePurchases::class);
    $first = $service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $later = $this->version->design->versions()->create(['version' => 2, 'snapshot' => []]);
    $this->travel(2)->minutes();
    $again = $service->quote($this->event, $later, $this->actor, 'buyer@example.test', (string) Str::uuid());
    expect($again->id)->toBe($first->id)->and($again->order->expires_at->equalTo($first->order->expires_at))->toBeTrue();
});

test('a new purchase cannot spend refund backing promised to an existing event', function (): void {
    app(App\Services\Finance\LedgerService::class)->postTransaction($this->seller, null, 'badge_template_reserve_adjustment', 'Synthetic reserve adjustment', 'reduce-fixture', [
        ['code' => '1010', 'direction' => 'debit', 'amount' => 45000], ['code' => '1020', 'direction' => 'credit', 'amount' => 45000],
    ], null, 'GHS', App\Enum\LedgerCategoryEnum::Commerce);
    $service = app(BadgeTemplatePurchases::class);
    $service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $other = App\Models\Event::factory()->create(['tenant_id' => $this->buyer->id]);
    expect(fn () => $service->quote($other, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid()))->toThrow(ValidationException::class);
});

test('current event staff can recover a purchase status without exposing platform finance', function (): void {
    $purchase = app(BadgeTemplatePurchases::class)->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $host = eventSubdomainHost($this->buyer->slug);
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host])
        ->getJson("http://{$host}/events/{$this->event->id}/badges/library/purchases/{$purchase->id}")
        ->assertOk()->assertJsonPath('purchase_id', $purchase->id)->assertJsonPath('state', 'pending')
        ->assertJsonMissingPath('account_identity')->assertJsonMissingPath('gateway_fee_pesewas');
    $this->actor->syncRoles(['Org Admin']);
    $this->getJson("http://{$host}/events/{$this->event->id}/badges/library/purchases/{$purchase->id}")->assertForbidden();
});

test('only the original independently verified payer can view a digital receipt page', function (): void {
    $purchase = app(BadgeTemplatePurchases::class)->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $path = "/my/badge-template-purchases/{$purchase->id}";
    $this->get($path)->assertRedirect('/my?return_to='.rawurlencode($path));
    $this->withSession(proofFor('other@example.test'))->getJson($path)->assertNotFound();
    $this->withSession(proofFor('buyer@example.test'))->getJson($path)->assertOk()->assertJsonPath('purchase_id', $purchase->id)
        ->assertJsonMissingPath('account_identity')->assertJsonMissingPath('gateway_fee_pesewas');
    $this->event->delete();
    $this->getJson($path)->assertOk()->assertJsonPath('event.archived', true);
});

test('a proposed paid design draft survives payment return only for its actor and event', function (): void {
    $host = eventSubdomainHost($this->buyer->slug);
    $path = "http://{$host}/events/{$this->event->id}/badges/library/purchase-draft";
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host]);
    $draft = ['version_id' => $this->version->id, 'expected_version' => 1, 'width_mm' => 85, 'height_mm' => 54, 'composition' => 'landscape', 'category_labels' => []];
    $this->postJson($path, $draft)->assertOk();
    $this->version->design->versions()->create(['version' => 2, 'snapshot' => []]);
    $this->getJson($path)->assertOk()->assertJsonPath('draft.width_mm', 85)->assertJsonPath('version.id', $this->version->id);
    $this->travel(25)->hours();
    $this->getJson($path)->assertOk()->assertJsonPath('draft', null);
});

test('payer verification status never treats a staff email as independent proof', function (): void {
    $host = eventSubdomainHost($this->buyer->slug);
    $path = "http://{$host}/events/{$this->event->id}/badges/library/payer";
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host])->getJson($path)->assertOk()->assertJsonPath('verified_email', null);
    $this->withSession(proofFor('buyer@example.test'))->getJson($path)->assertOk()->assertJsonPath('verified_email', 'buyer@example.test');
});

test('paid template rollout is dry by default and refuses an unfunded explicit enable', function (): void {
    $this->buyer->features()->where('feature_key', 'paid_badge_templates')->delete();
    $this->artisan('badges:paid-templates', ['tenant' => $this->buyer->slug])->assertSuccessful();
    expect($this->buyer->features()->where('feature_key', 'paid_badge_templates')->exists())->toBeFalse();
    $this->artisan('badges:paid-templates', ['tenant' => $this->buyer->slug, '--enable' => true])->assertSuccessful();
    expect($this->buyer->features()->where('feature_key', 'paid_badge_templates')->value('enabled'))->toBeTrue();
    $this->artisan('badges:paid-templates', ['tenant' => $this->buyer->slug, '--off' => true])->assertSuccessful();
    config(['services.badge_templates.commercial_approval' => null]);
    $this->artisan('badges:paid-templates', ['tenant' => $this->buyer->slug, '--enable' => true])->assertFailed();
    expect($this->buyer->features()->where('feature_key', 'paid_badge_templates')->value('enabled'))->toBeFalse();
});

test('digital agreements are restricted to the configured platform seller and fixed licence economics', function (): void {
    $actor = User::factory()->create();
    setPermissionsTeamId(null);
    $actor->assignRole('Superadmin');
    $input = ['percentage' => 0, 'cap_pesewas' => null, 'fee_bearer' => 'organizer', 'gateway_estimate_rate' => 0,
        'refund_gateway_fee_bearer' => 'platform', 'collection_terms' => 'Digital event licence. Unlimited reprints.',
        'refund_terms' => 'Seven calendar days while unused.', 'support_contact' => 'Platform support', 'receipt_terms' => 'Owner-approved receipt presentation',
        'payout_policy' => ['holdback_pesewas' => 0, 'manual_release' => true]];
    $service = app(App\Services\Marketplace\StoreCommercialTerms::class);
    $agreement = $service->approve($this->seller, $actor, $input, 'badge_template');
    expect($agreement->offering_scope)->toBe('badge_template')->and($agreement->percentage)->toBe('0.00');
    expect(fn () => $service->approve($this->buyer, $actor, $input, 'badge_template'))->toThrow(ValidationException::class);
    expect(fn () => $service->approve($this->seller, $actor, [...$input, 'percentage' => 3], 'badge_template'))->toThrow(ValidationException::class);
});

test('a buyer organisation with digital licence history receives a clear deletion refusal', function (): void {
    $admin = User::factory()->create();
    setPermissionsTeamId(null);
    $admin->assignRole('Superadmin');
    $this->actingAs($admin)->deleteJson('/tenants/'.$this->buyer->uuid)->assertStatus(409)->assertJsonPath('message', 'Deactivate this organisation instead. Paid badge template purchase and licence history must be retained.');
    expect(Tenant::query()->whereKey($this->buyer->id)->exists())->toBeTrue();
});

test('a suspended event licence refuses another purchase while its refund is unresolved', function (): void {
    $service = app(BadgeTemplatePurchases::class);
    $purchase = $service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $purchase->update(['state' => 'paid']);
    $purchase->order->update(['payment_state' => 'refund_pending']);
    $purchase->licence->update(['state' => 'suspended', 'current_purchase_id' => $purchase->id]);
    expect(fn () => $service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(BadgeTemplatePurchase::query()->where('event_id', $this->event->id)->where('state', 'pending')->count())->toBe(0);
});

test('the original payer can discover digital licence purchases separately from physical orders', function (): void {
    $purchase = app(BadgeTemplatePurchases::class)->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $this->withSession(proofFor('other@example.test'))->getJson('/my/badge-template-purchases')->assertOk()->assertJsonCount(0, 'purchases.data');
    $this->withSession(proofFor('buyer@example.test'))->getJson('/my/badge-template-purchases')->assertOk()->assertJsonPath('purchases.data.0.purchase_id', $purchase->id);
});

test('paid checkout readiness refuses missing built in role definitions without seeding them', function (): void {
    App\Models\Role::query()->whereNull('tenant_id')->where('name', 'Superadmin')->delete();
    $this->artisan('badges:paid-templates', ['tenant' => $this->buyer->slug, '--enable' => true])->assertFailed();
    expect(App\Models\Role::query()->whereNull('tenant_id')->where('name', 'Superadmin')->exists())->toBeFalse();
});

test('removing a staff account with paid design history leaves its membership intact with a clear explanation', function (): void {
    $target = User::factory()->create(['tenant_id' => $this->buyer->id]);
    $this->buyer->users()->attach($target->id);
    setPermissionsTeamId($this->buyer->id);
    $target->assignRole('Org Superadmin');
    app(BadgeTemplatePurchases::class)->quote($this->event, $this->version, $target, 'buyer@example.test', (string) Str::uuid());
    $host = eventSubdomainHost($this->buyer->slug);
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host])->delete("http://{$host}/users/{$target->uuid}")->assertRedirect()->assertSessionHas('error', 'This account has paid badge template audit history. Deactivate its access instead of deleting the retained account.');
    expect($target->fresh())->not->toBeNull()->and($target->tenants()->whereKey($this->buyer->id)->exists())->toBeTrue();
});

test('platform sales history does not borrow a newer purchases licence status or clean use', function (): void {
    $purchase = app(BadgeTemplatePurchases::class)->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $purchase->update(['state' => 'cancelled']);
    $replacement = BadgeTemplatePurchase::factory()->create(['licence_id' => $purchase->licence_id, 'version_id' => $purchase->version_id, 'state' => 'paid']);
    $replacement->licence->update(['state' => 'active', 'current_purchase_id' => $replacement->id, 'first_used_at' => now()]);
    $finance = User::factory()->create();
    setPermissionsTeamId(null);
    $finance->assignRole('Superadmin');
    $this->actingAs($finance)->get('/admin/badge-template-sales')->assertOk()
        ->assertSee('cancelled · Licence cancelled')
        ->assertSee('No clean output requested · Purchase '.$purchase->id);
});

test('a badge purchase started on an organisation host gives Paystack a platform callback', function (): void {
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake([
        '*api.paystack.co/customer*' => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_BADGE', 'email' => 'buyer@example.test']]),
        '*api.paystack.co/transaction/initialize' => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/test-badge']]),
    ]);
    $purchase = app(BadgeTemplatePurchases::class)->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $host = eventSubdomainHost($this->buyer->slug);
    $this->actingAs($this->actor)->withSession(proofFor('buyer@example.test'))
        ->postJson("http://{$host}/events/{$this->event->id}/badges/library/purchases/{$purchase->id}/checkout", [
            'version_id' => $this->version->id, 'operation_key' => (string) Str::uuid(), 'accepted_terms_hash' => $purchase->order->terms_hash,
        ])->assertOk()->assertJsonPath('status', 'ready');
    Illuminate\Support\Facades\Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/transaction/initialize')
        && str_starts_with($request['callback_url'], mb_rtrim((string) config('app.url'), '/').'/marketplace/checkout/'));
});

test('an expired definitively refused initialization permits a fresh paid design quote', function (): void {
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake([
        '*api.paystack.co/customer*' => Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_BADGE', 'email' => 'buyer@example.test']]),
        '*api.paystack.co/transaction/initialize' => Illuminate\Support\Facades\Http::response(['status' => false, 'message' => 'Invalid Email Address Passed'], 400),
    ]);
    $service = app(BadgeTemplatePurchases::class);
    $first = $service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $this->actingAs($this->actor);
    $session = app('session.store');
    $session->put(proofFor('buyer@example.test'));
    $attempt = app(App\Services\Marketplace\StorePayments::class)->start($first->order, $session, (string) Str::uuid(), $first->order->terms_hash);
    expect($attempt->status)->toBe('failed')->and($first->order->fresh()->state)->toBe('awaiting_payment');
    $this->travel(11)->minutes();
    $next = $service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    expect($next->id)->not->toBe($first->id)->and($first->fresh()->state)->toBe('cancelled')->and($next->state)->toBe('pending');
});

test('another authorised payer can replace an expired untouched design quote', function (): void {
    $service = app(BadgeTemplatePurchases::class);
    $first = $service->quote($this->event, $this->version, $this->actor, 'buyer@example.test', (string) Str::uuid());
    $other = User::factory()->create(['tenant_id' => $this->buyer->id]);
    $this->buyer->users()->attach($other->id);
    $other->assignRole('Org Superadmin');
    $this->travel(11)->minutes();
    $next = $service->quote($this->event, $this->version, $other, 'other-payer@example.test', (string) Str::uuid());
    expect($next->id)->not->toBe($first->id)->and($first->fresh()->state)->toBe('cancelled')
        ->and($next->order->buyer_email)->toBe('other-payer@example.test')->and($first->order->fresh()->buyer_email)->toBe('buyer@example.test');
});
