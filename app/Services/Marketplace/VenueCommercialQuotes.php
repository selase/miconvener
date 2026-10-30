<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Http\Requests\Tenant\Venue\IssueVenueFinancialQuoteRequest;
use App\Models\StoreCommercialAgreement;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueFinancialDecision;
use App\Models\VenueFinancialQuote;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Finance\FeeCalculator;
use App\Services\Finance\FeeTerms;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The host's final price for an approved booking and the buyer's acceptance of
 * it. A quote is an immutable, versioned offer; withdrawing and accepting are
 * separate records. Acceptance creates the unpaid orders for each payment and
 * nothing else: it takes no money, issues no receipt and posts no ledger entry,
 * except that a contract with no rental to pay confirms the booking.
 */
final class VenueCommercialQuotes
{
    private const ISSUE_FIELDS = ['rental_pesewas', 'extras_pesewas', 'security_pesewas', 'payment_plan', 'deposit_pesewas', 'balance_due_at', 'security_due_at', 'cancellation_terms', 'support_contact'];

    public function __construct(
        private readonly CommerceOrderLocks $locks,
        private readonly StoreCommercialTerms $terms,
        private readonly FeeCalculator $fees,
        private readonly VenuePaymentRollout $rollout,
        private readonly VenueAvailabilityService $availability,
        private readonly VenueBookingApprovals $approvals,
        private readonly VenueCharges $charges,
        private readonly PlatformAttendeeVerification $verification,
    ) {}

    /**
     * The quote the buyer may currently accept, if any.
     */
    public function activeQuote(VenueBooking $booking): ?VenueFinancialQuote
    {
        return VenueFinancialQuote::query()->where('booking_id', $booking->id)
            ->whereDoesntHave('decisions', fn ($decisions) => $decisions->whereIn('decision', [VenueFinancialDecision::ACCEPTED, VenueFinancialDecision::WITHDRAWN]))
            ->orderByDesc('version')->first();
    }

