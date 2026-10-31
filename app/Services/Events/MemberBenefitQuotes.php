<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\EventMemberBenefitQuote;
use App\Models\EventRegistration;
use App\Models\Member;
use App\Services\Finance\FeeCalculator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

final class MemberBenefitQuotes
{
    public function __construct(private readonly MemberBenefitEligibility $eligibility, private readonly MemberBenefitIdentity $identity, private readonly RegistrationPricingService $pricing, private readonly FeeCalculator $fees) {}

    /** @return array<string, mixed> */
    public function pricing(EventRegistration $registration): array
    {
        $event = $registration->event;
        $type = $registration->ticketType;
        $base = $type !== null ? $type->member_price : $event->member_ticket_price;
        $answers = $this->pricing->validateAndCleanAnswers($event, (array) $registration->form_answers);
        $price = $this->pricing->calculatePrice($event, $type, $answers, $base);
        $promo = $registration->promoCode;
        $discount = 0;
        if ($promo !== null) {
            if (! $promo->is_active || ! $promo->hasStarted() || $promo->isExpired() || ! $promo->isValidForTicketType($type)) {
                throw ValidationException::withMessages(['promo_code' => 'This code is no longer available. Review your registration before paying.']);
            }
            $discount = $promo->calculateDiscount($price['amount']);
        }
        $amount = max(0, $price['amount'] - $discount);

        return [...$price, 'amount' => $amount, 'discount_amount' => $discount, 'member_base' => $base, 'fees' => $this->fees->for($event, $amount)->toArray()];
    }

    public function start(Session $session, EventRegistration $registration): EventMemberBenefitQuote
    {
        return $registration->getConnection()->transaction(function () use ($session, $registration): EventMemberBenefitQuote {
            $registration = EventRegistration::withoutGlobalScopes()->where('tenant_id', $registration->tenant_id)->lockForUpdate()->findOrFail($registration->id);
            if (! $registration->member_benefit_required || $registration->status !== EventRegistration::STATUS_PENDING_PAYMENT) {
                $this->refuse('This registration is not ready for member payment.');
            }
            $member = $this->eligibility->resolveLocked($session, $registration->event, $registration->email);
            if ($member === null) {
                $this->refuse('Verify your active personal membership before continuing.');
            }
            $identity = $this->identity->capture($session, $member);
            $old = EventMemberBenefitQuote::withoutGlobalScopes()->where('tenant_id', $registration->tenant_id)->find($registration->member_benefit_quote_id);
            if ($old !== null && $old->state === 'quoted' && $old->expires_at->isFuture()) {
                $this->assertUsable($old, $registration);

                return $old;
            }
            if ($old !== null && $old->attempts()->whereNotIn('state', ['failed', 'refunded'])->exists()) {
                $this->refuse('Your previous payment needs a confirmed outcome. Do not pay again.');
            }
            $pricing = $this->pricing($registration);
            if ($pricing['amount'] !== $registration->amount || $pricing['fees']['charged_amount'] !== $registration->effectiveChargedAmount()) {
                $this->refuse('The price has changed. Review and accept the current total before paying.');
            }
            $registration->fill(['charged_amount' => $pricing['fees']['charged_amount'], 'platform_fee_amount' => $pricing['fees']['platform_fee']]);
            $quote = EventMemberBenefitQuote::query()->create([
                'tenant_id' => $registration->tenant_id, 'event_id' => $registration->event_id, 'registration_id' => $registration->id,
                'member_id' => $member->id, 'identity' => $identity, 'pricing' => $pricing, 'amount' => $registration->amount,
                'currency' => $registration->currency, 'platform_fee_amount' => $pricing['fees']['platform_fee'],
                'charged_amount' => $pricing['fees']['charged_amount'], 'cart_fingerprint' => $this->fingerprint($registration),
                'expires_at' => now()->addMinutes(15), 'state' => 'quoted',
            ]);
            $registration->update(['member_benefit_quote_id' => $quote->id, 'member_benefit_identity' => $identity]);

            return $quote;
        });
    }

    public function assertUsable(EventMemberBenefitQuote $quote, EventRegistration $registration): void
    {
        $member = Member::withoutGlobalScopes()->where('tenant_id', $quote->tenant_id)->find($quote->member_id);
        if ($quote->tenant_id !== $registration->tenant_id || $quote->event_id !== $registration->event_id || $quote->registration_id !== $registration->id
            || $quote->expires_at->isPast() || $quote->expires_at->equalTo(now()) || $quote->state !== 'quoted'
            || ! hash_equals($quote->cart_fingerprint, $this->fingerprint($registration)) || $member === null || ! $this->identity->valid($quote->identity, $member, false)) {
            $this->refuse('This quote is no longer usable. Verify your membership and review your payment.');
        }
    }

    public function fingerprint(EventRegistration $registration): string
    {
        return hash('sha256', json_encode([$registration->tenant_id, $registration->event_id, $registration->id, $registration->ticket_type_id,
            mb_strtolower(mb_trim($registration->email)), $registration->form_answers, $registration->promo_code_id,
            $registration->amount, $registration->discount_amount, $registration->currency, $registration->charged_amount, $registration->platform_fee_amount], JSON_THROW_ON_ERROR));
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['membership' => $message]);
    }
}
