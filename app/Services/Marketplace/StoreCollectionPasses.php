<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreCollectionPass;
use App\Models\StoreFulfilmentArrangement;
use App\Models\StoreFulfilmentEvent;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Events\QrCodeGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class StoreCollectionPasses
{
    /** @return array{pass_id:string,qr_svg:string,manual_code:string,expires_at:string} */
    public function issue(StoreOrder $order, Session $session, string $operationKey): array
    {
        return $order->getConnection()->transaction(function () use ($order, $session, $operationKey): array {
            $order = app(CommerceInventoryLocks::class)->order($order);
            if (! app(StoreOrderAccess::class)->buyer($order, $session)) {
                throw new AuthorizationException;
            }
            $this->ready($order);
            if (! Str::isUuid($operationKey)) {
                $this->refuse();
            }
            $prior = StoreCollectionPass::query()->where('order_id', $order->id)->where('operation_key', $operationKey)->first();
            if ($prior !== null) {
                if (! $this->active($prior, $order) || $prior->secret_material === null) {
                    $this->refuse();
                }

                return $this->response($prior);
            }
            $limiter = $this->key('issue', [$order->tenant_id, $order->id, $order->buyer_email]);
            if (RateLimiter::tooManyAttempts($limiter, 5)) {
                $this->refuse();
            }
            RateLimiter::hit($limiter, 600);
            $this->revoke($order, 'replaced');
            $method = $this->method($order);
            $qr = bin2hex(random_bytes(32));
            $manual = '';
            $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
            for ($i = 0; $i < 12; $i++) {
                $manual .= $alphabet[random_int(0, 31)];
            }
            $pass = StoreCollectionPass::query()->create(['order_id' => $order->id, 'shop_id' => $order->shop_id, 'tenant_id' => $order->tenant_id,
                'event_id' => $this->generation($order, $method), 'method' => $method, 'operation_key' => $operationKey, 'issued_to' => $order->buyer_email,
                'qr_hash' => hash('sha256', $qr), 'manual_hash' => hash('sha256', $manual), 'secret_material' => ['qr' => $qr, 'manual' => $manual], 'expires_at' => now()->addMinutes(15)]);

            return $this->response($pass);
        });
    }

    /** @param array<string,mixed> $credential */
    public function validate(StoreOrder $order, User $actor, array $credential): StoreCollectionPass
    {
        $order = $order->fresh() ?? throw new AuthorizationException;
        app(StoreFulfilment::class)->authorize($order, $actor);
        $broad = $this->key('staff', [$order->tenant_id, (string) $actor->id]);
        $failed = $this->key('fail', [$order->tenant_id, $order->id, (string) $actor->id]);
        if (RateLimiter::tooManyAttempts($broad, 30) || RateLimiter::tooManyAttempts($failed, 5)) {
            $this->refuse();
        }
        RateLimiter::hit($broad, 60);
        $this->ready($order);
        if ($this->decode($credential, $this->method($order)) === null) {
            RateLimiter::hit($failed, 600);
            $this->refuse();
        }
        $pass = StoreCollectionPass::query()->where('order_id', $order->id)->whereNull('revoked_at')->whereNull('consumed_at')->first();
        if ($pass === null || ! $this->matches($pass, $credential) || ! $this->active($pass, $order)) {
            RateLimiter::hit($failed, 600);
            $this->refuse();
        }

        return $pass;
    }

    /** Hash-only comparison grants no ownership or lifecycle access.
     * @param  array<string,mixed>  $credential
     */
    public function matches(StoreCollectionPass $pass, array $credential): bool
    {
        $decoded = $this->decode($credential, $pass->method);

        return $decoded !== null && ($decoded['pass_id'] === null || $decoded['pass_id'] === $pass->id)
            && hash_equals($decoded['kind'] === 'manual' ? $pass->manual_hash : $pass->qr_hash, $decoded['hash']);
    }

    public function revoke(StoreOrder $order, string $reason): void
    {
        if ($order->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Pass revocation requires canonical locks.');
        }
        StoreCollectionPass::query()->where('order_id', $order->id)->whereNull('revoked_at')->whereNull('consumed_at')
            ->update(['revoked_at' => now(), 'revocation_reason' => $reason, 'secret_material' => null]);
    }

    public function purge(): void
    {
        StoreCollectionPass::query()->whereNotNull('secret_material')->where(fn ($query) => $query->where('expires_at', '<=', now())->orWhereNotNull('revoked_at')->orWhereNotNull('consumed_at'))
            ->update(['secret_material' => null]);
    }

    /** @param array<string,mixed> $credential
     * @return array{kind:string,pass_id:?string,hash:string}|null
     */
    private function decode(array $credential, string $method): ?array
    {
        $encoded = json_encode($credential);
        if ($encoded === false || mb_strlen($encoded) > 2048) {
            return null;
        }
        if (count($credential) === 1 && is_string($credential['manual_code'] ?? null)) {
            $code = strtr(mb_strtoupper(str_replace([' ', '-'], '', $credential['manual_code'])), ['O' => '0', 'I' => '1', 'L' => '1']);

            return preg_match('/^[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{12}$/D', $code) === 1 ? ['kind' => 'manual', 'pass_id' => null, 'hash' => hash('sha256', $code)] : null;
        }
        if (count($credential) !== 3 || ($credential['purpose'] ?? null) !== $this->purpose($method)
            || ! is_string($credential['pass_id'] ?? null) || ! Str::isUuid($credential['pass_id'])
            || ! is_string($credential['secret'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $credential['secret']) !== 1) {
            return null;
        }

        return ['kind' => 'qr', 'pass_id' => $credential['pass_id'], 'hash' => hash('sha256', $credential['secret'])];
    }

    private function ready(StoreOrder $order): void
    {
        $service = app(StoreFulfilment::class);
        $method = $this->method($order);
        if (! $service->enabled($order) || ! $service->eligible($order) || $order->fulfilment_state !== ($method === 'delivery' ? 'dispatched' : 'ready')) {
            $this->refuse();
        }
    }

    private function method(StoreOrder $order): string
    {
        $method = StoreFulfilmentArrangement::query()->where('order_id', $order->id)->value('method');
        if (! in_array($method, ['collection', 'delivery'], true)) {
            $this->refuse();
        }

        return $method;
    }

    private function generation(StoreOrder $order, string $method): string
    {
        $id = StoreFulfilmentEvent::query()->where('order_id', $order->id)->where('kind', $method === 'delivery' ? 'dispatch' : 'ready')->orderByDesc('sequence')->value('id');
        if (! is_string($id)) {
            $this->refuse();
        }

        return $id;
    }

    private function active(StoreCollectionPass $pass, StoreOrder $order): bool
    {
        return $pass->revoked_at === null && $pass->consumed_at === null && $pass->expires_at->isFuture()
            && $pass->method === $this->method($order) && $pass->event_id === $this->generation($order, $pass->method);
    }

    private function purpose(string $method): string
    {
        return 'miconvener.store.'.($method === 'delivery' ? 'delivery' : 'collection').'.v1';
    }

    /** @return array{pass_id:string,qr_svg:string,manual_code:string,expires_at:string} */
    private function response(StoreCollectionPass $pass): array
    {
        $material = $pass->secret_material;
        if ($material === null) {
            $this->refuse();
        }

        return ['pass_id' => $pass->id, 'qr_svg' => QrCodeGenerator::svgDataUri(json_encode(['purpose' => $this->purpose($pass->method), 'pass_id' => $pass->id, 'secret' => $material['qr']], JSON_THROW_ON_ERROR)),
            'manual_code' => implode('-', mb_str_split($material['manual'], 4)), 'expires_at' => $pass->expires_at->toIso8601String()];
    }

    /** @param list<string> $parts */
    private function key(string $purpose, array $parts): string
    {
        return 'store-handover:'.$purpose.':'.hash_hmac('sha256', implode('|', $parts), (string) config('app.key'));
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['credential' => 'Pass unavailable, expired or replaced. Refresh the order and request a new pass when ready to hand over.']);
    }
}
