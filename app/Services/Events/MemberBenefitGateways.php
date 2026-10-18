<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventMemberBenefitAttempt;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Finance\CommissionCredit;
use App\Services\Giving\GivingGateways;
use App\Services\Payment\CommissionSplitVerifier;
use App\Services\Payment\PaystackGateway;
use App\Services\Payment\StripeConnect;
use App\Services\Payment\StripeGateway;
use Illuminate\Validation\ValidationException;

final class MemberBenefitGateways
{
    public function __construct(private readonly GivingGateways $accounts, private readonly StripeConnect $stripe, private readonly CommissionCredit $credit, private readonly CommissionSplitVerifier $splits) {}

    /** @return array{account_key:string,gateway_id:?string,settlement_mode:string} */
    public function identity(Tenant $tenant, string $provider, string $currency): array
    {
        $key = $this->accounts->identity($tenant, $provider, $currency);
        $row = $provider === 'stripe' || ! $tenant->isPlatformDefaultSettlement()
            ? TenantPaymentGateway::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('provider', $provider)->where('is_active', true)->first() : null;

        return ['account_key' => $key, 'gateway_id' => $row?->id, 'settlement_mode' => $provider === 'stripe' ? Tenant::SETTLEMENT_MODE_OWN_GATEWAY : $tenant->settlement_mode];
    }

    public function forAttempt(EventMemberBenefitAttempt $attempt): PaystackGateway|StripeGateway
    {
        if ($attempt->provider === 'paystack' && $attempt->settlement_mode === Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT) {
            if (! filled(config('services.settlement.paystack.secret_key')) || ! hash_equals($attempt->account_key, $this->accounts->platformIdentity())) {
                $this->refuse();
            }

            return new PaystackGateway(['secret_key' => config('services.settlement.paystack.secret_key')]);
        }
        $row = $this->originalRow($attempt);

        return $attempt->provider === 'stripe' ? $this->stripe->gatewayFor($row) : new PaystackGateway(['secret_key' => $row->api_key_encrypted]);
    }

    /** @return array{gateway:PaystackGateway|StripeGateway,settlement:array<string,mixed>} */
    public function prepare(EventMemberBenefitAttempt $attempt): array
    {
        $gateway = $this->forAttempt($attempt);
        $settlement = ['commission_credit_applied' => 0, 'commission_collected' => 0, 'commission_split' => null];
        if ($attempt->settlement_mode === Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT) {
            return ['gateway' => $gateway, 'settlement' => $settlement];
        }
        $credit = min($this->credit->balance($attempt->tenant, $attempt->currency), $attempt->platform_fee_amount);
        if ($gateway instanceof StripeGateway) {
            $settlement['commission_credit_applied'] = $credit;
            $settlement['commission_collected'] = $attempt->platform_fee_amount - $credit;
        } elseif ($attempt->platform_fee_amount > 0 && $this->splits->isEnforced()) {
            $gateway = $gateway->withCreditApplied($credit);
            $settlement['commission_credit_applied'] = $credit;
            if ($attempt->platform_fee_amount > $credit) {
                $row = $this->originalRow($attempt);
                $code = $row->commissionSubaccountCode();
                if ($code === null || ! $this->splits->checkAndRecord($row, $code)->ok) {
                    throw ValidationException::withMessages(['payment' => 'The organisation needs to complete its payment setup.']);
                }
                $gateway = $gateway->withSplit($code, $attempt->platform_fee_amount - $credit);
                $settlement['commission_split'] = $code;
            }
        }

        return ['gateway' => $gateway, 'settlement' => $settlement];
    }

    private function originalRow(EventMemberBenefitAttempt $attempt): TenantPaymentGateway
    {
        $row = TenantPaymentGateway::withoutGlobalScopes()->where('tenant_id', $attempt->tenant_id)->where('provider', $attempt->provider)->find($attempt->gateway_id);
        if ($row === null || ! hash_equals($attempt->account_key, $this->accounts->identityForRow($row))) {
            $this->refuse();
        }

        return $row;
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['payment' => 'The original payment account needs operator review. Do not pay again.']);
    }
}
