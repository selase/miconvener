<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventMemberBenefitAttempt;
use App\Models\EventMemberBenefitQuote;
use App\Models\EventRegistration;
use App\Models\Member;
use App\Services\Payment\PaystackGateway;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MemberBenefitCheckout
{
    public function __construct(private readonly MemberBenefitQuotes $quotes, private readonly MemberBenefitEligibility $eligibility, private readonly MemberBenefitIdentity $identity, private readonly MemberBenefitGateways $gateways) {}

    public function start(Session $session, EventRegistration $registration, string $provider, int $expectedAmount, string $currency): string
    {
        [$attempt, $isNew] = $registration->getConnection()->transaction(function () use ($session, $registration, $provider, $expectedAmount, $currency): array {
            $registration = EventRegistration::withoutGlobalScopes()->where('tenant_id', $registration->tenant_id)->lockForUpdate()->findOrFail($registration->id);
            $event = $registration->event()->lockForUpdate()->firstOrFail();
            if (! $registration->member_benefit_required || $registration->status !== EventRegistration::STATUS_PENDING_PAYMENT || ! $event->isPublished() || ! $event->sellsPaidTickets()
                || ! in_array($provider, ['paystack', 'stripe'], true) || ! $this->eligibility->enabled($event->tenant)) {
                $this->refuse('This member registration is not ready for online payment.');
            }
            $old = EventMemberBenefitQuote::withoutGlobalScopes()->where('tenant_id', $registration->tenant_id)->find($registration->member_benefit_quote_id);
            $existing = EventMemberBenefitAttempt::withoutGlobalScopes()->where('tenant_id', $registration->tenant_id)->where('registration_id', $registration->id)->whereNotIn('state', ['failed', 'refunded'])->first();
            if ($existing !== null) {
                if ($old === null || $existing->quote_id !== $old->id) {
                    $this->refuse('Your original payment needs operator review. Do not pay again.');
                }
                $this->quotes->assertUsable($old, $registration);
                $member = Member::withoutGlobalScopes()->where('tenant_id', $old->tenant_id)->findOrFail($old->member_id);
                $this->identity->capture($session, $member);
                if ($existing->state !== 'open' || $existing->checkout_url === null || $existing->provider !== $provider || $existing->charged_amount !== $expectedAmount || $existing->currency !== $currency) {
                    $this->refuse('Your original payment is being prepared or needs review. Do not pay again.');
                }

                return [$existing, false];
            }
            if ($this->eligibility->resolve($session, $event, $registration->email) === null) {
                $this->refuse('Verify your active personal membership before paying.');
            }
            if ($old === null || ! $old->expires_at->isFuture()) {
                $pricing = $this->quotes->pricing($registration);
                if ($currency !== $registration->currency || $expectedAmount !== $pricing['fees']['charged_amount'] || $expectedAmount <= 0) {
                    $this->refuse('Review and accept the current total and currency before paying.');
                }
                $registration->update(['amount' => $pricing['amount'], 'discount_amount' => $pricing['discount_amount'], 'charged_amount' => $pricing['fees']['charged_amount'], 'platform_fee_amount' => $pricing['fees']['platform_fee']]);
            }
            $quote = $this->quotes->start($session, $registration);
            if ($expectedAmount !== $quote->charged_amount || $currency !== $quote->currency || $expectedAmount <= 0) {
                $this->refuse('Review and accept the quoted total and currency before paying.');
            }
            $account = $this->gateways->identity($event->tenant, $provider, $currency);
            $attempt = EventMemberBenefitAttempt::query()->create([...$account, 'tenant_id' => $quote->tenant_id, 'quote_id' => $quote->id,
                'registration_id' => $registration->id, 'provider' => $provider, 'reference' => 'member_'.Str::uuid(), 'amount' => $quote->amount,
                'charged_amount' => $quote->charged_amount, 'platform_fee_amount' => $quote->platform_fee_amount, 'currency' => $currency, 'state' => 'preparing']);

            return [$attempt, true];
        });
        if (! $isNew) {
            return $attempt->checkout_url;
        }
        $initializing = false;
        try {
            $prepared = $this->gateways->prepare($attempt);
            $attempt->update(['commission_settlement' => $prepared['settlement']]);
            $gateway = $prepared['gateway'];
            $registration = $attempt->registration;
            $event = $registration->event;
            $callback = route('public.events.member-callback', ['subdomain' => $event->tenant->slug, 'event' => $event->slug, 'registration' => $registration->id]);
            $metadata = ['type' => 'event_ticket', 'source' => config('services.paystack.metadata_source'), 'tenant_id' => $attempt->tenant_id,
                'event_registration_id' => $registration->id, 'member_benefit_attempt_id' => $attempt->id, 'member_benefit_reference' => $attempt->reference,
                ...$prepared['settlement']];
            if ($gateway instanceof PaystackGateway) {
                $customer = $gateway->createCustomer($registration->email, $registration->full_name);
                $initializing = true;
                $url = $gateway->createOneTimeCheckoutSession($customer, $attempt->charged_amount, $attempt->currency, $callback, $metadata, $attempt->reference);
                $sessionId = null;
            } else {
                $initializing = true;
                $result = $gateway->createConnectedCheckoutSessionResult($registration->email, $attempt->charged_amount, $attempt->currency,
                    $event->name, $callback, route('public.events.checkout', ['subdomain' => $event->tenant->slug, 'event' => $event->slug, 'registration' => $registration->id]),
                    array_map(static fn ($value): string => (string) $value, array_filter($metadata, static fn ($value): bool => $value !== null)),
                    (int) $prepared['settlement']['commission_collected'], $attempt->reference);
                $url = $result['url'];
                $sessionId = $result['session_id'];
            }
            EventMemberBenefitAttempt::withoutGlobalScopes()->whereKey($attempt->id)->where('state', 'preparing')->update(['state' => 'open', 'checkout_url' => \Illuminate\Support\Facades\Crypt::encryptString($url), 'provider_session_id' => $sessionId]);

            return $url;
        } catch (Throwable $exception) {
            EventMemberBenefitAttempt::withoutGlobalScopes()->whereKey($attempt->id)->where('state', 'preparing')->update(['state' => $initializing ? 'unknown' : 'failed', 'review_reason' => $initializing ? 'initialization_unknown' : 'initialization_refused']);
            $this->refuse($initializing ? 'The payment outcome is not known yet. Do not pay again; contact the organiser.' : 'Payment could not be started. Review the payment setup before trying again.');
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
