<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreCollection;
use App\Models\StoreCollectionPass;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StoreCollections
{
    /** @param array<string,mixed> $credential */
    public function confirm(StoreOrder $order, User $actor, array $credential, string $operationKey): StoreCollection
    {
        return $order->getConnection()->transaction(function () use ($order, $actor, $credential, $operationKey): StoreCollection {
            $order = app(CommerceInventoryLocks::class)->order($order);
            $fulfilment = app(StoreFulfilment::class);
            $fulfilment->authorize($order, $actor, false);
            if (! Str::isUuid($operationKey)) {
                $fulfilment->refuse();
            }
            $passes = app(StoreCollectionPasses::class);
            $prior = StoreCollection::query()->where('order_id', $order->id)->first();
            if ($prior !== null) {
                $pass = StoreCollectionPass::query()->where('order_id', $order->id)->findOrFail($prior->pass_id);
                $passes->verifyCompleted($order, $actor, $pass, $credential);
                if ($prior->operation_key === $operationKey && (string) $prior->actor_id === (string) $actor->id) {
                    return $prior;
                }
                throw ValidationException::withMessages(['fulfilment' => $prior->method === 'delivery' ? 'Already delivered. Check the handover history.' : 'Already collected. Check the handover history.']);
            }
            $fulfilment->authorize($order, $actor);
            $pass = $passes->validate($order, $actor, $credential);
            $record = StoreCollection::query()->create(['order_id' => $order->id, 'tenant_id' => $order->tenant_id, 'shop_id' => $order->shop_id, 'pass_id' => $pass->id,
                'method' => $pass->method, 'operation_key' => $operationKey, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'source' => array_key_exists('manual_code', $credential) ? 'manual' : 'qr',
                'lines_snapshot' => $order->lines()->get()->map(fn ($line): array => ['id' => $line->id, 'variant_id' => $line->variant_id, 'title' => $line->title_snapshot, 'options' => $line->options_snapshot, 'quantity' => $line->quantity])->all(),
                'handed_over_at' => now()]);
            $pass->update(['consumed_at' => now(), 'secret_material' => null]);
            $order->update(['fulfilment_state' => $pass->method === 'delivery' ? 'delivered' : 'collected']);

            return $record;
        });
    }
}
