<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Venue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Venue\RefundVenueChargeRequest;
use App\Http\Requests\Tenant\Venue\ResolveVenueConflictRequest;
use App\Http\Requests\Tenant\Venue\VerifyVenueConflictRequest;
use App\Models\StoreRefund;
use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Models\VenueBookingCharge;
use App\Models\VenuePaymentResolution;
use App\Services\Marketplace\StoreRefunds;
use App\Services\Marketplace\VenueMoneyPermissions;
use App\Services\Marketplace\VenuePaymentReconciliation;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The venue money desk: payment conflicts waiting for finance, captured payments
 * that can be refunded, and bookings whose earnings are held. Each action needs
 * its own permission, checked again inside the service in the owning organisation.
 */
final class VenueFinanceController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $this->tenant();
        $user = $request->user();
        $can = [
            'refunds' => Gate::forUser($user)->allows(VenueMoneyPermissions::REFUNDS),
            'reconcile' => Gate::forUser($user)->allows(VenueMoneyPermissions::RECONCILE),
            'settlements' => Gate::forUser($user)->allows(VenueMoneyPermissions::SETTLEMENTS),
        ];
        abort_unless($can['refunds'] || $can['reconcile'] || $can['settlements'], 403);

        return Inertia::render('Tenant/Venue/Finance/Index', [
            'can' => $can,
            'conflicts' => $can['reconcile'] ? $this->conflicts($tenant) : [],
            'payments' => $can['refunds'] ? $this->payments($tenant) : [],
        ]);
    }

    public function verify(VerifyVenueConflictRequest $request, string $subdomain, string $action, VenuePaymentReconciliation $reconciliation): RedirectResponse
    {
        return $this->act(function () use ($request, $action, $reconciliation): string {
            $conflict = VenueBookingAction::query()->where('tenant_id', $this->tenant()->id)->findOrFail($action);
            $reconciliation->verify($conflict, $request->user(), (string) $request->validated('operation_key'));

            return 'The payment was confirmed with the payment account.';
        });
    }

    public function resolve(ResolveVenueConflictRequest $request, string $subdomain, string $resolution, VenuePaymentReconciliation $reconciliation): RedirectResponse
    {
        return $this->act(function () use ($request, $resolution, $reconciliation): string {
            $verified = VenuePaymentResolution::query()->where('tenant_id', $this->tenant()->id)->findOrFail($resolution);
            $reconciliation->resolve($verified, $request->user(), (string) $request->validated('decision'), (string) $request->validated('reason'), (string) $request->validated('operation_key'));

            return $request->validated('decision') === 'refund' ? 'Refund requested. It completes when the payment provider confirms it.' : 'Payment retained for investigation.';
        });
    }

    public function refund(RefundVenueChargeRequest $request, string $subdomain, string $charge, StoreRefunds $refunds): RedirectResponse
    {
        return $this->act(function () use ($request, $charge, $refunds): string {
            $owned = VenueBookingCharge::query()->with('order')->where('tenant_id', $this->tenant()->id)->findOrFail($charge);
            $refund = $refunds->request($owned->order, $request->user(), (string) $request->validated('operation_key'), (string) $request->validated('reason'));

            return $refund->status === 'needs_funding'
                ? 'Refund recorded. It is sent once the organisation balance covers the processing fee.'
                : 'Refund requested. It completes when the payment provider confirms it.';
        });
    }

    public function reconcileRefund(Request $request, string $subdomain, string $refund, StoreRefunds $refunds): RedirectResponse
    {
        return $this->act(function () use ($request, $refund, $refunds): string {
            $owned = StoreRefund::query()->where('tenant_id', $this->tenant()->id)->findOrFail($refund);
            app(VenueMoneyPermissions::class)->authorize($request->user(), $owned->tenant_id, VenueMoneyPermissions::REFUNDS);
            $refunds->reconcile($owned);

            return 'Refund status refreshed.';
        });
    }

    /**
     * @param  Closure(): string  $action
     */
    private function act(Closure $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            return redirect()->back()->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return redirect()->back()->with('success', $message);
    }

    private function tenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_if($tenant === null, 404, 'Tenant not found');

        return $tenant;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conflicts(Tenant $tenant): array
    {
        return VenueBookingAction::query()->where('tenant_id', $tenant->id)->where('action', VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT)
            ->latest()->limit(50)->get()->map(function (VenueBookingAction $action): array {
                $steps = VenuePaymentResolution::query()->where('action_id', $action->id)->orderBy('created_at')->get();
                $verified = $steps->where('step', VenuePaymentResolution::VERIFIED)->last();
                $booking = VenueBooking::query()->find($action->booking_id);

                return [
                    'id' => $action->id, 'booking_reference' => $booking?->booking_reference, 'booking_status' => $booking?->status,
                    'reason' => $action->decision_reason, 'received_at' => $action->created_at?->toIso8601String(),
                    'reference' => $action->evidence['provider_reference'] ?? null, 'amount_pesewas' => $action->evidence['amount_pesewas'] ?? null,
                    'verified_resolution_id' => $verified?->id, 'classification' => $verified?->evidence['classification'] ?? null,
                    'resolved' => $steps->contains(fn (VenuePaymentResolution $step): bool => in_array($step->step, [VenuePaymentResolution::REFUNDED, VenuePaymentResolution::CONFIRMED], true)),
                    'steps' => $steps->map(fn (VenuePaymentResolution $step): array => ['step' => $step->step, 'reason' => $step->reason, 'actor' => $step->actor_name, 'at' => $step->created_at?->toIso8601String()])->values()->all(),
                ];
            })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payments(Tenant $tenant): array
    {
        return VenueBookingCharge::query()->with(['order', 'booking'])->where('tenant_id', $tenant->id)->whereHas('order', fn ($order) => $order->whereIn('payment_state', ['paid', 'refund_pending', 'captured_review']))
            ->latest()->limit(50)->get()->map(function (VenueBookingCharge $charge): array {
                $refund = StoreRefund::query()->where('order_id', $charge->order_id)->latest()->first();

                return [
                    'id' => $charge->id, 'booking_reference' => $charge->booking?->booking_reference, 'purpose' => $charge->purpose,
                    'amount_pesewas' => $charge->amount_pesewas, 'payment_state' => $charge->order->payment_state,
                    'refund' => $refund === null ? null : ['id' => $refund->id, 'status' => $refund->status, 'review_reason' => $refund->review_reason],
                ];
            })->values()->all();
    }
}
