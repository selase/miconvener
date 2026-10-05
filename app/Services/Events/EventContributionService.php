<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventContribution;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Finance\FeeCalculator;
use App\Services\Finance\LedgerService;
use App\Services\Payment\PaystackGateway;
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
    public function initiateContribution(Event $event, array $data, string $callbackUrl): string
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

        $paystack = $tenant->isPlatformDefaultSettlement()
            ? $this->platformGateway()
            : $this->tenantGateway($tenant);

        if (! $paystack) {
            throw new RuntimeException('Online payments are not currently configured for this organizer.');
        }

        $paymentReference = 'CONTRIB-'.mb_strtoupper(Str::random(16));

        /** @var EventContribution $contribution */
        $contribution = DB::connection('landlord')->transaction(function () use ($tenant, $event, $data, $amountPesewas, $paymentReference): EventContribution {
            return EventContribution::create([
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
        });

        try {
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

        DB::connection('landlord')->transaction(function () use (
            $contribution,
            $event,
            $reference,
            $grossAmount,
            $gatewayFee,
            $platformFee,
            $netAmount
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
        });

        return $contribution->fresh();
    }

    public function resolveGateway(Tenant $tenant): ?PaystackGateway
    {
        return $tenant->isPlatformDefaultSettlement()
            ? $this->platformGateway()
            : $this->tenantGateway($tenant);
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

    private function tenantGateway(Tenant $tenant): ?PaystackGateway
    {
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        return $gateway ? new PaystackGateway(['secret_key' => $gateway->api_key_encrypted]) : null;
    }

    private function platformGateway(): ?PaystackGateway
    {
        $secret = config('services.settlement.paystack.secret_key');

        return $secret ? new PaystackGateway(['secret_key' => $secret]) : null;
    }

    private function contributionPercentage(): ?float
    {
        $configured = config('services.contributions.platform_fee_percentage');

        return $configured === null || $configured === '' ? null : max(0.0, (float) $configured);
    }
}
