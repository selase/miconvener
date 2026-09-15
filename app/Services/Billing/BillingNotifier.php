<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Mail\Billing\PaymentReceiptMail;
use App\Models\BillingEmail;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Billing emails tenants receive about payments they make to MiConvener.
 *
 * They are transactional: they never count against a tenant's email credits
 * and cannot be switched off. Each goes to the person who paid and to the
 * tenant's contact address, once per event (see BillingEmailSender).
 */
final class BillingNotifier
{
    public function __construct(private readonly BillingEmailSender $sender) {}

    /**
     * Money in minor units, for display: "GHS 1,234.56".
     */
    public static function money(int $minor, string $currency): string
    {
        return mb_strtoupper($currency).' '.number_format($minor / 100, 2);
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    public static function paymentMethod(array $provider): ?string
    {
        $authorization = (array) ($provider['authorization'] ?? []);

        return match ($provider['channel'] ?? null) {
            null => null,
            'card' => isset($authorization['last4'])
                ? ucfirst((string) ($authorization['brand'] ?? 'card')).' card ending '.$authorization['last4']
                : 'Card',
            'mobile_money' => 'Mobile money',
            'bank', 'bank_transfer', 'dedicated_nuban' => 'Bank transfer',
            default => ucfirst(str_replace('_', ' ', (string) $provider['channel'])),
        };
    }

    /**
     * Send the receipt for a payment already recorded as a transaction.
     *
     * Safe to call from every path that sees the payment, whether or not that
     * path was the one that recorded it: the once-only key is the provider
     * reference, and a reference with no successful transaction sends nothing.
     *
     * @param  array<string, mixed>  $provider  Paystack's transaction data.
     */
    public function paymentReceived(Tenant $tenant, string $reference, ?string $payerEmail, array $provider = []): bool
    {
        if ($reference === '') {
            return false;
        }

        $transaction = Transaction::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider_transaction_id', $reference)
            ->where('status', 'success')
            ->first();

        if (! $transaction) {
            return false;
        }

        $meta = (array) $transaction->meta;
        $plan = isset($meta['package_id']) ? Package::query()->find($meta['package_id']) : null;

        $hasProviderAmount = isset($provider['amount'], $provider['currency']);

        $mail = new PaymentReceiptMail(
            tenant: $tenant,
            description: $this->describe($meta, $plan),
            amountDisplay: self::money(
                $hasProviderAmount ? (int) $provider['amount'] : (int) $transaction->amount,
                $hasProviderAmount ? (string) $provider['currency'] : (string) $transaction->currency,
            ),
            reference: $reference,
            paidOn: CarbonImmutable::parse($provider['paid_at'] ?? $transaction->created_at)
                ->timezone((string) config('app.timezone'))
                ->format('j F Y'),
            paymentMethod: self::paymentMethod($provider),
            activePlan: $plan?->name,
            billingUrl: $this->billingUrl($tenant),
        );

        return $this->sender->send($tenant, BillingEmail::TYPE_PAYMENT_RECEIPT, "receipt:{$reference}", [$payerEmail, $tenant->email], $mail);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function describe(array $meta, ?Package $plan): string
    {
        if (($meta['type'] ?? null) === 'llm_token_purchase') {
            $name = Config::get('llm.token_packs.'.($meta['pack_key'] ?? '').'.name', 'AI token pack');

            return "{$name}: ".number_format((int) ($meta['tokens'] ?? 0)).' AI tokens';
        }

        if (($meta['type'] ?? null) === 'invoice_payment') {
            $number = Invoice::query()->whereKey($meta['invoice_id'] ?? null)->value('number');

            return $number ? "Invoice {$number}" : 'Invoice payment';
        }

        if ($plan) {
            $planId = (string) ($meta['provider_plan_id'] ?? '');

            return match (true) {
                str_ends_with($planId, '_year') => "{$plan->name} plan, billed yearly",
                str_ends_with($planId, '_month') => "{$plan->name} plan, billed monthly",
                default => "{$plan->name} plan",
            };
        }

        return config('app.name').' payment';
    }

    private function billingUrl(Tenant $tenant): string
    {
        return route('billing.index', ['subdomain' => $tenant->slug]);
    }
}
