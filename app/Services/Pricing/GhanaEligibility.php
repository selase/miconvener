<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\Transaction;
use App\Models\User;

/**
 * Whether an organisation is really in Ghana, which is what earns the Ghana
 * price. Where someone browses from is not evidence; their money is: a
 * verified Ghana payout account, a payment by Ghana mobile money or a
 * Ghana-issued card, or a superadmin's decision. Every change keeps the
 * evidence and who recorded it, and an automatic grant never undoes a
 * superadmin's removal.
 */
final class GhanaEligibility
{
    public const string REGION_GHANA = 'GH';

    public const string REGION_INTERNATIONAL = 'INTL';

    public const string SOURCE_PAYOUT_ACCOUNT = 'payout_account';

    public const string SOURCE_GHANA_PAYMENT = 'ghana_payment';

    public const string SOURCE_SUPERADMIN = 'superadmin';

    public const string SOURCE_REVOKED = 'revoked';

    /** What an organisation pays the platform for (its own ticket sales do not count). */
    public const array PLATFORM_PAYMENT_TYPES = ['plan_subscription', 'plan_renewal', 'tenant_addon', 'llm_token_purchase', 'invoice_payment'];

    /**
     * Where a payment came from, read from the origin we store, or from the
     * Paystack authorization saved on payments recorded before we did.
     *
     * @param  array<string, mixed>  $meta
     * @return array{channel: string|null, country_code: string|null}
     */
    public static function originOf(array $meta): array
    {
        $origin = is_array($meta['payment_origin'] ?? null) ? $meta['payment_origin'] : (is_array($meta['authorization'] ?? null) ? $meta['authorization'] : []);

        return [
            'channel' => isset($origin['channel']) ? mb_strtolower((string) $origin['channel']) : (isset($meta['channel']) ? mb_strtolower((string) $meta['channel']) : null),
            'country_code' => isset($origin['country_code']) ? mb_strtoupper((string) $origin['country_code']) : null,
        ];
    }

    /**
     * Ghana mobile money, or a card issued in Ghana.
     *
     * @param  array{channel: string|null, country_code: string|null}  $origin
     */
    public static function isGhanaOrigin(array $origin): bool
    {
        return $origin['channel'] === 'mobile_money' || $origin['country_code'] === 'GH';
    }

    /**
     * The evidence found for this organisation, or null when there is none.
     *
     * @return array{source: string, detail: array<string, mixed>}|null
     */
    public function evaluate(Tenant $tenant): ?array
    {
        $account = TenantPayoutAccount::query()->where('tenant_id', $tenant->id)->where('is_verified', true)->orderBy('created_at')->first();

        if ($account !== null) {
            return ['source' => self::SOURCE_PAYOUT_ACCOUNT, 'detail' => ['account_id' => $account->id, 'type' => $account->type]];
        }

        $payments = Transaction::query()->where('tenant_id', $tenant->id)->where('status', 'success')->orderBy('created_at')->cursor();

        foreach ($payments as $payment) {
            $meta = (array) $payment->meta;

            if (! in_array($meta['type'] ?? null, self::PLATFORM_PAYMENT_TYPES, true)) {
                continue;
            }

            $origin = self::originOf($meta);

            if (self::isGhanaOrigin($origin)) {
                return ['source' => self::SOURCE_GHANA_PAYMENT, 'detail' => ['transaction_id' => $payment->id, 'channel' => $origin['channel'], 'country_code' => $origin['country_code']]];
            }
        }

        return null;
    }

    /**
     * Make an organisation Ghana-eligible. The first evidence is kept; an
     * automatic source cannot undo a superadmin's removal. Returns whether it
     * changed anything.
     *
     * @param  array<string, mixed>  $detail
     */
    public function grant(Tenant $tenant, string $source, array $detail = [], ?User $by = null): bool
    {
        $evidence = $tenant->pricing_region_evidence;

        if ($tenant->isGhanaEligible()) {
            return false;
        }

        if (($evidence['source'] ?? null) === self::SOURCE_REVOKED && $source !== self::SOURCE_SUPERADMIN) {
            return false;
        }

        $this->record($tenant, self::REGION_GHANA, $source, $detail, $by, "Ghana price granted to {$tenant->name}");

        return true;
    }

    /**
     * Take Ghana eligibility away: the organisation pays the USD price from now.
     */
    public function revoke(Tenant $tenant, User $by, string $reason): void
    {
        $this->record($tenant, self::REGION_INTERNATIONAL, self::SOURCE_REVOKED, ['reason' => $reason], $by, "Ghana price removed from {$tenant->name}");
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function record(Tenant $tenant, string $region, string $source, array $detail, ?User $by, string $log): void
    {
        $tenant->update([
            'pricing_region' => $region,
            'pricing_region_evidence' => ['source' => $source, 'detail' => $detail, 'at' => now()->toIso8601String(), 'by' => $by?->id],
        ]);

        $entry = activity()->performedOn($tenant)->withProperties(['region' => $region, 'source' => $source, 'detail' => $detail]);

        if ($by !== null) {
            $entry->causedBy($by);
        }

        $entry->log($log);
    }
}
