<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\PaymentFailedException;
use App\Mail\Billing\CommissionSplitBrokenMail;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use Illuminate\Support\Facades\Mail;

/**
 * Checks, with the tenant's own Paystack key, that the subaccount they entered
 * exists, is active, and settles to the platform's commission account. There is
 * no way to ask Paystack for permission in advance, so this reads back what
 * the tenant actually created.
 */
final class CommissionSplitVerifier
{
    /**
     * Splitting is only enforced once the platform has said where its
     * commission should land.
     */
    public function isEnforced(): bool
    {
        return filled(config('services.commission_split.account_number'));
    }

    /**
     * @return array{business_name: string, settlement_bank: ?string, account_number: ?string}
     */
    public function destination(): array
    {
        return [
            'business_name' => (string) config('services.commission_split.business_name'),
            'settlement_bank' => config('services.commission_split.settlement_bank'),
            'account_number' => config('services.commission_split.account_number'),
        ];
    }

    public function check(TenantPaymentGateway $gateway, string $subaccountCode): CommissionSplitCheck
    {
        $paystack = new PaystackGateway(['secret_key' => $gateway->api_key_encrypted]);

        try {
            $subaccount = $paystack->subaccount($subaccountCode);
        } catch (PaymentFailedException) {
            return CommissionSplitCheck::inconclusive('Paystack could not be reached to check the subaccount.');
        }

        if ($subaccount === null) {
            return CommissionSplitCheck::invalid('Paystack has no subaccount with that code on this account.');
        }

        if (($subaccount['active'] ?? true) === false) {
            return CommissionSplitCheck::invalid('That subaccount is deactivated.');
        }

        $expected = preg_replace('/\D+/', '', (string) config('services.commission_split.account_number'));
        $actual = preg_replace('/\D+/', '', (string) ($subaccount['account_number'] ?? ''));

        if ($expected === '' || $actual !== $expected) {
            return CommissionSplitCheck::invalid('That subaccount does not settle to the MiConvener commission account.');
        }

        return CommissionSplitCheck::valid();
    }

    /**
     * Checks the stored subaccount and records the outcome. An inconclusive
     * check leaves the previous verdict standing.
     */
    public function recheck(TenantPaymentGateway $gateway): CommissionSplitCheck
    {
        $code = $gateway->commissionSubaccountCode();

        if ($code === null) {
            return CommissionSplitCheck::invalid('No commission subaccount has been entered.');
        }

        return $this->checkAndRecord($gateway, $code);
    }

    public function checkAndRecord(TenantPaymentGateway $gateway, string $subaccountCode): CommissionSplitCheck
    {
        $result = $this->check($gateway, $subaccountCode);

        if (! $result->inconclusive) {
            // Tell the organization once, when a subaccount that worked stops
            // working, not on every failed re-check.
            $justBroke = ! $result->ok && $gateway->commissionSplitIsVerified();

            $gateway->recordCommissionSplit([
                'subaccount_code' => $subaccountCode,
                'ok' => $result->ok,
                'reason' => $result->reason,
            ]);

            if ($justBroke) {
                $this->notifyBroken($gateway, (string) $result->reason);
            }
        }

        return $result;
    }

    private function notifyBroken(TenantPaymentGateway $gateway, string $reason): void
    {
        $tenant = Tenant::find($gateway->tenant_id);
        $recipients = $tenant?->organizerNotificationEmails() ?? [];

        if ($recipients === []) {
            return;
        }

        Mail::to($recipients)->queue(new CommissionSplitBrokenMail($tenant, $reason, $tenant->url('/settings/payments')));
    }
}
