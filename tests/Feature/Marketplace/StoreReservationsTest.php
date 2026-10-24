<?php

declare(strict_types=1);

use App\Models\StoreOrder;
use App\Models\StoreStockAllocation;
use App\Models\StoreStockMovement;
use App\Services\Marketplace\MerchandiseInventory;
use App\Services\Marketplace\PurchaseReservations;
use App\Services\Marketplace\StoreQuotes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StoreCheckoutScenario;

it('quotes server prices and reserves stock once with an immutable ten minute window', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $key = (string) Str::uuid();
    $service = app(StoreQuotes::class);
    $order = $service->create($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 2]], $key);
    $expiry = $order->expires_at;
    $this->travel(2)->minutes();
    $same = $service->create($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 2]], $key);
    expect($same->id)->toBe($order->id)->and($same->expires_at->equalTo($expiry))->toBeTrue()
        ->and($order->principal_pesewas)->toBe(20000)->and($order->charged_pesewas)->toBe(20000)
        ->and($order->platform_fee_pesewas)->toBe(400)->and($variant->fresh()->on_hand)->toBe(3)
        ->and(StoreStockAllocation::query()->sum('quantity'))->toBe(2);
});

it('rejects changed payloads reusing the quote submission key', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $key = (string) Str::uuid();
    StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], $key);
    expect(fn () => StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 2]], $key))->toThrow(ValidationException::class);
    expect(StoreStockAllocation::query()->sum('quantity'))->toBe(1);
});

it('rejects quantities without coercing fractions negatives oversized or string values', function (mixed $quantity): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    expect(fn () => StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => $quantity]], (string) Str::uuid()))->toThrow(ValidationException::class);
})->with([-1, 0, 1.5, 100, '2', null]);

it('refuses another buyer the last held unit and protects it from manual reductions with checkout disabled', function (): void {
    [$tenant, $variant, $actor, $session] = StoreCheckoutScenario::make(1);
    StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    expect(fn () => StoreCheckoutScenario::quote($tenant, StoreCheckoutScenario::session('second@example.test'), [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid()))->toThrow(ValidationException::class);
    $tenant->features()->where('feature_key', 'commerce_checkout')->update(['enabled' => false]);
    expect(fn () => app(MerchandiseInventory::class)->adjust($variant, -1, 'Remove held unit', (string) Str::uuid(), $actor))->toThrow(ValidationException::class);
    expect($variant->fresh()->on_hand)->toBe(1);
});

it('releases an expired hold once and does not extend an expired quote', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make(1);
    $key = (string) Str::uuid();
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], $key);
    $this->travel(10)->minutes();
    app(PurchaseReservations::class)->release($order, 'expired');
    app(PurchaseReservations::class)->release($order, 'expired');
    expect(StoreStockAllocation::query()->where('state', 'released')->count())->toBe(1)
        ->and($variant->fresh()->on_hand)->toBe(1);
    expect(fn () => StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], $key))->toThrow(ValidationException::class);
});

it('does not let public callers consume a paid order from client-set flags', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make(1);
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $order->update(['state' => 'confirmed', 'payment_state' => 'paid', 'ledger_state' => 'posted', 'accepted_at' => now()]);
    expect(fn () => app(MerchandiseInventory::class)->consumeOrder($order))->toThrow(ValidationException::class);
    expect($variant->fresh()->on_hand)->toBe(1);
});

it('consumes an accepted zero order once with a system order audit source', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make(1);
    $variant->update(['price_pesewas' => 0]);
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $order->update(['accepted_at' => now(), 'payment_state' => 'not_required', 'ledger_state' => 'not_applicable']);
    app(PurchaseReservations::class)->consume($order);
    app(PurchaseReservations::class)->consume($order);
    $movement = StoreStockMovement::query()->where('source_id', $order->id)->firstOrFail();
    expect($variant->fresh()->on_hand)->toBe(0)->and($movement->actor_id)->toBeNull()
        ->and($movement->source_kind)->toBe('order')->and($movement->delta)->toBe(-1)
        ->and(StoreStockMovement::query()->where('source_id', $order->id)->count())->toBe(1);
});

it('admits an active no-dues email member but not a follower or lapsed membership to private merchandise', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $variant->merchandise->update(['visibility' => 'members']);
    expect(fn () => StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid()))->toThrow(ValidationException::class);
    $member = App\Models\Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'buyer@example.test', 'status' => App\Enum\MemberStatusEnum::Active, 'membership_type_id' => App\Models\MembershipType::factory()->create(['tenant_id' => $tenant->id, 'amount' => null, 'period' => null])->id]);
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    expect($order->principal_pesewas)->toBe(10000);
    $member->update(['status' => App\Enum\MemberStatusEnum::Lapsed]);
    expect(fn () => StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid()))->toThrow(ValidationException::class);
});

it('excludes inventory corrections on a second connection while a quote holds the canonical lock', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make(1);
    StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $other = app(Illuminate\Database\DatabaseManager::class)->build(config('database.connections.landlord'));
    try {
        $lock = $other->selectOne('select pg_try_advisory_xact_lock(hashtextextended(?, 0)) as acquired', ['commerce.inventory:'.$variant->id]);
        expect($lock->acquired)->toBeFalse();
    } finally {
        $other->disconnect();
    }
});

it('rejects duplicate variants and carts exceeding twenty lines', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $line = ['variant_id' => $variant->id, 'quantity' => 1];
    foreach ([[$line, $line], array_fill(0, 21, $line)] as $lines) {
        expect(fn () => StoreCheckoutScenario::quote($tenant, $session, $lines, (string) Str::uuid()))->toThrow(ValidationException::class);
    }
    expect(StoreOrder::query()->count())->toBe(0);
});

it('rejects another sellers variant without exposing its product', function (): void {
    [$tenant, , , $session] = StoreCheckoutScenario::make();
    $foreign = App\Models\StoreVariant::factory()->create();
    expect(fn () => StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $foreign->id, 'quantity' => 1]], (string) Str::uuid()))->toThrow(ValidationException::class);
});

it('enforces allocation ownership at the database boundary', function (): void {
    $line = App\Models\StoreOrderLine::factory()->create();
    $foreign = App\Models\StoreVariant::factory()->create();
    expect(fn () => StoreStockAllocation::factory()->create(['line_id' => $line->id, 'variant_id' => $foreign->id]))->toThrow(Illuminate\Database\QueryException::class);
});

it('refuses total overflow without reserving stock or creating a quote', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $variant->update(['price_pesewas' => 9007199254740991]);
    expect(fn () => StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 2]], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(StoreOrder::query()->count())->toBe(0)->and(StoreStockAllocation::query()->count())->toBe(0);
});

it('does not extend a live identical quote when the browser supplies a new request key', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $cart = [['variant_id' => $variant->id, 'quantity' => 1]];
    $first = StoreCheckoutScenario::quote($tenant, $session, $cart, (string) Str::uuid());
    $this->travel(2)->minutes();
    $retry = StoreCheckoutScenario::quote($tenant, $session, $cart, (string) Str::uuid());
    expect($retry->id)->toBe($first->id)->and($retry->expires_at->equalTo($first->expires_at))->toBeTrue();
});

it('reports no public stock while its final unit is held', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make(1);
    StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $listing = App\Models\StoreListing::withoutGlobalScope(App\Scopes\MerchandiseVisibilityScope::class)->findOrFail($variant->listing_id);
    $data = app(App\Services\Marketplace\MerchandisePayload::class)->forViewer($listing);
    expect($data['variants'][0]['available'])->toBeFalse();
});
