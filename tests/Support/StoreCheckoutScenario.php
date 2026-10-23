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

    public static function session(string $email = 'buyer@example.test'): Store
    {
        $session = new Store('store-test', new ArraySessionHandler(120));
        $session->put(proofFor($email));

        return $session;
    }
}
