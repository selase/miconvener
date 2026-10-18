<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Http\Controllers\Tenant\Commerce\WebhookController;
use App\Mail\Events\EventRegistrationConfirmed;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Finance\FeeCalculator;
use App\Services\Finance\LedgerService;
use App\Services\Finance\PlatformFeeResolver;
use App\Services\Notifications\EventRuleTriggerService;
use App\Services\Tenancy\FeatureMeteringService;
use Illuminate\Support\Facades\Mail;

/** Standard-price captures share inventory with member tickets without acquiring member benefits. */
final class StandardEventPaymentConfirmer
{
    public function __construct(private readonly MemberBenefitLifecycle $lifecycle, private readonly LedgerService $ledger, private readonly FeeCalculator $fees) {}

    /** @param array<string, mixed> $metadata */
    public function confirm(EventRegistration $candidate, ?string $reference, int $amount, int $gatewayFee, string $currency, string $provider, bool $platform, array $metadata = [], ?int $splitReceived = null): void
    {
        $candidate->getConnection()->transaction(function () use ($candidate, $reference, $amount, $gatewayFee, $currency, $provider, $platform, $metadata, $splitReceived): void {
            $registration = EventRegistration::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->id);
            $event = Event::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($registration->event_id);
            if ($registration->member_benefit_required || $registration->isConfirmed() || ($registration->status === EventRegistration::STATUS_PENDING_BENEFIT_REVIEW && $registration->payment_reference === $reference)) {
                return;
            }
            $shared = $this->lifecycle->sharesInventory($event);
            $issue = ! $shared || ($registration->status === EventRegistration::STATUS_PENDING_PAYMENT && $this->lifecycle->hasCapacity($registration));
            $ticketAmount = $event->effectiveFeeBearer() === PlatformFeeResolver::BEARER_ATTENDEE ? $registration->amount : $amount;
            $commission = $this->fees->for($event, $ticketAmount)->platformFee;
            $registration->email_verified_at ??= now();
            if ($issue) {
                $registration->issueTicket();
            }
            $registration->fill(['status' => $issue ? EventRegistration::STATUS_CONFIRMED : EventRegistration::STATUS_PENDING_BENEFIT_REVIEW,
                'payment_reference' => $reference, 'payment_method' => $provider, 'amount' => $ticketAmount, 'charged_amount' => $amount,
                'platform_fee_amount' => $commission, 'gateway_fee_amount' => $gatewayFee, 'currency' => $currency])->save();
            if ($platform) {
                $this->ledger->recordTicketSale($event, $registration, $amount, $commission, (string) $reference, $gatewayFee, $provider);
            } else {
                $settlement = WebhookController::commissionSettlement($event->tenant, $currency, $provider, $metadata, $commission, $splitReceived);
                $this->ledger->recordOwnGatewayTicketSale($event, $registration, $amount, $commission, (string) $reference, $gatewayFee, $provider, $settlement['received'], $settlement['credit']);
            }
            if ($issue) {
                app(EventRuleTriggerService::class)->registrationCompleted($registration);
                app(FeatureMeteringService::class)->recordUsage($event->tenant, 'event_registrations');
                app(FeatureMeteringService::class)->recordUsage($event->tenant, 'email_credits');
                Mail::to($registration->email)->queue((new EventRegistrationConfirmed($registration))->afterCommit());
            }
        });
    }
}
