<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Http\Controllers\Billing\WebhookController;
use App\Models\StoreOrder;
use App\Models\StoreOrderLine;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueBookingCharge;
use App\Models\VenuePaymentResolution;
use App\Support\WithoutTelescope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Finance-led handling of money the older venue payment path could not honour.
 * A conflict row only records what the provider callback claimed, so nothing is
 * decided until the platform account itself confirms the capture. Every step is
 * appended, never edited, and none of them marks a booking paid: money the older
 * path already posted stays blocked, and verified unposted money is refunded.
 */
final class VenuePaymentReconciliation
{
    public const DECISIONS = ['retain', 'refund', 'confirm'];

    public function __construct(
        private readonly CommerceOrderLocks $locks,
        private readonly StoreGateways $gateways,
        private readonly CommercePaymentReferences $references,
        private readonly StoreCommercialTerms $terms,
        private readonly StorePaymentEvidence $evidence,
        private readonly StorePaymentConfirmer $confirmer,
        private readonly StoreRefunds $refunds,
    ) {}

    /**
     * Re-check a conflict's payment with the platform account and keep the result.
     */
    public function verify(VenueBookingAction $action, User $actor, string $operationKey): VenuePaymentResolution
    {
        $action = $this->conflict($action);
        $this->authorize($actor, $action->tenant_id);
        $this->assertKey($operationKey);
        $requestHash = hash('sha256', json_encode(['verify', $action->id], JSON_THROW_ON_ERROR));
        $prior = $this->prior($action, $operationKey, $requestHash);
        if ($prior !== null) {
            return $prior;
        }

        $reference = (string) $action->evidence['provider_reference'];
        $capture = $this->capture($action, $reference);

        return $this->locks->forBooking($action->booking_id, function () use ($action, $actor, $operationKey, $requestHash, $capture): VenuePaymentResolution {
            $prior = $this->prior($action, $operationKey, $requestHash);
            if ($prior !== null) {
                return $prior;
            }
            $this->assertOpen($action);

            return $this->append($action, $actor, VenuePaymentResolution::VERIFIED, $operationKey, $requestHash, null, $capture['amount'], $capture);
        });
    }

    /**
     * Retain, refund or (never, in this release) confirm verified money.
     */
    public function resolve(VenuePaymentResolution $verified, User $actor, string $decision, string $reason, string $operationKey): VenuePaymentResolution
    {
        $action = $this->conflict(VenueBookingAction::query()->findOrFail($verified->action_id));
        $this->authorize($actor, $action->tenant_id);
        $this->assertKey($operationKey);
        $reason = mb_trim($reason);
        if (! in_array($decision, self::DECISIONS, true) || $reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['resolution' => 'Choose retain or refund and give a reason.']);
        }
        if ($verified->step !== VenuePaymentResolution::VERIFIED || $verified->verified_amount_pesewas === null) {
            throw ValidationException::withMessages(['resolution' => 'Only verified money can be resolved.']);
        }
        $requestHash = hash('sha256', json_encode([$decision, $action->id, $verified->id, $reason], JSON_THROW_ON_ERROR));
        $prior = $this->prior($action, $operationKey, $requestHash);
        if ($prior !== null) {
            return $prior;
        }

