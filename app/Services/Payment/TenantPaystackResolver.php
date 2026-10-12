<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\CommissionSplitUnavailableException;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Finance\CommissionCredit;

/**
 * Chooses the Paystack account a tenant's charge is created on: the platform's
 * for platform-default tenants, the tenant's own otherwise. For an own account
 * the charge is split so the platform's commission is routed to the platform
 * at payment time, which is the only point the platform can enforce it.
 */
final class TenantPaystackResolver
{
    public function __construct(
        private readonly CommissionSplitVerifier $verifier,
        private readonly CommissionCredit $credit,
    ) {}

    /**
     * The gateway to create a charge on, or null when none is configured.
     *
     * @param  int  $platformShare  The commission, in minor units, that is due to the platform from this charge. Credit the platform owes the organizer is netted against it, and only the rest is routed to the platform.
     *
     * @throws CommissionSplitUnavailableException
     */
    public function gatewayFor(Tenant $tenant, int $platformShare, string $currency = 'GHS'): ?PaystackGateway
    {
        if ($tenant->isPlatformDefaultSettlement()) {
            return $this->platformGateway();
        }

        $row = $this->ownGateway($tenant);

        if ($row === null) {
            return null;
        }

        $gateway = new PaystackGateway(['secret_key' => $row->api_key_encrypted]);

        if ($platformShare <= 0 || ! $this->verifier->isEnforced()) {
            return $gateway;
        }

        $creditApplied = min($this->credit->balance($tenant, $currency), $platformShare);
        $gateway = $gateway->withCreditApplied($creditApplied);
        $platformShare -= $creditApplied;

        // Credit covered the whole commission, so there is nothing to route.
        if ($platformShare <= 0) {
            return $gateway;
        }

        $code = $row->commissionSubaccountCode();

        if ($code === null) {
            throw new CommissionSplitUnavailableException('Online payment is unavailable for this event until the organizer finishes setting up payments.');
        }

        $check = $this->verifier->checkAndRecord($row, $code);

        if ($check->inconclusive) {
            throw new CommissionSplitUnavailableException('Payment could not be started. Please try again in a moment.');
        }

        if (! $check->ok) {
            throw new CommissionSplitUnavailableException('Online payment is unavailable for this event until the organizer fixes their payment setup.');
        }

        return $gateway->withSplit($code, $platformShare);
    }

    /**
     * Whether online Paystack payment can be offered, judged from what is
     * stored so that rendering a page never calls Paystack. The live check
     * runs when a payment is actually started.
     */
    public function isAvailableFor(Tenant $tenant): bool
    {
        if ($tenant->isPlatformDefaultSettlement()) {
            return $this->platformGateway() !== null;
        }

        $row = $this->ownGateway($tenant);

        if ($row === null) {
            return false;
        }

        return ! $this->verifier->isEnforced() || $row->commissionSplitIsVerified();
    }

    public function ownGateway(Tenant $tenant): ?TenantPaymentGateway
    {
        return TenantPaymentGateway::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();
    }

    private function platformGateway(): ?PaystackGateway
    {
        $secret = config('services.settlement.paystack.secret_key');

        return $secret ? new PaystackGateway(['secret_key' => $secret]) : null;
    }
}
