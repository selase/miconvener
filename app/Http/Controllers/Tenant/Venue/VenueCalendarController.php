<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Venue;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\VenueBooking;
use App\Services\Tenancy\TenantContext;
use App\Services\Venue\VenueBlackouts;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class VenueCalendarController extends Controller
{
    public function index(Request $request, string $subdomain): Response
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $shop = Shop::query()->where('tenant_id', $tenant->id)->first();
        if (! $shop) {
            return Inertia::render('Tenant/Venue/Calendar/Index', [
                'shop' => null,
                'spaces' => [],
                'events' => [],
                'month' => now()->format('Y-m'),
                'selectedSpaceId' => null,
            ]);
        }

        $spaces = StoreListing::query()
            ->withStaffOnly()
            ->where('shop_id', $shop->id)
            ->where('listing_kind', 'venue')
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get(['id', 'title', 'slug', 'rental_price_pesewas', 'pricing_model', 'capacity_breakdown'])
            ->map(fn (StoreListing $s): array => [
                'id' => $s->id,
                'title' => $s->title,
                'slug' => $s->slug,
                'pricing_model' => $s->pricing_model,
                'rental_price_pesewas' => $s->rental_price_pesewas,
                'capacity_breakdown' => $s->capacity_breakdown,
            ]);

        $monthParam = (string) $request->query('month', now()->format('Y-m'));
        try {
            $monthDate = Carbon::createFromFormat('Y-m', $monthParam);
        } catch (Throwable) {
            $monthDate = now();
            $monthParam = $monthDate->format('Y-m');
        }

        $windowStart = (clone $monthDate)->startOfMonth()->startOfWeek(\Carbon\CarbonInterface::SUNDAY)->startOfDay();
        $windowEnd = (clone $monthDate)->endOfMonth()->endOfWeek(\Carbon\CarbonInterface::SATURDAY)->endOfDay();

        $spaceId = $request->query('space_id');

        $query = VenueBooking::query()
            ->with(['listing:id,title,slug'])
            ->where('shop_id', $shop->id)
            ->whereIn('status', [
                VenueBooking::STATUS_CONFIRMED,
                VenueBooking::STATUS_PENDING_PAYMENT,
                VenueBooking::STATUS_PENDING_QUOTE,
                VenueBooking::STATUS_PENDING_APPROVAL,
                VenueBooking::STATUS_PENDING_HOST_APPROVAL,
                VenueBooking::STATUS_APPROVED_HOLD,
                VenueBooking::STATUS_BLOCKED,
            ])
            ->where(function ($visible): void {
                // An expired hold no longer occupies anything, so it leaves the calendar.
                $visible->where('status', '!=', VenueBooking::STATUS_APPROVED_HOLD)
                    ->orWhere('hold_expires_at', '>', now());
            })
            ->overlapping($windowStart, $windowEnd);

        if ($spaceId && is_string($spaceId) && Str::isUuid($spaceId)) {
            $query->where('store_listing_id', $spaceId);
        }

        $events = $query->orderBy('starts_at')->get()->map(function (VenueBooking $b): array {
            $isBlocked = $b->status === VenueBooking::STATUS_BLOCKED;

            return [
                'id' => $b->id,
                'booking_reference' => $b->booking_reference,
                'title' => $isBlocked ? ($b->host_notes ?: 'Blackout / Maintenance') : ($b->contract_terms_snapshot['event_title'] ?? $b->event_type),
                'planner_name' => $isBlocked ? 'Venue Staff' : $b->planner_name,
                'planner_email' => $b->planner_email,
                'planner_phone' => $b->planner_phone,
                'planner_company' => $b->planner_company,
                'event_type' => $b->event_type,
                'guest_count' => $b->guest_count,
                'layout_style' => $b->layout_style,
                'space_id' => $b->store_listing_id,
                'space_title' => $b->listing->title ?? 'Venue Space',
                'starts_at' => $b->starts_at->toIso8601String(),
                'ends_at' => $b->ends_at->toIso8601String(),
                'duration_units' => $b->duration_units,
                'time_slot_type' => $b->time_slot_type,
                'status' => $b->status,
                'payment_status' => $b->payment_status,
                'total_amount_pesewas' => $b->total_amount_pesewas,
                'deposit_required_pesewas' => $b->deposit_required_pesewas,
                'is_blocked' => $isBlocked,
                'kind' => $b->kind,
                'department' => $b->department,
                'purpose' => $b->purpose,
                'host_notes' => $b->host_notes,
                'quote_valid_until' => $b->quote_valid_until?->toIso8601String(),
                'approval_flow' => $b->usesApprovalFlow(),
                'holds_calendar' => VenueBooking::query()->whereKey($b->id)->blockingCalendar()->exists(),
                'hold_expires_at' => $b->hold_expires_at?->toIso8601String(),
                'timezone' => $b->usesApprovalFlow() ? $b->approvalPolicy()['timezone'] : null,
            ];
        });

        return Inertia::render('Tenant/Venue/Calendar/Index', [
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'city' => $shop->city,
            ],
            'spaces' => $spaces,
            'events' => $events,
            'month' => $monthParam,
            'selectedSpaceId' => $spaceId,
        ]);
    }

    public function storeBlock(Request $request, string $subdomain): RedirectResponse
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $validated = $request->validate([
            'store_listing_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $startsAt = Carbon::parse((string) $validated['starts_at']);
        $endsAt = Carbon::parse((string) $validated['ends_at']);

        $block = app(VenueBlackouts::class)->create($shop, (string) $validated['store_listing_id'], $startsAt, $endsAt, (string) $validated['reason']);
        $listing = $block->listing;

        return redirect()->back()->with('success', "Dates blocked successfully for {$listing->title}.");
    }

    public function destroyBlock(Request $request, string $subdomain, string $block): RedirectResponse
    {
        Gate::authorize('manage venue');

        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $shop = Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();

        if (! Str::isUuid($block)) {
            abort(404, 'Block not found');
        }

        $booking = app(VenueBlackouts::class)->remove($shop, $block);

        $listingTitle = $booking->listing->title ?? 'Space';

        return redirect()->back()->with('success', "Blackout block removed for {$listingTitle}. Dates are now available on the calendar.");
    }
}
