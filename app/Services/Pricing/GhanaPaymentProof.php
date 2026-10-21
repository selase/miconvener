<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Exceptions\PaymentFailedException;
use App\Http\Controllers\Billing\WebhookController;
use App\Mail\Billing\GhanaPriceAdjusted;
use App\Mail\Billing\GhanaTopUpFailedAlert;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Billing\BillingNotifier;
use App\Services\Payment\PaystackGateway;
use App\Services\Platform\PlatformStaff;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * What a payment of the Ghana price says about where the organisation is.
 * Ghana mobile money, or a card issued in Ghana, proves it. A card issued
 * anywhere else does not: the organisation is settled in USD and the
 * difference to the USD price is charged to the same card, so paying the Ghana
 * price with a foreign card gets no discount. Called by both the browser
 * callback and the webhook; whichever arrives first settles it, once.
 */
final class GhanaPaymentProof
{
    public const string PROVEN = 'proven';

    public const string TOPPED_UP = 'topped_up';

    public const string TOP_UP_FAILED = 'top_up_failed';

    /** The foreign card already paid at least the USD price. */
    public const string PAID_IN_FULL = 'paid_in_full';

    /** There was no USD price to compare to, so a person decides. */
    public const string UNRESOLVED = 'unresolved';

    public function __construct(
        private readonly GhanaEligibility $eligibility,
        private readonly ChargeQuote $quotes,
        private readonly PaystackGateway $paystack,
    ) {}

