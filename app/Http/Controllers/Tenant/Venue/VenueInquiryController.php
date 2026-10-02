<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Venue;

use App\Http\Controllers\Controller;
use App\Mail\Marketplace\QuoteSentNotification;
use App\Models\Shop;
use App\Models\VenueBooking;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
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
                'listing' => $inquiry->listing ? [
                    'id' => $inquiry->listing->id,
                    'title' => $inquiry->listing->title,
                    'slug' => $inquiry->listing->slug,
                    'pricing_model' => $inquiry->listing->pricing_model,
                ] : null,
            ],
        ]);
    }

    public function acceptAndHold(Request $request, string $subdomain, string $inquiryId): RedirectResponse
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        /** @var VenueBooking $inquiry */
        $inquiry = VenueBooking::query()
            ->where('shop_id', $shop->id)
            ->where('id', $inquiryId)
            ->firstOrFail();

        if ($inquiry->isConfirmed() || $inquiry->isDepositPaid()) {
            return redirect()->back()->with('error', 'Cannot modify an already confirmed or paid reservation.');
        }

        $inquiry->update([
            'status' => VenueBooking::STATUS_PENDING_PAYMENT,
            'host_notes' => 'Host has placed a 48-hour tentative reservation hold on this space.',
            'quote_valid_until' => now()->addHours(48),
        ]);

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

        /** @var VenueBooking $inquiry */
        $inquiry = VenueBooking::query()
            ->with('listing')
            ->where('shop_id', $shop->id)
            ->where('id', $inquiryId)
            ->firstOrFail();

        if ($inquiry->isConfirmed() || $inquiry->isDepositPaid()) {
            return redirect()->back()->with('error', 'Cannot modify an already confirmed or paid reservation.');
        }

        $rentalPesewas = (int) round(((float) $validated['rental_amount_ghs']) * 100);
        $depositPesewas = isset($validated['security_deposit_ghs']) ? (int) round(((float) $validated['security_deposit_ghs']) * 100) : 0;
        $totalPesewas = $rentalPesewas + $depositPesewas;
        $requiredPesewas = (int) round(((float) $validated['deposit_required_ghs']) * 100);

        $inquiry->update([
            'rental_amount_pesewas' => $rentalPesewas,
            'security_deposit_pesewas' => $depositPesewas,
            'total_amount_pesewas' => $totalPesewas,
            'deposit_required_pesewas' => min($requiredPesewas, $totalPesewas),
            'host_notes' => $validated['host_notes'] ?? null,
            'status' => VenueBooking::STATUS_PENDING_PAYMENT,
            'quote_valid_until' => now()->addDays(7),
        ]);

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

        /** @var VenueBooking $inquiry */
        $inquiry = VenueBooking::query()
            ->where('shop_id', $shop->id)
            ->where('id', $inquiryId)
            ->firstOrFail();

        if ($inquiry->isConfirmed() || $inquiry->isDepositPaid()) {
            return redirect()->back()->with('error', 'Cannot decline an already confirmed or paid reservation.');
        }

        $inquiry->update([
            'status' => VenueBooking::STATUS_REJECTED,
            'host_notes' => $validated['reason'] ?? 'Declined by venue host.',
        ]);

        return redirect()->back()->with('info', "Inquiry {$inquiry->booking_reference} marked as declined.");
    }
}
