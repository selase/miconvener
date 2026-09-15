<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Mail\Billing\PaymentFailedMail;
use App\Mail\Billing\PaymentReceiptMail;
use App\Mail\Billing\SubscriptionEndedMail;
use App\Mail\Billing\SubscriptionEndingMail;
use App\Models\BillingEmail;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
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

    public static function date(mixed $value): string
    {
        return CarbonImmutable::parse($value)->timezone((string) config('app.timezone'))->format('j F Y');
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
     * Warn that a recurring subscription charge failed. The plan is not changed.
     *
     * @param  array<string, mixed>  $data  Paystack invoice.payment_failed data.
     */
    public function paymentFailed(Tenant $tenant, array $data, ?string $payerEmail): bool
    {
        $subscription = (array) ($data['subscription'] ?? []);
        $amount = (int) ($data['amount'] ?? $subscription['amount'] ?? 0);
        $retryOn = $subscription['next_payment_date'] ?? null;
        $key = $data['invoice_code']
            ?? ($subscription['subscription_code'] ?? $tenant->id).':'.$amount.':'.now()->toDateString();

        $mail = new PaymentFailedMail(
            tenant: $tenant,
            amountDisplay: self::money($amount, (string) ($data['currency'] ?? $subscription['currency'] ?? 'GHS')),
            retryOn: $retryOn ? self::date($retryOn) : null,
            billingUrl: $this->billingUrl($tenant),
        );

        return $this->sender->send($tenant, BillingEmail::TYPE_PAYMENT_FAILED, "payment_failed:{$key}", [$payerEmail, $tenant->email], $mail);
    }

    /**
     * Tell the tenant their plan will not renew and when it ends.
     *
     * @param  array<string, mixed>  $data  Paystack subscription.not_renew data.
     */
    public function subscriptionEnding(Tenant $tenant, array $data, ?string $payerEmail): bool
    {
        $endsOn = $data['next_payment_date']
            ?? Subscription::query()->where('tenant_id', $tenant->id)->latest()->value('current_period_end');

        $mail = new SubscriptionEndingMail(
            tenant: $tenant,
            planName: Package::query()->whereKey($tenant->package_id)->value('name') ?? 'current',
            endsOn: $endsOn ? self::date($endsOn) : null,
            billingUrl: $this->billingUrl($tenant),
        );

        $key = $data['subscription_code'] ?? $tenant->id.':'.now()->toDateString();

        return $this->sender->send($tenant, BillingEmail::TYPE_SUBSCRIPTION_ENDING, (string) $key, [$payerEmail, $tenant->email], $mail);
    }

    /**
     * Tell the tenant their paid plan has ended and they are now on Free.
     *
     * @param  array<string, mixed>  $data  Paystack subscription.disable data.
     */
    public function subscriptionEnded(Tenant $tenant, array $data, ?string $payerEmail, ?string $previousPlan): bool
    {
        $mail = new SubscriptionEndedMail(
            tenant: $tenant,
            previousPlan: $previousPlan,
            billingUrl: $this->billingUrl($tenant),
        );

        $key = $data['subscription_code'] ?? $tenant->id.':'.now()->toDateString();

        return $this->sender->send($tenant, BillingEmail::TYPE_SUBSCRIPTION_ENDED, (string) $key, [$payerEmail, $tenant->email], $mail);
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
            paidOn: self::date($provider['paid_at'] ?? $transaction->created_at),
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