    /**
     * Issue a new version of the final quote, replacing any open one.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function issue(VenueBooking $booking, User $actor, array $input, string $operationKey): VenueFinancialQuote
    {
        $data = $this->validated($input, $operationKey);
        $requestHash = hash('sha256', json_encode([$booking->id, $data], JSON_THROW_ON_ERROR));

        return $this->locks->forBooking($booking->id, function (StoreListing $listing, VenueBooking $locked) use ($actor, $data, $operationKey, $requestHash): VenueFinancialQuote {
            $this->approvals->assertHost($locked, $actor);
            $prior = VenueFinancialQuote::query()->where('shop_id', $locked->shop_id)->where('operation_key', $operationKey)->first();
            if ($prior !== null) {
                if ($prior->booking_id !== $locked->id || ! hash_equals($prior->request_hash, $requestHash)) {
                    throw ValidationException::withMessages(['operation_key' => 'This request key was already used for a different quote.']);
                }

                return $prior;
            }

            $tenant = Tenant::query()->findOrFail($locked->tenant_id);
            $this->assertOpen($tenant, $locked);
            if ($this->accepted($locked) !== null) {
                $this->refuse('This booking already has an accepted contract. A paid amendment is a separate workflow.');
            }

            $agreement = $this->terms->resolve($tenant, StoreCommercialAgreement::SCOPE_RESERVATION);
            if ($agreement->fee_bearer !== 'organizer') {
                $this->refuse('Venue payments cannot add a buyer surcharge. Ask a platform administrator to review your venue agreement.');
            }

            $plan = $this->plan($data, $locked, $agreement);
            $hold = VenueBookingAction::query()->where('booking_id', $locked->id)->whereIn('action', [VenueBookingAction::ACTION_APPROVED, VenueBookingAction::ACTION_RENEWED])
                ->where('hold_expires_at', $locked->hold_expires_at)->orderByDesc('created_at')->orderByDesc('id')->first();
            if ($hold === null) {
                $this->refuse('Approve the dates again before quoting.');
            }

            $version = ((int) VenueFinancialQuote::query()->where('booking_id', $locked->id)->max('version')) + 1;
            $open = $this->activeQuote($locked);
            if ($open !== null) {
                $this->decide($open, VenueFinancialDecision::WITHDRAWN, 'host', $actor, null, "Replaced by version {$version}.", null, (string) \Illuminate\Support\Str::uuid(), hash('sha256', "replace:{$open->id}:{$version}"));
            }

            $snapshot = $this->snapshot($locked, $agreement, $plan, $data);

            return VenueFinancialQuote::query()->create([
                'tenant_id' => $locked->tenant_id, 'shop_id' => $locked->shop_id, 'booking_id' => $locked->id, 'version' => $version,
                'agreement_id' => $agreement->id, 'agreement_scope' => StoreCommercialAgreement::SCOPE_RESERVATION,
                'hold_action_id' => $hold->id, 'hold_expires_at' => $locked->hold_expires_at,
                'currency' => 'GHS', 'provider' => $agreement->provider, 'account_fingerprint' => $agreement->account_fingerprint,
                'rental_pesewas' => $data['rental_pesewas'], 'extras_pesewas' => $plan['extras'], 'security_pesewas' => $plan['security'],
                'initial_pesewas' => $plan['initial'], 'balance_pesewas' => $plan['balance'],
                'contract_fee_pesewas' => $plan['contract_fee'], 'initial_fee_pesewas' => $plan['initial_fee'], 'balance_fee_pesewas' => $plan['balance_fee'],
                'event_starts_at' => $locked->starts_at, 'event_ends_at' => $locked->ends_at, 'expires_at' => $plan['expires_at'],
                'balance_due_at' => $plan['balance_due_at'], 'security_due_at' => $plan['security_due_at'],
                'cancellation_terms' => $data['cancellation_terms'], 'support_contact' => $data['support_contact'],
                'issued_by' => $actor->id, 'issuer_name' => $actor->name, 'operation_key' => $operationKey, 'request_hash' => $requestHash,
                'terms_hash' => hash('sha256', json_encode($snapshot['terms'], JSON_THROW_ON_ERROR)), 'snapshot' => $snapshot,
            ]);
        });
    }

    /**
     * @throws ValidationException
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function withdraw(VenueFinancialQuote $quote, User $actor, string $reason, string $operationKey): VenueFinancialDecision
    {
        $reason = mb_trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500 || ! \Illuminate\Support\Str::isUuid($operationKey)) {
            $this->refuse('Give a reason and a valid request key.');
        }
        $requestHash = hash('sha256', "withdraw:{$quote->id}:{$reason}");

        return $this->locks->forBooking($quote->booking_id, function (StoreListing $listing, VenueBooking $booking) use ($quote, $actor, $reason, $operationKey, $requestHash): VenueFinancialDecision {
            $this->approvals->assertHost($booking, $actor);
            $replay = $this->replay($quote, $operationKey, $requestHash);
            if ($replay !== null) {
                return $replay;
            }
            if ($this->outcome($quote) !== null) {
                $this->refuse('This quote has already been accepted or withdrawn.');
            }

            return $this->decide($quote, VenueFinancialDecision::WITHDRAWN, 'host', $actor, null, $reason, null, $operationKey, $requestHash);
        });
    }

    /**
     * The verified requester accepts the exact terms of a live quote.
     *
     * @throws ValidationException
     */
    public function accept(VenueFinancialQuote $quote, Session $session, string $acceptedHash, string $operationKey): VenueFinancialDecision
    {
        $email = $this->verification->verifiedEmail($session);
        $requestHash = hash('sha256', "accept:{$quote->id}:{$acceptedHash}");
        if ($email === null || ! \Illuminate\Support\Str::isUuid($operationKey)) {
            $this->refuse('Verify the email address used for this request first.');
        }

        return $this->locks->forBooking($quote->booking_id, function (StoreListing $listing, VenueBooking $booking) use ($quote, $email, $acceptedHash, $operationKey, $requestHash): VenueFinancialDecision {
            if (! hash_equals(PlatformAttendeeVerification::normalise($booking->planner_email), PlatformAttendeeVerification::normalise($email))) {
                $this->refuse('Verify the email address used for this request first.');
            }
            $replay = $this->replay($quote, $operationKey, $requestHash);
            if ($replay !== null) {
                return $replay;
            }

            $tenant = Tenant::query()->findOrFail($booking->tenant_id);
            $this->assertOpen($tenant, $booking);
            if ($this->outcome($quote) !== null || ! hash_equals($quote->terms_hash, $acceptedHash)) {
                $this->refuse('This quote is no longer available. Ask the host for the current one.');
            }
            if ($quote->expires_at->isPast() || ! $quote->hold_expires_at->equalTo($booking->hold_expires_at)) {
                $this->refuse('This quote has expired. Ask the host to quote again.');
            }
            if (! $this->availability->isSlotAvailable($listing, $booking->starts_at, $booking->ends_at, $booking->id)) {
                $this->refuse('Those dates are no longer available.');
            }

            $decision = $this->decide($quote, VenueFinancialDecision::ACCEPTED, 'buyer', null, PlatformAttendeeVerification::normalise($email), null, $quote->terms_hash, $operationKey, $requestHash);
            foreach ($this->charges->dueFor($quote) as $purpose) {
                $this->charges->order($quote, $purpose);
            }
            if ($quote->initial_pesewas === 0) {
                $booking->update(['status' => VenueBooking::STATUS_CONFIRMED]);
            }

            return $decision;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input, string $operationKey): array
    {
        $request = new IssueVenueFinancialQuoteRequest;
        $data = Validator::make($input + ['operation_key' => $operationKey], $request->rules(), $request->messages())->validate();
        unset($data['operation_key']);
        $data['cancellation_terms'] = mb_trim($data['cancellation_terms']);
        $data['support_contact'] = mb_trim($data['support_contact']);
        $total = $data['rental_pesewas'] + ($data['extras_pesewas'] ?? 0) + ($data['security_pesewas'] ?? 0);
        if ($total > 9007199254740991) {
            $this->refuse('These amounts are too large.');
        }

        return array_intersect_key($data, array_flip(self::ISSUE_FIELDS)) + array_fill_keys(self::ISSUE_FIELDS, null);
    }

    private function assertOpen(Tenant $tenant, VenueBooking $booking): void
    {
        if (! $this->rollout->enabled($tenant)) {
            $this->refuse('Venue payments are not available for this organisation.');
        }
        if (! $booking->usesApprovalFlow() || $booking->kind !== VenueBooking::KIND_MARKETPLACE || $booking->status !== VenueBooking::STATUS_APPROVED_HOLD
            || $booking->hold_expires_at === null || $booking->hold_expires_at->isPast() || $booking->starts_at->isPast()) {
            $this->refuse('Only a booking with a live approved hold can be quoted or accepted.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{extras: int, security: int, initial: int, balance: int, contract_fee: int, initial_fee: int, balance_fee: int, expires_at: CarbonInterface, balance_due_at: ?CarbonInterface, security_due_at: ?CarbonInterface}
     */
    private function plan(array $data, VenueBooking $booking, StoreCommercialAgreement $agreement): array
    {
        $extras = (int) ($data['extras_pesewas'] ?? 0);
        $security = (int) ($data['security_pesewas'] ?? 0);
        $total = $data['rental_pesewas'] + $extras;
        $initial = $data['payment_plan'] === 'full' ? $total : (int) $data['deposit_pesewas'];
        if ($initial > $total && ! ($total === 0 && $initial === 0)) {
            $this->refuse('A deposit cannot be more than the rental.');
        }
        $balance = $total - $initial;

        $balanceDue = $balance > 0 ? $this->deadline($data['balance_due_at'], $booking, false, 'balance') : null;
        $securityDue = $security > 0 ? $this->deadline($data['security_due_at'], $booking, true, 'security') : null;

        $terms = new FeeTerms((float) $agreement->percentage, $agreement->cap_pesewas, 'organizer', (float) $agreement->gateway_estimate_rate);
        $contractFee = $this->fees->forTerms($total, $terms)->platformFee;
        $initialFee = min($contractFee, $this->fees->forTerms($initial, $terms)->platformFee);

        return [
            'extras' => $extras, 'security' => $security, 'initial' => $initial, 'balance' => $balance,
            'contract_fee' => $contractFee, 'initial_fee' => $initialFee, 'balance_fee' => $contractFee - $initialFee,
            'expires_at' => $booking->hold_expires_at->min($booking->starts_at), 'balance_due_at' => $balanceDue, 'security_due_at' => $securityDue,
        ];
    }

    private function deadline(mixed $value, VenueBooking $booking, bool $mayEqualStart, string $label): CarbonInterface
    {
        if (! is_string($value) || $value === '') {
            $this->refuse("Set a due date for the {$label}.");
        }
        $at = CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        $beforeStart = $mayEqualStart ? $at->lessThanOrEqualTo($booking->starts_at) : $at->lessThan($booking->starts_at);
        if ($at->isPast() || ! $beforeStart) {
            $this->refuse("The {$label} must be due in the future and before the event starts.");
        }

        return $at;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $data
     * @return array{terms: array<string, mixed>, venue: string|null, host: string|null}
     */
    private function snapshot(VenueBooking $booking, StoreCommercialAgreement $agreement, array $plan, array $data): array
    {
        $utc = fn (?CarbonInterface $at): ?string => $at?->toImmutable()->utc()->toIso8601String();
        $terms = [
            'booking' => $booking->booking_reference, 'currency' => 'GHS',
            'amounts' => ['rental' => $data['rental_pesewas'], 'extras' => $plan['extras'], 'security' => $plan['security'], 'initial' => $plan['initial'], 'balance' => $plan['balance']],
            'fees' => ['contract' => $plan['contract_fee'], 'initial' => $plan['initial_fee'], 'balance' => $plan['balance_fee']],
            'dates' => ['starts_at' => $utc($booking->starts_at), 'ends_at' => $utc($booking->ends_at), 'expires_at' => $utc($plan['expires_at']),
                'balance_due_at' => $utc($plan['balance_due_at']), 'security_due_at' => $utc($plan['security_due_at'])],
            'cancellation_terms' => $data['cancellation_terms'], 'support_contact' => $data['support_contact'],
            'agreement' => $agreement->only(['id', 'version', 'percentage', 'cap_pesewas', 'fee_bearer', 'refund_gateway_fee_bearer', 'receipt_terms']),
        ];

        return ['terms' => $terms, 'venue' => $booking->listing?->title, 'host' => $booking->shop?->name];
    }

    private function accepted(VenueBooking $booking): ?VenueFinancialDecision
    {
        return VenueFinancialDecision::query()->where('booking_id', $booking->id)->where('decision', VenueFinancialDecision::ACCEPTED)->first();
    }

    private function outcome(VenueFinancialQuote $quote): ?VenueFinancialDecision
    {
        return VenueFinancialDecision::query()->where('quote_id', $quote->id)->whereIn('decision', [VenueFinancialDecision::ACCEPTED, VenueFinancialDecision::WITHDRAWN])->first();
    }

    private function replay(VenueFinancialQuote $quote, string $operationKey, string $requestHash): ?VenueFinancialDecision
    {
        $prior = VenueFinancialDecision::query()->where('quote_id', $quote->id)->where('operation_key', $operationKey)->first();
        if ($prior === null) {
            return null;
        }
        if (! hash_equals($prior->request_hash, $requestHash)) {
            $this->refuse('This request key was already used for a different decision.');
        }

        return $prior;
    }

    private function decide(VenueFinancialQuote $quote, string $decision, string $actorType, ?User $actor, ?string $buyerEmail, ?string $reason, ?string $termsHash, string $operationKey, string $requestHash): VenueFinancialDecision
    {
        return VenueFinancialDecision::query()->create([
            'quote_id' => $quote->id, 'booking_id' => $quote->booking_id, 'shop_id' => $quote->shop_id, 'tenant_id' => $quote->tenant_id,
            'decision' => $decision, 'actor_type' => $actorType, 'actor_id' => $actor?->id, 'actor_name' => $actor?->name,
            'buyer_email' => $buyerEmail, 'reason' => $reason, 'terms_hash' => $termsHash, 'operation_key' => $operationKey, 'request_hash' => $requestHash,
        ]);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['quote' => $message]);
    }
}
