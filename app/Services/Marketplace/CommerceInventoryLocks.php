<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreOrder;
use App\Models\StoreVariant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

final class CommerceInventoryLocks
{
    public function tenant(string $tenantId): Tenant
    {
        $this->requireTransaction();

        return Tenant::query()->lockForUpdate()->findOrFail($tenantId);
    }

    public function order(StoreOrder $order): StoreOrder
    {
        $this->tenant($order->tenant_id);
        if (StoreOrder::query()->whereKey($order->id)->value('offering_type') === 'reservation') {
            throw new LogicException('Reservation orders are locked through CommerceOrderLocks.');
        }

        return StoreOrder::query()->where('tenant_id', $order->tenant_id)->lockForUpdate()->findOrFail($order->id);
    }

    /** @param list<string> $ids
     * @return Collection<int, StoreVariant>
     */
    public function variants(array $ids): Collection
    {
        $this->requireTransaction();
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        $db = (new StoreVariant)->getConnection();
        foreach ($ids as $id) {
            $db->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['commerce.inventory:'.$id]);
        }

        return StoreVariant::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
    }

    private function requireTransaction(): void
    {
        if ((new StoreOrder)->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Inventory locks require a transaction.');
        }
    }
}
