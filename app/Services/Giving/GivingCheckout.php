<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Enum\CommercialStreamEnum;
use App\Models\EventContribution;
use App\Models\GivingContributionLink;
use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Models\GivingPaymentAttempt;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Finance\CommercialTerms;
use App\Services\Payment\StripeGateway;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class GivingCheckout
{
    public function __construct(private readonly GivingRollout $rollout, private readonly GivingProgress $progress, private readonly GivingGateways $gateways, private readonly CommercialTerms $terms) {}

    /**
     * @param  array<string, mixed>  $validated
     * @param  (Closure(): EventContribution)|null  $createEventSource
     */
    public function start(GivingFund $fund, array $validated, Session $session, string $submissionKey, ?EventContribution $eventSource = null, ?Closure $createEventSource = null): string
    {
        abort_unless($this->rollout->onlineEnabled($fund->tenant), 404);
        if (! Str::isUuid($submissionKey)) {
            throw ValidationException::withMessages(['submission_key' => 'Use a valid submission identifier.']);
        }
        [$attempt, $isNew] = $fund->getConnection()->transaction(function () use ($fund, $validated, $session, $submissionKey, $eventSource, $createEventSource): array {
            $fund = GivingFund::withoutGlobalScopes()->where('tenant_id', $fund->tenant_id)->lockForUpdate()->findOrFail($fund->id);
            $old = GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $fund->tenant_id)->where('submission_key', $submissionKey)->first();
            if ($old !== null) {
                $access = $session->get('giving_checkout_access.'.$old->id);
                if ($old->fund_id !== $fund->id || $old->amount !== ($validated['amount_minor'] ?? null) || $old->provider !== ($validated['provider'] ?? 'paystack') || ! is_array($access) || ! hash_equals((string) $old->receipt_token_hash, hash('sha256', (string) ($access['token'] ?? '')))) {
                    throw ValidationException::withMessages(['submission_key' => 'This submission was already used. Refresh before starting another contribution.']);
                }
                if ($old->state !== 'open' || $old->checkout_url === null) {
                    throw ValidationException::withMessages(['payment' => 'This payment is already being prepared or needs review. Do not pay again.']);
                }

                return [$old, false];
            }
            $this->progress->assertOpen($fund);
            if ($createEventSource !== null) {
                $eventSource = $createEventSource();
            }
            if ($eventSource === null) {
                abort_if($fund->visibility === 'private' || $fund->event_id !== null, 404);
            } else {
                abort_unless($eventSource->tenant_id === $fund->tenant_id && $eventSource->event_id === $fund->event_id && $eventSource->status === EventContribution::STATUS_PENDING_PAYMENT && $eventSource->event->allowsContributions(), 404);
            }
            $amount = $validated['amount_minor'] ?? null;
            if (! is_int($amount) || $amount < $fund->minimum_amount || $amount > 999999999999 || ($validated['currency'] ?? null) !== $fund->currency || ($eventSource !== null && ($eventSource->amount !== $amount || $eventSource->currency !== $fund->currency))) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount at or above the fund minimum in its currency.']);
            }
            $tenant = $fund->tenant;
            $provider = $validated['provider'] ?? 'paystack';
            $identity = $this->gateways->identity($tenant, $provider, $fund->currency);
            $terms = $this->terms->for($tenant, CommercialStreamEnum::Giving);
            $commission = $this->terms->commission($terms, $amount);
            if ($commission >= $amount) {
                throw ValidationException::withMessages(['amount' => 'This amount is too small after commission.']);
            }
            $email = ! empty($validated['payer_email']) ? mb_strtolower(mb_trim($validated['payer_email'])) : null;
            $verified = PlatformAttendeeVerification::getVerifiedEmail($session);
            $giverKey = $email !== null && $verified !== null && $email === $verified ? GivingIdentity::emailKey($tenant, $email) : null;
            $gift = $eventSource === null ? GivingGift::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'amount' => $amount, 'currency' => $fund->currency,
                'source' => 'online', 'status' => 'pending', 'submission_key' => $submissionKey,
                'payer_name' => $validated['payer_name'] ?? null, 'payer_email' => $email, 'payer_phone' => $validated['payer_phone'] ?? null,
                'payer_email_hash' => $email === null ? null : GivingIdentity::emailKey($tenant, $email), 'giver_key' => $giverKey,
                'is_anonymous' => (bool) ($validated['is_anonymous'] ?? false),
            ]) : null;
            if ($eventSource !== null) {
                GivingContributionLink::withoutGlobalScopes()->firstOrCreate(['event_contribution_id' => $eventSource->id], ['tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'origin' => 'checkout', 'category' => 'giving', 'associated_at' => now()]);
            }
            $token = Str::random(64);
            $attempt = GivingPaymentAttempt::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'fund_id' => $fund->id, 'gift_id' => $gift?->id, 'event_contribution_id' => $eventSource?->id,
                'submission_key' => $submissionKey, 'amount' => $amount, 'currency' => $fund->currency,
                'rate' => $terms->rate, 'cap_amount' => $terms->capAmount, 'bearer' => $terms->bearer, 'terms_source' => $terms->source, 'commission_amount' => $commission,
                'provider' => $provider, 'account_identity' => $identity, 'settlement_mode' => $tenant->settlement_mode,
                'reference' => 'giving_'.Str::uuid(), 'category' => 'giving', 'state' => 'initialising',
                'receipt_token_hash' => hash('sha256', $token), 'receipt_expires_at' => now()->addHours(12), 'giver_key' => $giverKey,
            ]);
            $session->put('giving_checkout_access.'.$attempt->id, ['token' => $token, 'expires_at' => now()->addHours(12)->timestamp]);

            return [$attempt, true];
        });
        if (! $isNew) {
            return $attempt->checkout_url;
        }
        $eventSource = $attempt->event_contribution_id === null ? null : EventContribution::withoutGlobalScopes()->where('tenant_id', $attempt->tenant_id)->findOrFail($attempt->event_contribution_id);
        try {
            $prepared = $this->gateways->prepare($attempt);
            $attempt->update(['commission_settlement' => $prepared['settlement']]);
            $gateway = $prepared['gateway'];
            $metadata = [...$prepared['settlement'], 'type' => 'community_gift', 'giving_attempt_id' => $attempt->id,
                'tenant_id' => $attempt->tenant_id, 'fund_id' => $attempt->fund_id, 'local_reference' => $attempt->reference,
                'source_type' => $attempt->gift_id === null ? 'event_contribution' : 'gift', 'source_id' => $attempt->gift_id ?? $attempt->event_contribution_id,
                'source' => config('services.paystack.metadata_source')];
            $callback = $eventSource !== null && isset($validated['callback_url']) ? str_replace(':id', $eventSource->id, $validated['callback_url']) : route('public.giving.callback', ['subdomain' => $attempt->tenant->slug, 'reference' => $attempt->reference]);
            $payerEmail = ($eventSource !== null ? $eventSource->contributor_email : $attempt->gift->payer_email) ?? 'giving+'.$attempt->id.'@miconvener.com';
            $payerName = ($eventSource !== null ? $eventSource->contributor_name : $attempt->gift->payer_name) ?? 'Contributor';
            $providerSession = null;
            $providerReference = null;
            if ($gateway instanceof StripeGateway) {
                $result = $gateway->createConnectedCheckoutSessionResult($payerEmail, $attempt->amount, $attempt->currency, $fund->name, $callback, $callback, array_map(fn ($value): string => (string) $value, $metadata), $prepared['settlement']['commission_collected'], $attempt->id);
                if ($result['session_id'] === '') {
                    throw new RuntimeException('Missing session identity.');
                }
                $url = $result['url'];
                $providerSession = $result['session_id'];
                $providerReference = $result['payment_intent'];
            } else {
                $customer = $gateway->createCustomer($payerEmail, $payerName);
                $url = $gateway->createOneTimeCheckoutSession($customer, $attempt->amount, $attempt->currency, $callback, $metadata, $attempt->reference);
            }
            if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException('Missing hosted checkout.');
            }
            $attempt->getConnection()->transaction(function () use ($attempt, $url, $providerSession, $providerReference): void {
                $locked = GivingPaymentAttempt::withoutGlobalScopes()->lockForUpdate()->findOrFail($attempt->id);
                $locked->update(['state' => $locked->state === 'initialising' ? 'open' : $locked->state, 'checkout_url' => $url,
                    'provider_session_id' => $providerSession ?? $locked->provider_session_id,
                    'provider_reference' => $locked->provider_reference ?? $providerReference]);
            });

            return $url;
        } catch (Throwable) {
            app(GivingPaymentConfirmer::class)->quarantine($attempt, 'initialisation_outcome_unknown');
            throw ValidationException::withMessages(['payment' => 'We could not safely finish preparing this payment. Do not pay again; ask the organisation to check its status.']);
        }
    }
}
