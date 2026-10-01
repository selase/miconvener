<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Venue;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\VenueBooking;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
                VenueBooking::STATUS_BLOCKED,
            ])
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
                'space_title' => $b->listing?->title ?? 'Venue Space',
                'starts_at' => $b->starts_at->toIso8601String(),
                'ends_at' => $b->ends_at->toIso8601String(),
                'duration_units' => $b->duration_units,
                'time_slot_type' => $b->time_slot_type,
                'status' => $b->status,
                'payment_status' => $b->payment_status,
                'total_amount_pesewas' => $b->total_amount_pesewas,
                'deposit_required_pesewas' => $b->deposit_required_pesewas,
                'is_blocked' => $isBlocked,
                'host_notes' => $b->host_notes,
                'quote_valid_until' => $b->quote_valid_until?->toIso8601String(),
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

        $listing = StoreListing::query()
            ->where('shop_id', $shop->id)
            ->where('id', $validated['store_listing_id'])
            ->firstOrFail();

        $startsAt = Carbon::parse((string) $validated['starts_at']);
        $endsAt = Carbon::parse((string) $validated['ends_at']);

        // Check if there is already a confirmed booking, active hold, or existing blackout in this time window
        $overlap = VenueBooking::query()
            ->where('store_listing_id', $listing->id)
            ->where(function ($q): void {
                $q->whereIn('status', [VenueBooking::STATUS_CONFIRMED, VenueBooking::STATUS_BLOCKED])
                    ->orWhere(function ($hold): void {
                        $hold->where('status', VenueBooking::STATUS_PENDING_PAYMENT)
                            ->where(function ($valid): void {
                                $valid->whereNull('quote_valid_until')
                                    ->orWhere('quote_valid_until', '>=', now());
                            });
                    });
            })
            ->overlapping($startsAt, $endsAt)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'starts_at' => ['Cannot block dates: An active reservation, hold, or blackout already occupies this space during this time.'],
            ]);
        }

        do {
            $uniqueRef = 'BLK-'.mb_strtoupper(Str::random(8));
        } while (VenueBooking::query()->where('booking_reference', $uniqueRef)->exists());

        VenueBooking::create([
            'booking_reference' => $uniqueRef,
            'tenant_id' => $tenant->id,
            'shop_id' => $shop->id,
            'store_listing_id' => $listing->id,
            'planner_name' => 'Venue Maintenance / Blackout',
            'planner_email' => $shop->email ?? 'venue@miconvener.test',
            'planner_phone' => $shop->phone ?? null,
            'event_type' => 'Maintenance / Blackout',
            'guest_count' => 0,
            'layout_style' => 'banquet',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'time_slot_type' => 'full_day',
            'pricing_model' => 'per_day',
            'rate_pesewas' => 0,
            'duration_units' => 1.0,
            'rental_amount_pesewas' => 0,
            'security_deposit_pesewas' => 0,
            'total_amount_pesewas' => 0,
            'deposit_required_pesewas' => 0,
            'amount_paid_pesewas' => 0,
            'gateway_fee_pesewas' => 0,
            'status' => VenueBooking::STATUS_BLOCKED,
            'payment_status' => VenueBooking::PAYMENT_UNPAID,
            'host_notes' => (string) $validated['reason'],
        ]);

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

        $booking = VenueBooking::query()
            ->where('shop_id', $shop->id)
            ->where('id', $block)
            ->where('status', VenueBooking::STATUS_BLOCKED)
            ->firstOrFail();

        $listingTitle = $booking->listing?->title ?? 'Space';
        $booking->delete();

        return redirect()->back()->with('success', "Blackout block removed for {$listingTitle}. Dates are now available on the calendar.");
    }
}
