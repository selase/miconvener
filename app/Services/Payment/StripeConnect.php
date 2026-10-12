<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Stripe\StripeClient;
use Throwable;

/**
 * Links a tenant's own Stripe account to the platform with Connect (Standard
 * accounts, OAuth). The platform holds no key of theirs, only the connected
 * account's id, and can be disconnected by either side.
 */
final class StripeConnect
{
    private const STATE_TTL_MINUTES = 15;

    public function isConfigured(): bool
    {
        return filled(config('services.stripe.connect.client_id')) && filled(config('services.stripe.secret'));
    }

    public function connectedGateway(Tenant $tenant): ?TenantPaymentGateway
    {
        return TenantPaymentGateway::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', 'stripe')
            ->whereNotNull('provider_account_id')
            ->where('is_active', true)
            ->first();
    }

    /**
     * Stripe is offered only to tenants who take payments on their own
     * accounts, because a platform-default tenant's money is collected and
     * paid out by the platform.
     */
    public function isAvailableFor(Tenant $tenant): bool
    {
        return $this->isConfigured()
            && ! $tenant->isPlatformDefaultSettlement()
            && $this->connectedGateway($tenant) !== null;
    }

    public function gatewayFor(TenantPaymentGateway $row): StripeGateway
    {
        return new StripeGateway($this->stripe(), ['stripe_account' => $row->provider_account_id]);
    }

    /**
     * Where to send the tenant to authorize the link. The state is sealed and
     * single-use so the callback, which lands on the central domain without
     * the tenant's session, can still trust who started it.
     */
    public function authorizationUrl(Tenant $tenant, User $user): string
    {
        $nonce = Str::random(40);
        Cache::put($this->nonceKey($nonce), true, now()->addMinutes(self::STATE_TTL_MINUTES));

        $state = Crypt::encryptString(json_encode([
            'tenant' => $tenant->id,
            'user' => $user->id,
            'nonce' => $nonce,
        ], JSON_THROW_ON_ERROR));

        return $this->stripe()->oauth->authorizeUrl([
            'response_type' => 'code',
            'client_id' => config('services.stripe.connect.client_id'),
            'scope' => 'read_write',
            'redirect_uri' => route('stripe.connect.callback'),
            'state' => $state,
            'stripe_user[email]' => $user->email,
        ]);
    }

    /**
     * Reads the state back, returning the tenant it was issued for, or null if
     * it is forged, expired or already used.
     */
    public function tenantFromState(string $state): ?Tenant
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload) || ! isset($payload['nonce'], $payload['tenant'])) {
            return null;
        }

        if (! Cache::pull($this->nonceKey((string) $payload['nonce']))) {
            return null;
        }

        return Tenant::query()->find($payload['tenant']);
    }

    /**
     * Exchanges the authorization code for the connected account and stores
     * the link.
     */
    public function connect(Tenant $tenant, string $code): TenantPaymentGateway
    {
        $token = $this->stripe()->oauth->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        return TenantPaymentGateway::updateOrCreate(
            ['tenant_id' => $tenant->id, 'provider' => 'stripe'],
            [
                'provider_account_id' => $token['stripe_user_id'],
                'api_key_encrypted' => null,
                'public_key_encrypted' => null,
                'webhook_secret_encrypted' => null,
                'is_active' => true,
                'meta' => ['livemode' => (bool) $token['livemode']],
            ],
        );
    }

    /**
     * Revokes the platform's access on Stripe's side, then forgets the link.
     * If Stripe says the link is already gone, the local record still goes.
     */
    public function disconnect(TenantPaymentGateway $row): void
    {
        try {
            $this->stripe()->oauth->deauthorize([
                'client_id' => config('services.stripe.connect.client_id'),
                'stripe_user_id' => $row->provider_account_id,
            ]);
        } catch (Throwable) {
            // Already revoked from the Stripe dashboard.
        }

        $row->delete();
    }

    /**
     * Built on first use: the client refuses an empty key, and most
     * deployments and tests have no Stripe credentials at all.
     */
    private function stripe(): StripeClient
    {
        return app(StripeClient::class);
    }

    private function nonceKey(string $nonce): string
    {
        return "stripe-connect-state:{$nonce}";
    }
}
