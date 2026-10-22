<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreStockMovement;
use App\Models\StoreVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MerchandiseInventory
{
    public function adjust(StoreVariant $variant, int $delta, string $reason, string $idempotencyKey, User $actor): StoreStockMovement
    {
        $reason = mb_trim($reason);
        if ($delta === 0 || $delta < -2147483647 || $delta > 2147483647 || $reason === '' || mb_strlen($reason) > 500 || ! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['adjustment' => 'Provide a non-zero quantity, a reason and a valid adjustment key.']);
        }

        return $variant->getConnection()->transaction(function () use ($variant, $delta, $reason, $idempotencyKey, $actor): StoreStockMovement {
            $variant->getConnection()->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['commerce.inventory:'.$variant->id]);
            $locked = StoreVariant::query()->with('shop')->lockForUpdate()->findOrFail($variant->id);
            if ($locked->shop === null || (! User::query()->whereKey($actor->id)->where('tenant_id', $locked->shop->tenant_id)->exists() && ! $actor->tenants()->whereKey($locked->shop->tenant_id)->exists())) {
                throw new AuthorizationException;
            }
            $team = getPermissionsTeamId();
            try {
                setPermissionsTeamId($locked->shop->tenant_id);
                $recordedActor = $actor->fresh();
                Gate::forUser($recordedActor)->authorize('manage store catalog');
            } finally {
                setPermissionsTeamId($team);
            }
            $prior = $locked->movements()->where('idempotency_key', $idempotencyKey)->first();
            if ($prior !== null) {
                if ($prior->delta !== $delta || $prior->reason !== $reason || $prior->actor_id !== $actor->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This adjustment key was already used for a different correction.']);
                }

                return $prior;
            }
            $balance = $locked->on_hand + $delta;
            if ($balance < 0 || $balance > 2147483647) {
                throw ValidationException::withMessages(['delta' => 'The correction would put stock outside its allowed range.']);
            }
            StoreVariant::query()->whereKey($locked->id)->update(['on_hand' => $balance]);

            return $locked->movements()->create([
                'shop_id' => $locked->shop_id, 'delta' => $delta, 'balance_after' => $balance,
                'reason' => $reason, 'actor_id' => $actor->id, 'actor_name' => $recordedActor->name, 'idempotency_key' => $idempotencyKey,
            ]);
        });
    }
}
