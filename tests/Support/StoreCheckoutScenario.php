<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Permission;
use App\Models\StoreCommercialAgreement;
use App\Models\StoreVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Giving\GivingGateways;
use App\Services\Marketplace\MerchandiseInventory;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

final class StoreCheckoutScenario
{
    /** @return array{Tenant, StoreVariant, User, Store} */
    public static function make(int $stock = 3): array
    {
        config(['services.settlement.paystack.secret_key' => 'sk_test_store_only']);
        $variant = StoreVariant::factory()->create(['price_pesewas' => 10000]);
        $tenant = $variant->shop->tenant;
        $tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
        foreach (['commerce_catalog', 'commerce_checkout'] as $feature) {
            $tenant->features()->create(['feature_key' => $feature, 'enabled' => true]);
        }
        $variant->merchandise->listing()->withoutGlobalScopes()->update(['status' => 'published']);
        StoreCommercialAgreement::factory()->create(['shop_id' => $variant->shop_id, 'tenant_id' => $tenant->id,
            'account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity())]);
        $actor = User::factory()->create(['tenant_id' => $tenant->id]);
        setPermissionsTeamId($tenant->id);
        Permission::query()->firstOrCreate(['name' => 'manage store catalog', 'guard_name' => 'web'], ['category' => 'store']);
        $actor->givePermissionTo('manage store catalog');
        if ($stock > 0) {
            app(MerchandiseInventory::class)->adjust($variant, $stock, 'Opening stock', (string) Str::uuid(), $actor);
        }

        return [$tenant->fresh(), $variant->fresh(), $actor, self::session()];
    }

    /**
     * Explicit fixture buyer acceptance for legacy checkout regression scenarios.
     *
     * @param  array<array-key,mixed>  $lines
     */
    public static function quote(Tenant $tenant, \Illuminate\Contracts\Session\Session $session, array $lines, string $key): \App\Models\StoreOrder
    {
        $order = app(\App\Services\Marketplace\StoreQuotes::class)->create($tenant, $session, $lines, $key);
        if (! \App\Models\StoreFulfilmentArrangement::query()->where('order_id', $order->id)->exists()) {
            $policy = \App\Models\StoreFulfilmentPolicy::query()->where('shop_id', $order->shop_id)->orderByDesc('version')->first()
                ?? \App\Models\StoreFulfilmentPolicy::factory()->create(['shop_id' => $order->shop_id, 'tenant_id' => $order->tenant_id]);
            app(\App\Services\Marketplace\StoreFulfilmentOptions::class)->choose($order, $session,
                ['policy_id' => $policy->id, 'method' => 'collection'], (string) Str::uuid());
        }

        return $order;
    }

    /** @return array{Tenant, StoreVariant, User, Store, \App\Models\StoreOrder, \App\Models\StorePaymentAttempt, \App\Models\StorePaymentObservation} */
    public static function collectionReady(): array
    {
        $data = self::paid();
        [$tenant,,$actor,,$order] = $data;
        $tenant->features()->create(['feature_key' => 'commerce_collection', 'enabled' => true]);
        setPermissionsTeamId($tenant->id);
        Permission::query()->firstOrCreate(['name' => 'manage store collection', 'guard_name' => 'web'], ['category' => 'store']);
        $actor->givePermissionTo('manage store collection');
        app(\App\Services\Marketplace\StoreFulfilment::class)->ready($order, $actor, (string) Str::uuid());

        return $data;
    }

    /** @return array{Tenant, StoreVariant, User, Store, \App\Models\StoreOrder, \App\Models\StorePaymentAttempt} */
    public static function online(): array
    {
        [$tenant, $variant, $actor, $session] = self::make();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transaction/initialize' => \Illuminate\Support\Facades\Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/store-test']])]);
        $order = self::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
        $attempt = app(\App\Services\Marketplace\StorePayments::class)->start($order, $session, (string) Str::uuid());

        return [$tenant, $variant, $actor, $session, $order, $attempt];
    }

    /** @return array{Tenant, StoreVariant, User, Store, \App\Models\StoreOrder, \App\Models\StorePaymentAttempt, \App\Models\StorePaymentObservation} */
    public static function paid(): array
    {
        [$tenant, $variant, $actor, $session, $order, $attempt] = self::online();
        $capture = app(\App\Services\Marketplace\StorePaymentEvidence::class)->record($attempt, self::evidence($attempt));
        app(\App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($capture);

        return [$tenant, $variant, $actor, $session, $order->fresh(), $attempt->fresh(), $capture->fresh()];
    }

    /** @return array{\App\Models\StoreOrder, \App\Models\StorePaymentAttempt, \App\Models\StorePaymentObservation} */
    public static function anotherPaid(Tenant $tenant, StoreVariant $variant, Store $session): array
    {
        $order = self::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
        $attempt = app(\App\Services\Marketplace\StorePayments::class)->start($order, $session, (string) Str::uuid());
        $capture = app(\App\Services\Marketplace\StorePaymentEvidence::class)->record($attempt, self::evidence($attempt));
        app(\App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($capture);

        return [$order->fresh(), $attempt->fresh(), $capture->fresh()];
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public static function evidence(\App\Models\StorePaymentAttempt $attempt, array $changes = []): array
    {
        return array_replace(['status' => 'success', 'provider' => 'paystack', 'reference' => $attempt->reference,
            'reference_reported' => true, 'account_fingerprint' => $attempt->account_fingerprint,
            'amount' => 10000, 'currency' => 'GHS', 'fees' => 195, 'paid_at' => now()->toIso8601String(),
            'transaction_id' => 987, 'metadata' => ['source' => 'miconvener_store', 'store_order_id' => $attempt->order_id, 'store_attempt_id' => $attempt->id, 'seller_id' => $attempt->tenant_id]], $changes);
    }

    public static function session(string $email = 'buyer@example.test'): Store
    {
        $session = new Store('store-test', new ArraySessionHandler(120));
        $session->put(proofFor($email));

        return $session;
    }
}
