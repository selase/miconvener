<?php

declare(strict_types=1);

use App\Models\StoreOrder;
use App\Models\StoreOrderLine;
use App\Models\StoreVariant;
use Illuminate\Database\QueryException;

it('keeps quoted line prices and options after the catalog changes', function (): void {
    $line = StoreOrderLine::factory()->create(['unit_price_pesewas' => 1500, 'quantity' => 2, 'line_amount_pesewas' => 3000, 'options_snapshot' => ['Size' => 'M']]);
    $line->variant->update(['price_pesewas' => 2500, 'options' => ['Size' => 'XL']]);
    expect($line->fresh()->unit_price_pesewas)->toBe(1500)
        ->and($line->fresh()->options_snapshot)->toBe(['Size' => 'M'])
        ->and($line->order->shop_id)->toBe($line->shop_id);
});

it('rejects lines from a different seller in the database', function (): void {
    $order = StoreOrder::factory()->create();
    $variant = StoreVariant::factory()->create();
    StoreOrderLine::factory()->create(['order_id' => $order->id, 'shop_id' => $order->shop_id, 'variant_id' => $variant->id]);
})->throws(QueryException::class);

it('keeps order ownership price and terms immutable even for bulk writes', function (string $field, mixed $value): void {
    $order = StoreOrder::factory()->create();
    StoreOrder::query()->whereKey($order->id)->update([$field => $value]);
})->with([
    ['buyer_email', 'another@example.test'], ['principal_pesewas', 999], ['terms_snapshot', '{"changed":true}'],
])->throws(QueryException::class);

it('does not allow line snapshots to be rewritten or removed', function (string $operation): void {
    $line = StoreOrderLine::factory()->create();
    $query = StoreOrderLine::query()->whereKey($line->id);
    $operation === 'delete' ? $query->delete() : $query->update(['quantity' => 2]);
})->with(['update', 'delete'])->throws(QueryException::class);

it('retains catalog references and historical orders', function (): void {
    $line = StoreOrderLine::factory()->create();
    $line->variant->delete();
})->throws(QueryException::class);

it('keeps commercial agreements versioned and immutable', function (): void {
    $order = StoreOrder::factory()->create();
    $order->agreement->getConnection()->table('store_commercial_agreements')->where('id', $order->agreement_id)->update(['percentage' => '25.00']);
})->throws(QueryException::class);

it('allows lifecycle state changes without rewriting the quote', function (): void {
    $order = StoreOrder::factory()->create();
    $expiry = $order->expires_at;
    $order->update(['state' => 'awaiting_payment', 'payment_state' => 'awaiting']);
    expect($order->fresh()->state)->toBe('awaiting_payment')->and($order->fresh()->expires_at->equalTo($expiry))->toBeTrue();
});

it('does not let a line misattribute a variant to another listing of the same seller', function (): void {
    $line = StoreOrderLine::factory()->create();
    $other = App\Models\StoreListing::factory()->create(['shop_id' => $line->shop_id, 'listing_kind' => 'merchandise']);
    StoreOrderLine::factory()->create(['order_id' => $line->order_id, 'shop_id' => $line->shop_id, 'variant_id' => StoreVariant::factory()->create(['shop_id' => $line->shop_id, 'listing_id' => $line->variant->listing_id, 'options' => ['Colour' => 'Blue']])->id, 'listing_id' => $other->id]);
})->throws(QueryException::class);

it('does not allow bulk deletion of purchase history', function (): void {
    $order = StoreOrder::factory()->create();
    StoreOrder::query()->whereKey($order->id)->delete();
})->throws(QueryException::class);
