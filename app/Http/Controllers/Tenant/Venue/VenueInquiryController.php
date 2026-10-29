<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Venue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Venue\DecideVenueBookingRequest;
use App\Mail\Marketplace\QuoteSentNotification;
use App\Models\Shop;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Services\Marketplace\VenueBookingApprovals;
use App\Services\Marketplace\VenueBookingService;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class VenueInquiryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $shop = Shop::query()->where('tenant_id', $tenant->id)->first();
        if (! $shop) {
            return Inertia::render('Tenant/Venue/Inquiries/Index', [
                'shop' => null,
                'inquiries' => ['data' => [], 'links' => []],
                'stats' => [
                    'total_leads' => 0,
                    'pending_quotes' => 0,
                    'confirmed_bookings' => 0,
                    'total_revenue_pesewas' => 0,
                ],
                'filters' => [],
            ]);
        }

        $status = $request->query('status');
        $search = $request->query('search');

        $query = VenueBooking::query()
            ->with(['listing', 'plannerTenant'])
            ->where('shop_id', $shop->id)
            ->latest();

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search): void {
                $q->where('booking_reference', 'ilike', "%{$search}%")
                    ->orWhere('planner_name', 'ilike', "%{$search}%")
                    ->orWhere('planner_email', 'ilike', "%{$search}%")
                    ->orWhere('planner_company', 'ilike', "%{$search}%")
                    ->orWhere('event_type', 'ilike', "%{$search}%");
            });
        }

        $paginated = $query->paginate(15)->withQueryString();

        $inquiries = $paginated->through(fn (VenueBooking $lead): array => [
            'id' => $lead->id,
            'booking_reference' => $lead->booking_reference,
            'created_at' => $lead->created_at?->toIso8601String(),
            'planner_name' => $lead->planner_name,
            'planner_email' => $lead->planner_email,
            'planner_phone' => $lead->planner_phone,
            'planner_company' => $lead->planner_company,
            'planner_tenant' => $lead->plannerTenant ? [
                'id' => $lead->plannerTenant->id,
                'name' => $lead->plannerTenant->name,
                'slug' => $lead->plannerTenant->slug,
            ] : null,
            'event_type' => $lead->event_type,
            'guest_count' => $lead->guest_count,
            'layout_style' => $lead->layout_style,
            'starts_at' => $lead->starts_at->toIso8601String(),
            'ends_at' => $lead->ends_at->toIso8601String(),
            'duration_units' => (float) $lead->duration_units,
            'time_slot_type' => $lead->time_slot_type,
            'total_amount_pesewas' => $lead->total_amount_pesewas,
            'deposit_required_pesewas' => $lead->deposit_required_pesewas,
            'amount_paid_pesewas' => $lead->amount_paid_pesewas,
            'status' => $lead->status,
            'payment_status' => $lead->payment_status,
            'listing' => $lead->listing ? [
                'id' => $lead->listing->id,
                'title' => $lead->listing->title,
                'slug' => $lead->listing->slug,
            ] : null,
        ]);

        // Calculate pipeline metrics
        $allShopBookings = VenueBooking::query()->where('shop_id', $shop->id);
        $totalLeads = (clone $allShopBookings)->count();
        $pendingQuotes = (clone $allShopBookings)->where('status', VenueBooking::STATUS_PENDING_QUOTE)->count();
        $confirmedBookings = (clone $allShopBookings)->whereIn('status', [VenueBooking::STATUS_CONFIRMED])->count();
        $totalRevenue = (int) (clone $allShopBookings)->sum('amount_paid_pesewas');

        return Inertia::render('Tenant/Venue/Inquiries/Index', [
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
            ],
            'inquiries' => $inquiries,
            'stats' => [
                'total_leads' => $totalLeads,
                'pending_quotes' => $pendingQuotes,
                'confirmed_bookings' => $confirmedBookings,
                'total_revenue_pesewas' => $totalRevenue,
            ],
            'filters' => [
                'status' => $status ?? 'all',
                'search' => $search ?? '',
            ],
        ]);
    }

    public function show(Request $request, string $subdomain, string $inquiryId): Response
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        /** @var VenueBooking $inquiry */
        $inquiry = VenueBooking::query()
            ->with(['listing', 'shop', 'plannerTenant'])
            ->where('shop_id', $shop->id)
            ->where('id', $inquiryId)
            ->firstOrFail();

        return Inertia::render('Tenant/Venue/Inquiries/Show', [
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
            ],
            'inquiry' => [
                'id' => $inquiry->id,
                'booking_reference' => $inquiry->booking_reference,
                'status' => $inquiry->status,
                'payment_status' => $inquiry->payment_status,
                'planner_name' => $inquiry->planner_name,
                'planner_email' => $inquiry->planner_email,
                'planner_phone' => $inquiry->planner_phone,
                'planner_company' => $inquiry->planner_company,
                'planner_tenant' => $inquiry->plannerTenant ? [
                    'id' => $inquiry->plannerTenant->id,
                    'name' => $inquiry->plannerTenant->name,
                ] : null,
                'event_type' => $inquiry->event_type,
                'guest_count' => $inquiry->guest_count,
                'layout_style' => $inquiry->layout_style,
                'special_requests' => $inquiry->special_requests,
                'starts_at' => $inquiry->starts_at->toIso8601String(),
                'ends_at' => $inquiry->ends_at->toIso8601String(),
                'time_slot_type' => $inquiry->time_slot_type,
                'duration_units' => (float) $inquiry->duration_units,
                'rental_amount_pesewas' => $inquiry->rental_amount_pesewas,
                'security_deposit_pesewas' => $inquiry->security_deposit_pesewas,
                'total_amount_pesewas' => $inquiry->total_amount_pesewas,
                'deposit_required_pesewas' => $inquiry->deposit_required_pesewas,
                'amount_paid_pesewas' => $inquiry->amount_paid_pesewas,
                'paystack_reference' => $inquiry->paystack_reference,
                'paid_at' => $inquiry->paid_at?->toIso8601String(),
                'quote_valid_until' => $inquiry->quote_valid_until?->toIso8601String(),
                'contract_agreed_at' => $inquiry->contract_agreed_at?->toIso8601String(),
                'host_notes' => $inquiry->host_notes,
                'approval' => $this->approvalPayload($inquiry),
                'listing' => $inquiry->listing ? [
                    'id' => $inquiry->listing->id,
                    'title' => $inquiry->listing->title,
                    'slug' => $inquiry->listing->slug,
                    'pricing_model' => $inquiry->listing->pricing_model,
                ] : null,
            ],
        ]);
    }

    public function approve(DecideVenueBookingRequest $request, string $subdomain, string $inquiryId): RedirectResponse
    {
        return $this->decide($request, $inquiryId, function (VenueBooking $booking) use ($request): string {
            $hours = (int) ($request->validated('hold_hours') ?? $booking->approvalPolicy()['default_hold_hours']);
            $held = app(VenueBookingApprovals::class)->approve($booking, $request->user(), $hours, (string) $request->validated('operation_key'));

            return "Dates held for {$held->booking_reference} until ".$held->hold_expires_at?->copy()->setTimezone($held->approvalPolicy()['timezone'])->format('M j, g:i A T').'.';
        });
    }

    public function renew(DecideVenueBookingRequest $request, string $subdomain, string $inquiryId): RedirectResponse
    {
        return $this->decide($request, $inquiryId, function (VenueBooking $booking) use ($request): string {
            $hours = (int) ($request->validated('hold_hours') ?? $booking->approvalPolicy()['default_hold_hours']);
            $held = app(VenueBookingApprovals::class)->renew($booking, $request->user(), $hours, (string) $request->validated('operation_key'));

            return "Hold renewed for {$held->booking_reference} until ".$held->hold_expires_at?->copy()->setTimezone($held->approvalPolicy()['timezone'])->format('M j, g:i A T').'.';
        });
    }

    public function decline(DecideVenueBookingRequest $request, string $subdomain, string $inquiryId): RedirectResponse
    {
        return $this->decide($request, $inquiryId, function (VenueBooking $booking) use ($request): string {
            $declined = app(VenueBookingApprovals::class)->decline($booking, $request->user(), (string) $request->validated('reason'), (string) $request->validated('operation_key'));

            return "Request {$declined->booking_reference} declined.";
        });
    }

    public function release(DecideVenueBookingRequest $request, string $subdomain, string $inquiryId): RedirectResponse
    {
        return $this->decide($request, $inquiryId, function (VenueBooking $booking) use ($request): string {
            $released = app(VenueBookingApprovals::class)->release($booking, $request->user(), (string) $request->validated('reason'), (string) $request->validated('operation_key'));

            return "Hold on {$released->booking_reference} released.";
        });
    }

    public function acceptAndHold(Request $request, string $subdomain, string $inquiryId): RedirectResponse
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        try {
            $inquiry = app(VenueBookingService::class)->placeHostHold($shop, $inquiryId);
        } catch (ValidationException $e) {
            return redirect()->back()->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return redirect()->back()->with('success', "Booking {$inquiry->booking_reference} placed on tentative hold.");
    }

    public function sendQuote(Request $request, string $subdomain, string $inquiryId): RedirectResponse
    {
        Gate::authorize('manage venue');

        $validated = $request->validate([
            'rental_amount_ghs' => ['required', 'numeric', 'min:0'],
            'security_deposit_ghs' => ['nullable', 'numeric', 'min:0'],
            'deposit_required_ghs' => ['required', 'numeric', 'min:0'],
            'host_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $tenant = app(TenantContext::class)->getTenant();
        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $rentalPesewas = (int) round(((float) $validated['rental_amount_ghs']) * 100);
        $depositPesewas = isset($validated['security_deposit_ghs']) ? (int) round(((float) $validated['security_deposit_ghs']) * 100) : 0;
        $requiredPesewas = (int) round(((float) $validated['deposit_required_ghs']) * 100);

        try {
            $inquiry = app(VenueBookingService::class)->quoteAsHost($shop, $inquiryId, $rentalPesewas, $depositPesewas, $requiredPesewas, $validated['host_notes'] ?? null);
        } catch (ValidationException $e) {
            return redirect()->back()->with('error', (string) collect($e->errors())->flatten()->first());
        }

        // Send quote notification to planner
        try {
            $checkoutUrl = route('marketplace.bookings.show', $inquiry->booking_reference);
            Mail::to($inquiry->planner_email)->queue(new QuoteSentNotification($inquiry, $checkoutUrl));
        } catch (Throwable) {
            // Logged, non-blocking
        }

        return redirect()->back()->with('success', "Custom quotation sent to {$inquiry->planner_email}.");
    }

    public function reject(Request $request, string $subdomain, string $inquiryId): RedirectResponse
    {
        Gate::authorize('manage venue');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $tenant = app(TenantContext::class)->getTenant();
        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        try {
            $inquiry = app(VenueBookingService::class)->rejectAsHost($shop, $inquiryId, $validated['reason'] ?? null);
        } catch (ValidationException $e) {
            return redirect()->back()->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return redirect()->back()->with('info', "Inquiry {$inquiry->booking_reference} marked as declined.");
    }

    /**
     * Resolve the request inside this organisation's shop, run one decision and
     * report it. A stale or refused decision is explained, never half-applied.
     *
     * @param  Closure(VenueBooking): string  $decision
     */
    private function decide(DecideVenueBookingRequest $request, string $inquiryId, Closure $decision): RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_if($tenant === null, 404, 'Tenant not found');
        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $booking = VenueBooking::query()
            ->where('shop_id', $shop->id)
            ->where('id', $inquiryId)
            ->firstOrFail();

        try {
            $message = $decision($booking);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            return redirect()->back()->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalPayload(VenueBooking $booking): array
    {
        if (! $booking->usesApprovalFlow()) {
            return [
                'flow' => false,
                'conflicts' => $this->paymentConflicts($booking),
            ];
        }

        $policy = $booking->approvalPolicy();

        return [
            'flow' => true,
            'effective_status' => $booking->effectiveStatus(),
            'hold_expires_at' => $booking->hold_expires_at?->toIso8601String(),
            'timezone' => $policy['timezone'],
            'default_hold_hours' => $policy['default_hold_hours'],
            'max_hold_hours' => min(168, $policy['max_hold_hours']),
            'decision_reason' => $booking->decline_reason,
            'actions' => $booking->actions()->get()->map(fn (VenueBookingAction $action): array => [
                'id' => $action->id,
                'action' => $action->action,
                'actor_type' => $action->actor_type,
                'actor_name' => $action->actor_name,
                'previous_status' => $action->previous_status,
                'result_status' => $action->result_status,
                'hold_expires_at' => $action->hold_expires_at?->toIso8601String(),
                'decision_reason' => $action->decision_reason,
                'delivery_state' => $action->delivery_state,
                'created_at' => $action->created_at?->toIso8601String(),
            ])->all(),
            'conflicts' => $this->paymentConflicts($booking),
        ];
    }

    /**
     * Payments the calendar could not honour, awaiting staff reconciliation.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentConflicts(VenueBooking $booking): array
    {
        return $booking->actions()->where('action', VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT)->get()
            ->map(fn (VenueBookingAction $action): array => [
                'id' => $action->id,
                'reason' => $action->decision_reason,
                'provider_reference' => $action->evidence['provider_reference'] ?? null,
                'amount_pesewas' => $action->evidence['amount_pesewas'] ?? null,
                'currency' => $action->evidence['currency'] ?? null,
                'recorded_at' => $action->created_at?->toIso8601String(),
            ])->all();
    }
}
