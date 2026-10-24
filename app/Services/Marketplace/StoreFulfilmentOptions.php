<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\Shop;
use App\Models\StoreFulfilmentArrangement;
use App\Models\StoreFulfilmentPolicy;
use App\Models\StoreOrder;
use App\Models\StoreVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StoreFulfilmentOptions
{
    /** @return array<string,mixed> */
    public static function policyRules(): array
    {
        return ['operation_key' => ['required', 'uuid'], 'policy' => ['required', 'array:methods,collection_location,collection_hours,collection_instructions,delivery_countries,delivery_instructions,delivery_timing'],
            'policy.methods' => ['required', 'array', 'min:1', 'max:2'], 'policy.methods.*' => ['required', Rule::in(['collection', 'delivery']), 'distinct'],
            'policy.collection_location' => ['required_if:policy.methods.*,collection', 'nullable', 'string', 'max:1000'],
            'policy.collection_hours' => ['nullable', 'string', 'max:500'], 'policy.collection_instructions' => ['nullable', 'string', 'max:2000'],
            'policy.delivery_countries' => ['required_if:policy.methods.*,delivery', 'array', 'min:1', 'max:250'],
            'policy.delivery_countries.*' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/D', 'distinct'],
            'policy.delivery_instructions' => ['nullable', 'string', 'max:2000'], 'policy.delivery_timing' => ['nullable', 'string', 'max:500']];
    }

    /** @return array<string,mixed> */
    public static function choiceRules(): array
    {
        return ['operation_key' => ['required', 'uuid'], 'choice' => ['required', 'array:policy_id,method,destination'],
            'choice.policy_id' => ['required', 'uuid'], 'choice.method' => ['required', Rule::in(['collection', 'delivery'])],
            'choice.destination' => ['required_if:choice.method,delivery', 'prohibited_if:choice.method,collection', 'array:recipient,address_line_1,address_line_2,locality,country,postal_code,landmark,instructions,phone'],
            'choice.destination.recipient' => ['required_with:choice.destination', 'string', 'max:255'],
            'choice.destination.address_line_1' => ['required_with:choice.destination', 'string', 'max:500'],
            'choice.destination.address_line_2' => ['nullable', 'string', 'max:500'],
            'choice.destination.locality' => ['required_with:choice.destination', 'string', 'max:255'],
            'choice.destination.country' => ['required_with:choice.destination', 'string', 'size:2', 'regex:/^[A-Z]{2}$/D'],
            'choice.destination.postal_code' => ['nullable', 'string', 'max:30'], 'choice.destination.landmark' => ['nullable', 'string', 'max:500'],
            'choice.destination.instructions' => ['nullable', 'string', 'max:2000'], 'choice.destination.phone' => ['nullable', 'string', 'max:40']];
    }

    /** @param array<string,mixed> $policy */
    public function offer(Shop $shop, User $actor, array $policy, string $operationKey): StoreFulfilmentPolicy
    {
        return $shop->getConnection()->transaction(function () use ($shop, $actor, $policy, $operationKey): StoreFulfilmentPolicy {
            $tenant = app(CommerceInventoryLocks::class)->tenant($shop->tenant_id);
            app(MerchandiseCatalog::class)->authorize($tenant, $actor);
            $data = Validator::make(['policy' => $policy, 'operation_key' => $operationKey], self::policyRules())->validate()['policy'];
            if ((in_array('collection', $data['methods'], true) && blank($data['collection_location'] ?? null))
                || (in_array('delivery', $data['methods'], true) && empty($data['delivery_countries']))) {
                $this->refuse();
            }
            sort($data['methods']);
            if (isset($data['delivery_countries'])) {
                sort($data['delivery_countries']);
            }
            $fingerprint = self::fingerprint($data);
            $prior = StoreFulfilmentPolicy::query()->where('shop_id', $shop->id)->where('operation_key', $operationKey)->first();
            if ($prior !== null) {
                if (! hash_equals($prior->fingerprint, $fingerprint)) {
                    $this->refuse();
                }

                return $prior;
            }

            return StoreFulfilmentPolicy::query()->create(['shop_id' => $shop->id, 'tenant_id' => $tenant->id,
                'version' => 1 + (int) StoreFulfilmentPolicy::query()->where('shop_id', $shop->id)->max('version'),
                'operation_key' => $operationKey, 'fingerprint' => $fingerprint, 'snapshot' => $data]);
        });
    }

    /** @param list<StoreVariant> $variants
     * @return array<string,mixed>
     */
    public function options(array $variants): array
    {
        if ($variants === [] || count(array_unique(array_map(fn (StoreVariant $v): string => $v->shop_id, $variants))) !== 1) {
            return ['methods' => []];
        }
        $policy = StoreFulfilmentPolicy::query()->where('shop_id', $variants[0]->shop_id)->orderByDesc('version')->first();

        return $policy === null ? ['methods' => []] : ['policy_id' => $policy->id, ...$policy->snapshot];
    }

    /** @param array<string,mixed> $choice */
    public function choose(StoreOrder $order, Session $session, array $choice, string $operationKey, ?string $replacesOrderId = null): StoreFulfilmentArrangement
    {
        return $order->getConnection()->transaction(function () use ($order, $session, $choice, $operationKey, $replacesOrderId): StoreFulfilmentArrangement {
            $order = app(CommerceInventoryLocks::class)->order($order);
            if (! app(StoreOrderAccess::class)->buyer($order, $session)) {
                throw new AuthorizationException;
            }
            Validator::make(['choice' => $choice, 'operation_key' => $operationKey], self::choiceRules())->validate();
            $fingerprint = self::fingerprint($choice);
            $replay = StoreFulfilmentArrangement::query()->where('replaces_order_id', $order->id)->where('operation_key', $operationKey)->first();
            if ($replay !== null) {
                if (! hash_equals($replay->fingerprint, $fingerprint)) {
                    $this->refuse();
                }

                return $replay;
            }
            $prior = StoreFulfilmentArrangement::query()->where('order_id', $order->id)->first();
            if ($prior !== null) {
                if ($prior->operation_key === $operationKey) {
                    if (! hash_equals($prior->fingerprint, $fingerprint)) {
                        $this->refuse();
                    }

                    return $prior;
                }
                if ($order->state !== 'quoted' || $order->attempts()->exists() || ! $order->expires_at->isFuture()) {
                    $this->refuse();
                }
                $replacement = $order->replicate();
                $replacement->submission_key = (string) \Illuminate\Support\Str::uuid();
                $replacement->save();
                foreach ($order->lines()->get() as $line) {
                    $copy = $line->replicate();
                    $copy->order_id = $replacement->id;
                    $copy->save();
                }
                app(PurchaseReservations::class)->release($order, 'replaced');
                $order->update(['state' => 'cancelled', 'fulfilment_state' => 'cancelled']);
                app(PurchaseReservations::class)->reserve($replacement);
                $arrangement = $this->choose($replacement, $session, $choice, $operationKey, $order->id);

                return $arrangement;

            }
            if (! in_array($order->state, ['quoted', 'confirmed'], true) || $order->fulfilment_state !== 'awaiting_preparation'
                || ($order->state === 'quoted' && ($order->attempts()->exists() || $order->expires_at->isPast()))) {
                $this->refuse();
            }
            $policy = StoreFulfilmentPolicy::query()->where('shop_id', $order->shop_id)->where('tenant_id', $order->tenant_id)->orderByDesc('version')->first();
            if ($policy === null || $policy->id !== $choice['policy_id'] || ! in_array($choice['method'], $policy->snapshot['methods'], true)) {
                $this->refuse();
            }
            $destination = $choice['destination'] ?? null;
            if ($choice['method'] === 'delivery' && ! in_array($destination['country'] ?? null, $policy->snapshot['delivery_countries'] ?? [], true)) {
                $this->refuse();
            }

            return StoreFulfilmentArrangement::query()->create(['order_id' => $order->id, 'shop_id' => $order->shop_id, 'tenant_id' => $order->tenant_id,
                'replaces_order_id' => $replacesOrderId, 'policy_id' => $policy->id, 'method' => $choice['method'], 'operation_key' => $operationKey, 'fingerprint' => $fingerprint,
                'policy_snapshot' => $policy->snapshot, 'destination' => $destination]);
        });
    }

    /** @param array<string,mixed> $data */
    private static function fingerprint(array $data): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn (mixed $part): mixed => is_array($part) ? $canonical($part) : $part, $value);
        };

        return hash_hmac('sha256', json_encode($canonical($data), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['fulfilment' => 'Choose an available seller option and verify the original buyer email. Accepted arrangements cannot be changed.']);
    }
}
