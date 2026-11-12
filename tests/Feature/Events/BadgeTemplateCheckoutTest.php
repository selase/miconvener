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
