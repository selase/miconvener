<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\GivingFund;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

final class GivingScenario
{
    /** @param array<string, mixed> $fundAttributes
     * @return array{Tenant, User, GivingFund}
     */
    public static function make(array $fundAttributes = []): array
    {
        Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
        Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
        $tenant = Tenant::factory()->create();
        $tenant->features()->create(['feature_key' => 'community', 'enabled' => true]);
        $tenant->features()->create(['feature_key' => 'community_giving', 'enabled' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        setPermissionsTeamId($tenant->id);
        $tenant->users()->attach($user->id);
        $user->givePermissionTo(['manage giving', 'read finance']);
        $fund = GivingFund::factory()->create(['tenant_id' => $tenant->id, 'state' => 'open', ...$fundAttributes]);

        return [$tenant, $user, $fund];
    }

    /** @return array{Tenant, User, GivingFund, \App\Models\GivingPaymentAttempt} */
    public static function online(bool $own = false): array
    {
        [$tenant, $user, $fund] = self::make(['visibility' => 'public']);
        $tenant->update(['package_id' => \App\Models\Package::factory()->create()->id, 'settlement_mode' => $own ? Tenant::SETTLEMENT_MODE_OWN_GATEWAY : Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT]);
        if ($own) {
            \App\Models\TenantPaymentGateway::factory()->create(['tenant_id' => $tenant->id, 'provider' => 'paystack', 'api_key_encrypted' => 'sk_test_own_giving', 'is_active' => true]);
        }
        $tenant->features()->where('feature_key', 'community_giving')->update(['meta' => ['online_enabled' => true]]);
        config(['services.settlement.paystack.secret_key' => 'sk_test_giving', 'services.commission_split.enforced' => false]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake([
            '*api.paystack.co/customer*' => \Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_GIVING', 'email' => 'giver@example.test']]),
            '*api.paystack.co/transaction/initialize' => \Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/giving-test']]),
        ]);
        app(\App\Services\Giving\GivingCheckout::class)->start($fund, ['amount_minor' => 10000, 'currency' => 'GHS', 'payer_name' => 'Guest', 'payer_email' => 'giver@example.test', 'provider' => 'paystack'], app('session.store'), (string) \Illuminate\Support\Str::uuid());
        $attempt = \App\Models\GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        return [$tenant, $user, $fund, $attempt];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function verified(\App\Models\GivingPaymentAttempt $attempt, array $overrides = []): array
    {
        return array_replace_recursive([
            'status' => 'success', 'provider' => 'paystack', 'reference' => $attempt->reference,
            'provider_reference' => $attempt->reference, 'account_identity' => $attempt->account_identity,
            'amount' => 10000, 'currency' => 'GHS', 'fees' => 200,
            'metadata' => ['type' => 'community_gift', 'giving_attempt_id' => $attempt->id, 'tenant_id' => $attempt->tenant_id,
                'fund_id' => $attempt->fund_id, 'source_type' => 'gift', 'source_id' => $attempt->gift_id,
                'local_reference' => $attempt->reference, 'source' => config('services.paystack.metadata_source')],
        ], $overrides);
    }
}