    /**
     * @param  array<string, mixed>  $payment  the verified Paystack payment: reference, amount, channel, authorization, customer_email, metadata
     * @return string|null the outcome, or null when the payment was not a priced platform checkout needing no proof
     */
    public function settle(Tenant $tenant, array $payment): ?string
    {
        $reference = (string) ($payment['reference'] ?? '');
        $metadata = WebhookController::metadata($payment['metadata'] ?? null);
        $pricing = is_array($metadata['pricing'] ?? null) ? $metadata['pricing'] : null;

        if ($reference === '' || $pricing === null) {
            return null;
        }

        return Cache::lock('ghana-proof:'.$reference, 60)->block(15, fn (): ?string => $this->settleOnce($tenant, $payment, $reference, $pricing));
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $pricing
     */
    private function settleOnce(Tenant $tenant, array $payment, string $reference, array $pricing): ?string
    {
        $transaction = Transaction::query()->where('provider_transaction_id', $reference)->first();

        if ($transaction === null) {
            return null;
        }

        $meta = (array) $transaction->meta;
        $origin = GhanaEligibility::originOf(['authorization' => (array) ($payment['authorization'] ?? []), 'channel' => $payment['channel'] ?? null]);
        $meta['payment_origin'] = $origin;

        if (($pricing['proof_required'] ?? false) !== true) {
            $transaction->update(['meta' => $meta]);

            return null;
        }

        if (isset($meta['ghana_proof'])) {
            return (string) $meta['ghana_proof'];
        }

        $outcome = GhanaEligibility::isGhanaOrigin($origin)
            ? $this->prove($tenant, $reference, $origin)
            : $this->settleForeignCard($tenant, $payment, $reference, $pricing, $origin);

        $transaction->update(['meta' => [...$meta, 'ghana_proof' => $outcome]]);

        return $outcome;
    }

    /**
     * @param  array{channel: string|null, country_code: string|null}  $origin
     */
    private function prove(Tenant $tenant, string $reference, array $origin): string
    {
        $this->eligibility->grant($tenant, GhanaEligibility::SOURCE_GHANA_PAYMENT, ['reference' => $reference, ...$origin]);

        return self::PROVEN;
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $pricing
     * @param  array{channel: string|null, country_code: string|null}  $origin
     */
    private function settleForeignCard(Tenant $tenant, array $payment, string $reference, array $pricing, array $origin): string
    {
        if (! isset($pricing['usd_alternative_minor'])) {
            Log::warning('pricing.ghana.no_usd_price', ['tenant_id' => $tenant->id, 'reference' => $reference]);
            $this->alertFinance($tenant, $reference, self::UNRESOLVED, 'The item has no USD price, so the foreign card could not be compared to one.');

            return self::UNRESOLVED;
        }

        $due = $this->quotes->for(new Money((int) $pricing['usd_alternative_minor'], 'USD'))->ghsMinor();
        $difference = $due - (int) ($payment['amount'] ?? 0);

        $this->eligibility->settleInternational($tenant, ['reference' => $reference, ...$origin]);
        $this->billInUsdFromNow($reference);

        if ($difference <= 0) {
            return self::PAID_IN_FULL;
        }

        return $this->topUp($tenant, $payment, $reference, $difference, $origin);
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  array{channel: string|null, country_code: string|null}  $origin
     */
    private function topUp(Tenant $tenant, array $payment, string $reference, int $difference, array $origin): string
    {
        $authorization = (array) ($payment['authorization'] ?? []);
        $email = (string) ($payment['customer_email'] ?? $payment['customer']['email'] ?? $tenant->email ?? '');
        $display = (new Money($difference, 'GHS'))->format();
        $item = $this->describe($payment);

        try {
            if (($authorization['reusable'] ?? false) !== true || blank($authorization['authorization_code'] ?? null)) {
                throw PaymentFailedException::fromProvider('paystack', 'The card cannot be charged again.');
            }

            $result = $this->paystack->chargeAuthorization($email, $difference, 'GHS', (string) $authorization['authorization_code'], 'topup-'.$reference, [
                'source' => config('services.paystack.metadata_source'),
                'type' => 'ghana_price_topup',
                'tenant_id' => $tenant->id,
                'original_reference' => $reference,
            ]);
        } catch (PaymentFailedException $e) {
            return $this->topUpFailed($tenant, $reference, $display, $item, $e->getMessage());
        }

        if ($result['status'] !== 'success') {
            return $this->topUpFailed($tenant, $reference, $display, $item, (string) ($result['gateway_response'] ?? $result['status']));
        }

        Transaction::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'paystack',
            'provider_transaction_id' => 'topup-'.$reference,
            'amount' => $difference,
            'currency' => 'ghs',
            'status' => 'success',
            'type' => 'charge',
            'meta' => ['type' => 'ghana_price_topup', 'original_reference' => $reference, 'payment_origin' => $origin],
        ]);

        $this->tell($tenant, GhanaPriceAdjusted::TOPPED_UP, $item, $display);

        return self::TOPPED_UP;
    }

    private function topUpFailed(Tenant $tenant, string $reference, string $display, string $item, string $why): string
    {
        Log::warning('pricing.ghana.topup_failed', ['tenant_id' => $tenant->id, 'reference' => $reference, 'why' => $why]);
        $this->tell($tenant, GhanaPriceAdjusted::TOP_UP_FAILED, $item, $display);
        $this->alertFinance($tenant, $reference, self::TOP_UP_FAILED, "Charging {$display} to the same card failed: {$why}");

        return self::TOP_UP_FAILED;
    }

    /** The plan bought with this payment renews at the USD price from now on. */
    private function billInUsdFromNow(string $reference): void
    {
        Subscription::query()->where('provider_id', 'ps_'.$reference)->update(['billing_currency' => 'USD']);
    }

    private function tell(Tenant $tenant, string $outcome, string $item, string $display): void
    {
        $recipients = array_values(array_unique(array_filter([$tenant->email, ...$tenant->users()->pluck('email')->all()])));

        if ($recipients !== []) {
            Mail::to($recipients)->queue(new GhanaPriceAdjusted($tenant, $outcome, $item, $display, app(BillingNotifier::class)->billingUrl($tenant)));
        }
    }

    private function alertFinance(Tenant $tenant, string $reference, string $outcome, string $detail): void
    {
        $staff = app(PlatformStaff::class)->emailsWithPermission('view platform finance');

        if ($staff !== []) {
            Mail::to($staff)->queue(new GhanaTopUpFailedAlert($tenant, $reference, $outcome, $detail));
        }
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function describe(array $payment): string
    {
        $metadata = WebhookController::metadata($payment['metadata'] ?? null);

        return match ($metadata['type'] ?? null) {
            'plan_subscription' => ucfirst((string) ($metadata['plan_slug'] ?? 'your plan')).' plan',
            'tenant_addon' => 'your add-on',
            'llm_token_purchase' => 'your AI tokens',
            default => 'your purchase',
        };
    }
}