        return match ($decision) {
            'retain' => $this->retain($action, $verified, $actor, $reason, $operationKey, $requestHash),
            'refund' => $this->refund($action, $verified, $actor, $reason, $operationKey, $requestHash),
            default => throw ValidationException::withMessages(['resolution' => 'Captured money from the older payment path cannot be confirmed as a booking payment. Refund it, and ask the buyer to accept a new quote.']),
        };
    }

    private function retain(VenueBookingAction $action, VenuePaymentResolution $verified, User $actor, string $reason, string $operationKey, string $requestHash): VenuePaymentResolution
    {
        return $this->locks->forBooking($action->booking_id, function () use ($action, $verified, $actor, $reason, $operationKey, $requestHash): VenuePaymentResolution {
            $prior = $this->prior($action, $operationKey, $requestHash);
            if ($prior !== null) {
                return $prior;
            }
            $this->assertOpen($action);

            return $this->append($action, $actor, VenuePaymentResolution::RETAINED, $operationKey, $requestHash, $reason, $verified->verified_amount_pesewas, ['verification_id' => $verified->id]);
        });
    }

    private function refund(VenueBookingAction $action, VenuePaymentResolution $verified, User $actor, string $reason, string $operationKey, string $requestHash): VenuePaymentResolution
    {
        $evidence = $verified->evidence ?? [];
        if (($evidence['classification'] ?? null) !== 'unposted') {
            throw ValidationException::withMessages(['resolution' => 'This payment was already posted by the older payment path. It stays blocked until a category-aware refund is available.']);
        }
        $fresh = $this->capture($action, $verified->reference);
        if ($fresh['amount'] !== $verified->verified_amount_pesewas) {
            throw ValidationException::withMessages(['resolution' => 'The payment no longer matches its verification. Verify it again.']);
        }

        $order = $this->locks->forBooking($action->booking_id, function () use ($action, $verified, $fresh): StoreOrder {
            $this->assertOpen($action);

            return $this->import($action, $verified, $fresh);
        });
        $refund = $this->refunds->request($order, $actor, $operationKey, $reason, StorePaymentObservation::query()->where('order_id', $order->id)->value('id'));

        return $this->locks->forBooking($action->booking_id, function () use ($action, $verified, $actor, $reason, $operationKey, $requestHash, $order, $refund): VenuePaymentResolution {
            $prior = $this->prior($action, $operationKey, $requestHash);
            if ($prior !== null) {
                return $prior;
            }

            return $this->append($action, $actor, VenuePaymentResolution::REFUNDED, $operationKey, $requestHash, $reason, $verified->verified_amount_pesewas, ['order_id' => $order->id, 'refund_id' => $refund->id, 'refund_status' => $refund->status]);
        });
    }

    /**
     * Carry verified, never-posted money into the common ledger as a review
     * liability, once. The original reference, amount and paid time are kept; no
     * contract, acceptance or commission is invented for it.
     *
     * @param  array<string, mixed>  $capture
     */
    private function import(VenueBookingAction $action, VenuePaymentResolution $verified, array $capture): StoreOrder
    {
        $reference = $verified->reference;
        $existing = StorePaymentAttempt::query()->where('reference', $reference)->first();
        if ($existing !== null) {
            return $this->importedOrder($existing, $action);
        }

        $booking = VenueBooking::query()->findOrFail($action->booking_id);
        $tenant = Tenant::query()->findOrFail($booking->tenant_id);
        $agreement = $this->agreementFor($tenant);
        $amount = (int) $capture['amount'];
        $terms = $agreement->only(['id', 'version', 'refund_gateway_fee_bearer', 'support_contact']) + ['import_origin' => 'legacy_reconciliation', 'action_id' => $action->id];

        $order = StoreOrder::query()->create([
            'tenant_id' => $booking->tenant_id, 'shop_id' => $booking->shop_id, 'agreement_id' => $agreement->id,
            'buyer_email' => mb_strtolower((string) $booking->planner_email),
            'submission_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, "legacy-import:{$reference}")->toString(),
            'cart_hash' => hash('sha256', "legacy-import:{$reference}"), 'currency' => 'GHS', 'offering_type' => 'reservation',
            'principal_pesewas' => $amount, 'platform_fee_pesewas' => 0, 'gateway_estimate_pesewas' => 0, 'charged_pesewas' => $amount,
            'fee_bearer' => 'organizer', 'settlement_mode' => $agreement->settlement_mode, 'provider' => $agreement->provider,
            'account_fingerprint' => $this->gateways->platformFingerprint(),
            'seller_snapshot' => ['name' => (string) $booking->shop?->name], 'terms_snapshot' => $terms, 'terms_hash' => hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR)),
            'expires_at' => $capture['paid_at'],
        ]);
        StoreOrderLine::query()->create([
            'order_id' => $order->id, 'shop_id' => $booking->shop_id, 'variant_id' => null, 'booking_id' => $booking->id, 'listing_id' => $booking->store_listing_id,
            'offering_type' => 'reservation', 'title_snapshot' => 'Venue booking payment held for review', 'sku_snapshot' => 'RESERVATION', 'options_snapshot' => [],
            'quantity' => 1, 'unit_price_pesewas' => $amount, 'line_amount_pesewas' => $amount,
        ]);
        VenueBookingCharge::query()->create([
            'tenant_id' => $booking->tenant_id, 'shop_id' => $booking->shop_id, 'booking_id' => $booking->id, 'quote_id' => null,
            'order_id' => $order->id, 'purpose' => VenueBookingCharge::LEGACY_REVIEW, 'amount_pesewas' => $amount, 'fee_pesewas' => 0,
        ]);
        $this->references->claimForLegacyBooking($booking, $order->provider, $order->account_fingerprint, $reference);
        $attempt = StorePaymentAttempt::query()->create([
            'order_id' => $order->id, 'tenant_id' => $order->tenant_id, 'shop_id' => $order->shop_id, 'submission_key' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "legacy-attempt:{$reference}"),
            'reference' => $reference, 'provider' => $order->provider, 'settlement_mode' => $order->settlement_mode,
            'account_fingerprint' => $order->account_fingerprint, 'amount_pesewas' => $amount, 'currency' => 'GHS', 'status' => 'ready',
        ]);
        $observation = $this->evidence->record($attempt, $this->verifiedEvidence($capture, $order));
        $this->confirmer->confirm($observation);

        return $order->fresh();
    }

    private function importedOrder(StorePaymentAttempt $attempt, VenueBookingAction $action): StoreOrder
    {
        $order = StoreOrder::query()->findOrFail($attempt->order_id);
        if ($order->tenant_id !== $action->tenant_id || ! VenueBookingCharge::query()->where('order_id', $order->id)->where('booking_id', $action->booking_id)->where('purpose', VenueBookingCharge::LEGACY_REVIEW)->exists()) {
            throw ValidationException::withMessages(['resolution' => 'This provider reference already belongs to another payment source.']);
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $capture
     * @return array<string, mixed>
     */
    private function verifiedEvidence(array $capture, StoreOrder $order): array
    {
        return [
            'status' => 'success', 'provider' => 'paystack', 'reference' => $capture['reference'], 'reference_reported' => true,
            'account_fingerprint' => $order->account_fingerprint, 'amount' => $capture['amount'], 'currency' => 'GHS', 'fees' => $capture['fees'],
            'paid_at' => $capture['paid_at'], 'transaction_id' => $capture['transaction_id'],
            'metadata' => ['venue_booking_id' => $capture['booking_id']],
        ];
    }

    /**
     * The payment as the platform account reports it, or a refusal. Nothing is
     * trusted from the stored conflict row except which reference to ask about.
     *
     * @return array<string, mixed>
     */
    private function capture(VenueBookingAction $action, string $reference): array
    {
        $tenant = Tenant::query()->findOrFail($action->tenant_id);
        if (! $tenant->isPlatformDefaultSettlement()) {
            $this->refuse('The original payment account needs operator review.');
        }
        try {
            $data = WithoutTelescope::run(fn (): array => $this->gateways->platform()->verifyTransaction($reference, true));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            $this->refuse('The payment could not be verified right now. Try again shortly.');
        }
        $metadata = WebhookController::metadata($data['metadata'] ?? null);
        $stored = $action->evidence;
        $amount = $data['amount'] ?? null;
        $fees = ($data['fees_valid'] ?? false) ? $data['fees'] : null;
        $problems = [];
        if (($data['status'] ?? null) !== 'success') {
            $problems[] = 'not_captured';
        }
        if (($data['reference_reported'] ?? false) !== true || ($data['reference'] ?? null) !== $reference) {
            $problems[] = 'reference_mismatch';
        }
        if (! is_int($amount) || $amount < 1 || $amount !== (int) ($stored['amount_pesewas'] ?? -1)) {
            $problems[] = 'amount_mismatch';
        }
        if (($data['currency_reported'] ?? false) !== true || mb_strtoupper((string) $data['currency']) !== 'GHS') {
            $problems[] = 'currency_mismatch';
        }
        if (! is_int($fees) || (is_int($amount) && $fees >= $amount)) {
            $problems[] = 'fees_unverifiable';
        }
        if (($metadata['venue_booking_id'] ?? null) !== $action->booking_id) {
            $problems[] = 'booking_mismatch';
        }
        if (! is_string($data['paid_at'] ?? null) || $data['paid_at'] === '') {
            $problems[] = 'paid_time_unknown';
        }
        if ($problems !== []) {
            $this->refuse('The payment does not match what was recorded ('.implode(', ', $problems).'). It stays under review.');
        }

        return [
            'reference' => $reference, 'booking_id' => $action->booking_id, 'amount' => $amount, 'fees' => $fees, 'currency' => 'GHS',
            'paid_at' => $data['paid_at'], 'transaction_id' => $data['transaction_id'] ?? null,
            'classification' => $this->references->postedByLegacyVenuePath($reference) ? 'posted_legacy' : 'unposted',
        ];
    }

    private function agreementFor(Tenant $tenant): \App\Models\StoreCommercialAgreement
    {
        try {
            return $this->terms->resolve($tenant, \App\Models\StoreCommercialAgreement::SCOPE_RESERVATION);
        } catch (ValidationException) {
            return $this->terms->resolve($tenant);
        }
    }

    private function conflict(VenueBookingAction $action): VenueBookingAction
    {
        $current = VenueBookingAction::query()->findOrFail($action->id);
        if ($current->action !== VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT || ! is_string($current->evidence['provider_reference'] ?? null)) {
            $this->refuse('Only a recorded payment conflict can be reconciled.');
        }

        return $current;
    }

    private function assertOpen(VenueBookingAction $action): void
    {
        if (VenuePaymentResolution::query()->where('action_id', $action->id)->whereIn('step', [VenuePaymentResolution::REFUNDED, VenuePaymentResolution::CONFIRMED])->exists()) {
            $this->refuse('This payment was already resolved.');
        }
    }

    private function prior(VenueBookingAction $action, string $operationKey, string $requestHash): ?VenuePaymentResolution
    {
        $prior = VenuePaymentResolution::query()->where('action_id', $action->id)->where('operation_key', $operationKey)->first();
        if ($prior !== null && ! hash_equals($prior->request_hash, $requestHash)) {
            $this->refuse('This operation key belongs to another request.');
        }

        return $prior;
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    private function append(VenueBookingAction $action, User $actor, string $step, string $operationKey, string $requestHash, ?string $reason, ?int $amount, ?array $evidence): VenuePaymentResolution
    {
        return VenuePaymentResolution::query()->create([
            'tenant_id' => $action->tenant_id, 'shop_id' => $action->shop_id, 'booking_id' => $action->booking_id, 'action_id' => $action->id,
            'step' => $step, 'provider' => 'paystack', 'account_fingerprint' => $this->gateways->platformFingerprint(),
            'reference' => (string) $action->evidence['provider_reference'], 'verified_amount_pesewas' => $amount, 'currency' => 'GHS',
            'reason' => $reason, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'operation_key' => $operationKey,
            'request_hash' => $requestHash, 'evidence' => $evidence,
        ]);
    }

    private function authorize(User $actor, string $tenantId): void
    {
        $member = User::query()->whereKey($actor->id)->where('tenant_id', $tenantId)->exists() || $actor->tenants()->whereKey($tenantId)->exists();
        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenantId);
            $allowed = $member && Gate::forUser($actor->fresh())->allows('manage store finance');
        } finally {
            setPermissionsTeamId($team);
        }
        if (! $allowed) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }
    }

    private function assertKey(string $operationKey): void
    {
        if (! Uuid::isValid($operationKey)) {
            $this->refuse('Provide a valid operation key.');
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['resolution' => $message]);
    }
}
