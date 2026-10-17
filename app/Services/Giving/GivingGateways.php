<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Models\GivingPaymentAttempt;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Finance\CommissionCredit;
use App\Services\Payment\CommissionSplitVerifier;
use App\Services\Payment\PaystackGateway;
use App\Services\Payment\StripeConnect;
use App\Services\Payment\StripeGateway;
use Illuminate\Validation\ValidationException;

/** Resolve the original account, never the organisation's current settlement choice. */
final class GivingGateways
{
    public function __construct(private readonly StripeConnect $stripeConnect, private readonly CommissionSplitVerifier $splitVerifier, private readonly CommissionCredit $credit) {}

    public function identityForRow(TenantPaymentGateway $row): string
    {
        return $row->provider === 'stripe'
            ? 'stripe:'.$row->id.':'.$row->provider_account_id
            : 'paystack:'.$row->id.':'.$this->fingerprint((string) $row->api_key_encrypted);
    }

    public function platformIdentity(): string
    {
        return 'paystack:platform:'.$this->fingerprint((string) config('services.settlement.paystack.secret_key'));
    }

    public function identity(Tenant $tenant, string $provider, string $currency): string
    {
        if ($provider === 'paystack' && $currency !== mb_strtoupper((string) config('services.paystack.currency', 'GHS'))) {
            throw ValidationException::withMessages(['currency' => 'This Paystack account does not support that collection currency.']);
        }
        if ($provider === 'paystack' && $tenant->isPlatformDefaultSettlement()) {
            if (! filled(config('services.settlement.paystack.secret_key'))) {
                throw ValidationException::withMessages(['provider' => 'Online payments are not configured.']);
            }

            return $this->platformIdentity();
        }
        if (! in_array($provider, ['paystack', 'stripe'], true) || $tenant->isPlatformDefaultSettlement()) {
            throw ValidationException::withMessages(['provider' => 'Choose an available payment method.']);
        }
        $row = TenantPaymentGateway::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('provider', $provider)->where('is_active', true)->first();
        if ($row === null || ($provider === 'stripe' && (! $row->provider_account_id || ! $this->stripeConnect->isConfigured())) || ($provider === 'paystack' && ! filled($row->api_key_encrypted))) {
            throw ValidationException::withMessages(['provider' => 'The organisation needs to connect this payment account.']);
        }

        return $this->identityForRow($row);
    }

    /** @return array{gateway:PaystackGateway|StripeGateway, settlement:array<string, mixed>} */
    public function prepare(GivingPaymentAttempt $attempt): array
    {
        $gateway = $this->forAttempt($attempt);
        $settlement = ['commission_credit_applied' => 0, 'commission_collected' => 0, 'commission_split' => null];
        if ($attempt->settlement_mode === Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT) {
            return ['gateway' => $gateway, 'settlement' => $settlement];
        }
        $credit = min($this->credit->balance($attempt->tenant, $attempt->currency), $attempt->commission_amount);
        if ($gateway instanceof StripeGateway) {
            $settlement['commission_credit_applied'] = $credit;
            $settlement['commission_collected'] = $attempt->commission_amount - $credit;
        } elseif ($attempt->commission_amount > 0 && $this->splitVerifier->isEnforced()) {
            $gateway = $gateway->withCreditApplied($credit);
            $settlement['commission_credit_applied'] = $credit;
            if ($attempt->commission_amount > $credit) {
                $row = $this->originalRow($attempt);
                $code = $row->commissionSubaccountCode();
                if ($code === null || ! $this->splitVerifier->checkAndRecord($row, $code)->ok) {
                    throw ValidationException::withMessages(['provider' => 'Payment setup needs the organisation’s attention.']);
                }
                $gateway = $gateway->withSplit($code, $attempt->commission_amount - $credit);
                $settlement['commission_split'] = $code;
            }
        }

        return ['gateway' => $gateway, 'settlement' => $settlement];
    }

    public function forAttempt(GivingPaymentAttempt $attempt): PaystackGateway|StripeGateway
    {
        if ($attempt->provider === 'paystack' && $attempt->settlement_mode === Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT) {
            if (! filled(config('services.settlement.paystack.secret_key')) || ! hash_equals($attempt->account_identity, $this->platformIdentity())) {
                throw ValidationException::withMessages(['payment' => 'The original payment account needs operator review.']);
            }

            return new PaystackGateway(['secret_key' => config('services.settlement.paystack.secret_key')]);
        }
        $row = $this->originalRow($attempt);

        return $attempt->provider === 'stripe' ? $this->stripeConnect->gatewayFor($row) : new PaystackGateway(['secret_key' => $row->api_key_encrypted]);
    }

    /** @return array<string, mixed> */
    public function verify(GivingPaymentAttempt $attempt): array
    {
        $gateway = $this->forAttempt($attempt);
        $data = $gateway instanceof StripeGateway
            ? $gateway->verifyConnectedGivingSession((string) $attempt->provider_session_id)
            : $gateway->verifyTransaction($attempt->reference);

        return [...$data, 'fees' => $attempt->provider === 'paystack' && ! ($data['fees_reported'] ?? false) ? null : ($data['fees'] ?? null), 'commission_received' => $attempt->provider === 'stripe' ? ($data['commission_received'] ?? 0) : (int) ($data['fees_split']['subaccount'] ?? 0), 'provider' => $attempt->provider, 'account_identity' => $attempt->account_identity,
            'provider_reference' => $attempt->provider === 'stripe' ? ($data['provider_reference'] ?? null) : ($data['reference'] ?? null)];
    }

    /** @return array{reference:string, status:string} */
    public function requestRefund(GivingPaymentAttempt $attempt, \App\Models\GivingAdjustment $adjustment): array
    {
        $gateway = $this->forAttempt($attempt);

        return $gateway->requestGivingRefund((string) $attempt->provider_reference, $adjustment->principal, $adjustment->operation_key);
    }

    /** @return array<string, mixed> */
    public function verifyRefund(GivingPaymentAttempt $attempt, \App\Models\GivingAdjustment $adjustment): array
    {
        $gateway = $this->forAttempt($attempt);
        $data = $gateway->verifyGivingRefund((string) $adjustment->provider_refund_reference, (string) $attempt->provider_reference, $adjustment->operation_key);

        return [...$data, 'provider' => $attempt->provider, 'account_identity' => $attempt->account_identity,
            'payment_reference' => $attempt->provider_reference, 'provider_refund_reference' => $adjustment->provider_refund_reference];
    }

    private function originalRow(GivingPaymentAttempt $attempt): TenantPaymentGateway
    {
        $parts = explode(':', $attempt->account_identity, 3);
        $row = TenantPaymentGateway::withoutGlobalScopes()->where('tenant_id', $attempt->tenant_id)->where('provider', $attempt->provider)->whereKey($parts[1] ?? '')->first();
        if ($row === null || ! hash_equals($attempt->account_identity, $this->identityForRow($row))) {
            throw ValidationException::withMessages(['payment' => 'The original payment account needs operator review.']);
        }

        return $row;
    }

    private function fingerprint(string $secret): string
    {
        return hash_hmac('sha256', $secret, (string) config('app.key'));
    }
}
