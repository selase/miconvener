<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Http\Requests\Admin\StoreCommercialAgreementRequest;
use App\Models\StoreCommercialAgreement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Giving\GivingGateways;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class StoreCommercialTerms
{
    /**
     * The seller's current agreement for one scope. Purchase is the default so
     * merchandise callers are unaffected by venue reservation agreements.
     */
    public function resolve(Tenant $seller, string $scope = StoreCommercialAgreement::SCOPE_PURCHASE): StoreCommercialAgreement
    {
        $this->assertScope($scope);
        $current = Tenant::query()->findOrFail($seller->id);
        $agreement = StoreCommercialAgreement::query()->where('tenant_id', $current->id)->where('offering_scope', $scope)->where('approved_at', '<=', now())->orderByDesc('version')->first();
        if ($agreement === null || ! $current->isPlatformDefaultSettlement() || ! filled(config('services.settlement.paystack.secret_key'))
            || $agreement->shop_id !== $current->shop?->id || $agreement->currency !== 'GHS'
            || ! hash_equals($agreement->account_fingerprint, hash('sha256', app(GivingGateways::class)->platformIdentity()))) {
            throw ValidationException::withMessages(['checkout' => 'This store needs an approved commercial agreement and supported payment setup.']);
        }

        return $agreement;
    }

    /** @param array<string, mixed> $input */
    public function approve(Tenant $seller, User $actor, array $input, string $scope = StoreCommercialAgreement::SCOPE_PURCHASE): StoreCommercialAgreement
    {
        $this->assertScope($scope);
        Gate::forUser($actor)->authorize('access-superadmin-dashboard');
        $request = new StoreCommercialAgreementRequest;
        $data = Validator::make($input, $request->rules(), $request->messages())->validate();

        return $seller->getConnection()->transaction(function () use ($seller, $actor, $data, $scope): StoreCommercialAgreement {
            $current = Tenant::query()->lockForUpdate()->findOrFail($seller->id);
            $shop = $current->shop;
            if ($shop === null || ! $current->isPlatformDefaultSettlement() || ! filled(config('services.settlement.paystack.secret_key'))) {
                throw ValidationException::withMessages(['agreement' => 'A store and platform-default Paystack setup are required.']);
            }

            return StoreCommercialAgreement::query()->create([
                ...$data, 'tenant_id' => $current->id, 'shop_id' => $shop->id, 'offering_scope' => $scope,
                'version' => ((int) StoreCommercialAgreement::query()->where('shop_id', $shop->id)->max('version')) + 1,
                'provider' => 'paystack', 'settlement_mode' => Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT, 'currency' => 'GHS',
                'account_fingerprint' => hash('sha256', app(GivingGateways::class)->platformIdentity()),
                'approved_by' => $actor->id, 'approved_at' => now(),
            ]);
        });
    }

    private function assertScope(string $scope): void
    {
        if (! in_array($scope, [StoreCommercialAgreement::SCOPE_PURCHASE, StoreCommercialAgreement::SCOPE_RESERVATION], true)) {
            throw new InvalidArgumentException("Unknown commercial agreement scope [{$scope}].");
        }
    }
}
