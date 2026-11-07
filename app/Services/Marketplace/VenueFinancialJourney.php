<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreOrder;
use App\Models\StoreRefund;
use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Models\VenueBookingCharge;
use App\Models\VenueFinancialDecision;
use App\Models\VenueFinancialQuote;
use App\Models\VenueQuoteNotice;
use App\Models\VenueSettlementReserve;

/**
 * What a buyer or host may see of a booking's final quote and payments. Money
 * state comes only from the persisted orders and decisions; the quote's terms
 * hash is shown only while the quote can still be accepted, and the commission
 * allocation is shown to the host alone.
 */
final class VenueFinancialJourney
{
    public function __construct(private readonly VenueCommercialQuotes $quotes, private readonly VenuePaymentRollout $rollout) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forBuyer(VenueBooking $booking): ?array
    {
        return $this->build($booking, false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forHost(VenueBooking $booking): ?array
    {
        return $this->build($booking, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build(VenueBooking $booking, bool $host): ?array
    {
        $quote = $this->quotes->activeQuote($booking) ?? VenueFinancialQuote::query()->where('booking_id', $booking->id)->orderByDesc('version')->first();
        if ($quote === null) {
            return null;
        }
        $decisions = VenueFinancialDecision::query()->where('quote_id', $quote->id)->get()->keyBy('decision');
        $accepted = $decisions->get(VenueFinancialDecision::ACCEPTED);
        $withdrawn = $decisions->get(VenueFinancialDecision::WITHDRAWN);
        $tenant = Tenant::query()->findOrFail($booking->tenant_id);
        $timezone = $booking->approvalPolicy()['timezone'];
        $expired = $accepted === null && $withdrawn === null && ! $quote->expires_at->isFuture();
        $status = $accepted !== null ? 'accepted' : ($withdrawn !== null ? 'withdrawn' : ($expired ? 'expired' : 'open'));

        $charges = VenueBookingCharge::query()->with(['order'])->where('quote_id', $quote->id)->orderByRaw("array_position(array['rental_initial','rental_balance','security'], purpose::text)")->get();
        $rows = $charges->map(fn (VenueBookingCharge $charge): array => $this->charge($charge, $booking, $tenant, $host))->values()->all();
        $paid = fn (string $purpose): int => (int) $charges->filter(fn (VenueBookingCharge $c): bool => $c->purpose === $purpose && in_array($c->order->payment_state, ['paid', 'refund_pending'], true))->sum('amount_pesewas');
        $refunded = (int) $charges->filter(fn (VenueBookingCharge $c): bool => $c->order->payment_state === 'refunded')->sum('amount_pesewas');

        $payload = [
            'quote' => [
                'id' => $quote->id, 'version' => $quote->version, 'status' => $status, 'timezone' => $timezone,
                'terms_hash' => $status === 'open' ? $quote->terms_hash : null,
                'accepted_terms_hash' => $status === 'accepted' ? $quote->terms_hash : null,
                'rental_pesewas' => $quote->rental_pesewas, 'extras_pesewas' => $quote->extras_pesewas, 'security_pesewas' => $quote->security_pesewas,
                'contract_total_pesewas' => $quote->rental_pesewas + $quote->extras_pesewas,
                'initial_pesewas' => $quote->initial_pesewas, 'balance_pesewas' => $quote->balance_pesewas,
                'expires_at' => $quote->expires_at->toIso8601String(), 'hold_expires_at' => $quote->hold_expires_at?->toIso8601String(),
                'balance_due_at' => $quote->balance_due_at?->toIso8601String(), 'security_due_at' => $quote->security_due_at?->toIso8601String(),
                'cancellation_terms' => $quote->cancellation_terms, 'support_contact' => $quote->support_contact,
                'accepted_at' => $accepted?->created_at?->toIso8601String(), 'withdrawn_reason' => $withdrawn?->reason,
            ],
            'payments_open' => $this->rollout->enabled($tenant),
            'charges' => $rows,
            'totals' => [
                'rental_received_pesewas' => $paid(VenueBookingCharge::RENTAL_INITIAL) + $paid(VenueBookingCharge::RENTAL_BALANCE),
                'security_held_pesewas' => $paid(VenueBookingCharge::SECURITY),
                'refunded_pesewas' => $refunded,
            ],
        ];
        if ($host) {
            $payload['quote']['commission_pesewas'] = $quote->contract_fee_pesewas;
            $payload['settlement'] = $this->settlement($booking);
            $payload['notices'] = VenueQuoteNotice::query()->where('quote_id', $quote->id)->orderBy('created_at')->get()
                ->map(fn (VenueQuoteNotice $notice): array => ['kind' => $notice->kind, 'state' => $notice->state, 'attempts' => $notice->attempts, 'next_attempt_at' => $notice->next_attempt_at?->toIso8601String()])->values()->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(VenueBookingCharge $charge, VenueBooking $booking, Tenant $tenant, bool $host): array
    {
        $order = $charge->order;
        $state = $this->state($charge, $order);
        $row = [
            'id' => $charge->id, 'purpose' => $charge->purpose, 'amount_pesewas' => $charge->amount_pesewas,
            'due_at' => $charge->due_at?->toIso8601String(), 'expires_at' => $order->expires_at->toIso8601String(),
            'state' => $state, 'paid_at' => $order->paid_at?->toIso8601String(),
            'can_pay' => in_array($state, ['due', 'overdue'], true) && $this->rollout->enabled($tenant)
                && ($charge->purpose === VenueBookingCharge::RENTAL_INITIAL ? $booking->status === VenueBooking::STATUS_APPROVED_HOLD : $booking->status === VenueBooking::STATUS_CONFIRMED),
            'has_receipt' => $order->receipt()->exists(),
        ];
        if ($host) {
            $row['fee_pesewas'] = $charge->fee_pesewas;
            $row['order_id'] = $order->id;
            $row['refund'] = $this->refund($order);
        }

        return $row;
    }

    private function state(VenueBookingCharge $charge, StoreOrder $order): string
    {
        return match ($order->payment_state) {
            'paid' => 'paid',
            'refund_pending' => 'refund_pending',
            'refunded' => 'refunded',
            'captured_review' => 'under_review',
            default => ! $order->expires_at->isFuture() ? 'expired' : ($charge->due_at !== null && ! $charge->due_at->isFuture() ? 'overdue' : 'due'),
        };
    }

    /**
     * @return array{id: string, status: string}|null
     */
    private function refund(StoreOrder $order): ?array
    {
        $refund = StoreRefund::query()->where('order_id', $order->id)->latest()->first();

        return $refund === null ? null : ['id' => $refund->id, 'status' => $refund->status];
    }

    /**
     * @return array{held_pesewas: int, released_pesewas: int, completed: bool}
     */
    private function settlement(VenueBooking $booking): array
    {
        $reserves = VenueSettlementReserve::query()->where('booking_id', $booking->id)->get();

        return [
            'held_pesewas' => (int) $reserves->where('state', VenueSettlementReserve::HELD)->sum('reserved_pesewas'),
            'released_pesewas' => (int) $reserves->where('state', VenueSettlementReserve::RELEASED)->sum('reserved_pesewas'),
            'completed' => VenueFinancialDecision::query()->where('booking_id', $booking->id)->where('decision', VenueFinancialDecision::COMPLETED)->exists(),
        ];
    }
}
