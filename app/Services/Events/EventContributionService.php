<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Http\Controllers\Tenant\Commerce\WebhookController;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\Tenant;
use App\Services\Finance\CommissionCredit;
use App\Services\Finance\FeeCalculator;
use App\Services\Finance\LedgerService;
use App\Services\Payment\PaystackGateway;
use App\Services\Payment\StripeConnect;
use App\Services\Payment\TenantPaystackResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class EventContributionService
{
    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly FeeCalculator $feeCalculator,
        private readonly TenantPaystackResolver $paystackResolver,
        private readonly StripeConnect $stripeConnect,
        private readonly CommissionCredit $credit,
    ) {}

    /**
     * Creates a pending contribution and initiates a Paystack checkout session.
     *
     * @param  array{
     *     contributor_name: string,
     *     contributor_email?: string|null,
     *     contributor_phone?: string|null,
     *     amount: int|float,
     *     tribute_message?: string|null,
     *     is_anonymous?: bool|null
     * }  $data
     */
    public function initiateContribution(Event $event, array $data, string $callbackUrl, string $provider = 'paystack'): string
    {
        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);
        if (! $tenant) {
            throw new RuntimeException('Tenant not found for event.');
        }

        if (! $event->allowsContributions()) {
            throw new RuntimeException('This event is not currently accepting voluntary contributions.');
        }

        $minAmountPesewas = $event->contribution_min_amount_pesewas ?? 100;
        $amountPesewas = (int) round(((float) $data['amount']) * 100);

        if ($amountPesewas < $minAmountPesewas) {
            $formattedMin = sprintf('%.2f', $minAmountPesewas / 100);
            throw new InvalidArgumentException("Minimum contribution amount is {$event->currency} {$formattedMin}.");
        }

        $initiatedAt = \Carbon\CarbonImmutable::now();
        $bridge = app(\App\Services\Giving\GivingContributionBridge::class);
        if ($bridge->usesGiving($tenant, $initiatedAt)) {
            return $bridge->initiate($event, $data, $callbackUrl, $provider, $initiatedAt);
        }

        $platformFee = $this->platformFeeFor($event, $amountPesewas);
        $stripeRow = null;
        $paystack = null;

        if ($provider === 'stripe') {
            $stripeRow = $this->stripeConnect->isAvailableFor($tenant) ? $this->stripeConnect->connectedGateway($tenant) : null;

            if (! $stripeRow) {
                throw new RuntimeException('Card payments by Stripe are not available for this organizer.');
            }
        } else {
            $paystack = $this->paystackResolver->gatewayFor($tenant, $platformFee, $event->currency ?: 'GHS');

            if (! $paystack) {
                throw new RuntimeException('Online payments are not currently configured for this organizer.');
            }
        }

        $paymentReference = 'CONTRIB-'.mb_strtoupper(Str::random(16));

        /** @var EventContribution $contribution */
        $contribution = DB::connection('landlord')->transaction(function () use ($tenant, $event, $data, $amountPesewas, $paymentReference, $initiatedAt): EventContribution {
            $source = new EventContribution([
                'tenant_id' => $tenant->id,
                'event_id' => $event->id,
                'contributor_name' => mb_trim((string) $data['contributor_name']),
                'contributor_email' => ! empty($data['contributor_email']) ? mb_trim((string) $data['contributor_email']) : null,
                'contributor_phone' => ! empty($data['contributor_phone']) ? mb_trim((string) $data['contributor_phone']) : null,
                'amount' => $amountPesewas,
                'currency' => $event->currency ?: 'GHS',
                'status' => EventContribution::STATUS_PENDING_PAYMENT,
                'payment_reference' => $paymentReference,
                'tribute_message' => ! empty($data['tribute_message']) ? mb_trim((string) $data['tribute_message']) : null,
                'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
                'is_approved' => true,
            ]);
            $source->setCreatedAt($initiatedAt);
            $source->save();

            return $source;
        });

        try {
            if ($stripeRow) {
                $creditApplied = min($this->credit->balance($tenant, $contribution->currency), $platformFee);

                return $this->stripeConnect->gatewayFor($stripeRow)->createConnectedCheckoutSession(
                    $contribution->contributor_email ?: 'contributor+'.mb_strtolower(Str::random(8)).'@miconvener.com',
                    $contribution->amount,
                    $contribution->currency,
                    $event->name,
                    // Stripe confirms through its webhook, so both ends of the
                    // round trip return to the event page.
                    route('public.events.show', ['subdomain' => $tenant->slug, 'event' => $event->slug]),
                    route('public.events.show', ['subdomain' => $tenant->slug, 'event' => $event->slug]),
                    [
                        'type' => 'event_contribution',
                        'event_contribution_id' => (string) $contribution->id,
                        'tenant_id' => (string) $tenant->id,
                        'event_id' => (string) $event->id,
                        'commission_collected' => (string) ($platformFee - $creditApplied),
                        'commission_credit_applied' => (string) $creditApplied,
                    ],
                    $platformFee - $creditApplied,
                );
            }

            $customerEmail = $contribution->contributor_email ?: 'contributor+'.mb_strtolower(Str::random(8)).'@miconvener.com';
            $customerId = $paystack->createCustomer($customerEmail, $contribution->displayName());

            $resolvedCallbackUrl = str_contains($callbackUrl, ':id')
                ? str_replace(':id', $contribution->id, $callbackUrl)
                : $callbackUrl;

            return $paystack->createOneTimeCheckoutSession(
                $customerId,
                $contribution->amount,
                $contribution->currency,
                $resolvedCallbackUrl,
                [
                    'type' => 'event_contribution',
                    'event_contribution_id' => $contribution->id,
                    'tenant_id' => $tenant->id,
                    'event_id' => $event->id,
                    'source' => config('services.paystack.metadata_source'),
                    'commission_split' => $paystack->splitSubaccount(),
                    'commission_credit_applied' => $paystack->commissionCreditApplied(),
                ]
            );
        } catch (Throwable $e) {
            $contribution->delete();
            throw $e;
        }
    }

    /**
     * Idempotently confirm payment for an event contribution.
     *
     * @param  array<string, mixed>  $gatewayData
     */
    public function confirmContributionPayment(string $reference, array $gatewayData = []): ?EventContribution
    {
        // 1. Idempotency check by Paystack reference
        $existing = EventContribution::query()
            ->with(['event', 'tenant'])
            ->where('paystack_reference', $reference)
            ->first();

        if ($existing !== null && $existing->isCompleted()) {
            return $existing;
        }

        // 2. Lookup contribution by payment reference or metadata ID with grouped OR conditions
        $metadata = $gatewayData['metadata'] ?? [];
        $contributionId = $metadata['event_contribution_id'] ?? null;

        $query = EventContribution::query()->with(['event', 'tenant']);

        if ($contributionId) {
            $query->where('id', $contributionId);
        } else {
            $query->where(function (Builder $q) use ($reference): void {
                $q->where('payment_reference', $reference)
                    ->orWhere('paystack_reference', $reference);
            });
        }

        /** @var EventContribution|null $contribution */
        $contribution = $query->first();

        if ($contribution === null) {
            Log::warning("Event contribution for reference [{$reference}] was not found during confirmation.");

            return null;
        }

        $givingAttempt = \App\Models\GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $contribution->tenant_id)->where('event_contribution_id', $contribution->id)->first();
        if ($givingAttempt !== null) {
            app(\App\Services\Giving\GivingPaymentConfirmer::class)->quarantine($givingAttempt, 'legacy_dispatch_for_giving_source');

            return $givingAttempt->state === 'confirmed' ? $contribution : null;
        }

        if ($contribution->isCompleted()) {
            return $contribution;
        }

        $event = $contribution->event;
        if (! $event) {
            throw new RuntimeException('Event associated with contribution not found.');
        }

        $grossAmount = isset($gatewayData['amount']) ? (int) $gatewayData['amount'] : (int) $contribution->amount;
        $gatewayFee = isset($gatewayData['fees']) ? (int) $gatewayData['fees'] : 0;
        $platformFee = $this->platformFeeFor($event, $grossAmount);
        $netAmount = max(0, $grossAmount - $gatewayFee - $platformFee);

        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);
        $provider = (string) ($gatewayData['provider'] ?? 'paystack');
        $settlement = $tenant->isPlatformDefaultSettlement()
            ? ['received' => 0, 'credit' => 0]
            : WebhookController::commissionSettlement($tenant, (string) ($event->currency ?: 'GHS'), $provider, (array) ($gatewayData['metadata'] ?? []), $platformFee);

        DB::connection('landlord')->transaction(function () use (
            $contribution,
            $event,
            $reference,
            $grossAmount,
            $gatewayFee,
            $platformFee,
            $netAmount,
            $tenant,
            $provider,
            $settlement
        ): void {
            $contribution->update([
                'status' => EventContribution::STATUS_COMPLETED,
                'paystack_reference' => $reference,
                'amount' => $grossAmount,
                'gateway_fee_amount' => $gatewayFee,
                'platform_fee_amount' => $platformFee,
                'net_amount' => $netAmount,
                'paid_at' => now(),
            ]);

            if ($tenant->isPlatformDefaultSettlement()) {
                $this->ledgerService->recordContribution(
                    $event,
                    $contribution,
                    $grossAmount,
                    $platformFee,
                    $reference,
                    $gatewayFee,
                    'paystack',
                    $reference
                );

                return;
            }

            $this->ledgerService->recordOwnGatewayContribution(
                $event,
                $contribution,
                $grossAmount,
                $platformFee,
                $reference,
                $gatewayFee,
                $provider,
                $settlement['received'],
                $settlement['credit'],
            );
        });

        return $contribution->fresh();
    }

    /**
     * The tenant's gateway for looking up a payment that already happened, so
     * it carries no split.
     */
    public function resolveGateway(Tenant $tenant): ?PaystackGateway
    {
        return $this->paystackResolver->gatewayFor($tenant, 0);
    }

    /**
     * What MiConvener keeps from a contribution of this amount, in minor units.
     */
    public function platformFeeFor(Event $event, int $grossAmount): int
    {
        $percentage = $this->contributionPercentage();

        if ($percentage === null) {
            return $this->feeCalculator->for($event, $grossAmount)->platformFee;
        }

        return (int) round($grossAmount * $percentage / 100);
    }

    /**
     * The commission in plain words, shown to the organizer before they turn
     * contributions on, so the rate taken is the rate they were told.
     */
    public function feeNoteFor(Event $event): string
    {
        if (app(\App\Services\Giving\GivingContributionBridge::class)->usesGiving($event->tenant, \Carbon\CarbonImmutable::now())) {
            return 'Your selected amount is your total contribution. Applicable processing fees and platform charges are covered by the organisation.';
        }
        $percentage = $this->contributionPercentage();

        if ($percentage === 0.0) {
            return 'Contributions carry no MiConvener commission. Paystack deducts its own processing fee.';
        }

        if ($percentage !== null) {
            return sprintf('MiConvener keeps %s%% of each contribution. Paystack also deducts its processing fee.', self::percent($percentage));
        }

        $cap = $event->effectivePlatformFeeCapAmount();
        $capText = $cap !== null
            ? sprintf(', up to %s %s per contribution', $event->currency ?: 'GHS', number_format($cap / 100, 2))
            : '';

        return sprintf(
            'MiConvener keeps %s%% of each contribution%s, the same as your ticket commission. Paystack also deducts its processing fee.',
            self::percent($event->effectivePlatformFeePercentage()),
            $capText,
        );
    }

    private static function percent(float $percentage): string
    {
        return mb_rtrim(mb_rtrim(number_format($percentage, 2, '.', ''), '0'), '.');
    }

    private function contributionPercentage(): ?float
    {
        $configured = config('services.contributions.platform_fee_percentage');

        return $configured === null || $configured === '' ? null : max(0.0, (float) $configured);
    }
}
