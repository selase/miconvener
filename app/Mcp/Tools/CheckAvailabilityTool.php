<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\StoreListing;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class CheckAvailabilityTool extends Tool
{
    protected string $name = 'check_venue_availability';

    protected string $title = 'Check Venue Availability';

    public function description(): string
    {
        return 'Verify if a venue space is operational, accommodates requested attendee counts for specific seating arrangements, and get booking hold details.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('The unique slug of the venue space')->required(),
            'date' => $schema->string()->description('Desired event date in YYYY-MM-DD format (e.g. "2026-11-20")'),
            'start_time' => $schema->string()->description('Optional preferred start time in HH:MM format (e.g. "09:00")'),
            'end_time' => $schema->string()->description('Optional preferred end time in HH:MM format (e.g. "17:00")'),
            'guest_count' => $schema->integer()->min(1)->description('Estimated attendee or delegate count'),
            'layout_style' => $schema->string()->description('Preferred layout: "banquet", "theater", "cocktail", "classroom", "boardroom"'),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'slug' => ['required', 'string', 'max:255'],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'layout_style' => ['nullable', 'string', 'in:banquet,theater,cocktail,classroom,boardroom'],
        ]);

        $slug = (string) $request->get('slug');

        /** @var StoreListing|null $listing */
        $listing = StoreListing::query()
            ->with('shop')
            ->where('listing_kind', StoreListing::KIND_VENUE)
            ->where('slug', $slug)
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->whereHas('shop', function ($q): void {
                $q->where('is_active', true);
            })
            ->first();

        if ($listing === null) {
            return Response::error("Venue space '{$slug}' is either inactive or does not exist.");
        }

        if (! $listing->isBookable()) {
            return Response::text(json_encode([
                'venue' => $listing->title,
                'host' => $listing->shop?->name,
                'is_operational' => true,
                'is_slot_available' => false,
                'is_bookable' => false,
                'note' => 'Direct online reservations are currently paused for this venue while onboarding completes. Please contact the venue directly.',
                'contact' => [
                    'phone' => $listing->shop?->phone,
                    'email' => $listing->shop?->email,
                ],
                'direct_url' => url("/marketplace/venues/{$listing->slug}"),
            ], JSON_PRETTY_PRINT));
        }

        $dateStr = $request->get('date') ? (string) $request->get('date') : null;
        $startTime = $request->get('start_time') ? (string) $request->get('start_time') : null;
        $endTime = $request->get('end_time') ? (string) $request->get('end_time') : null;
        $guestCount = $request->has('guest_count') ? (int) $request->get('guest_count') : null;
        $layoutStyle = (string) $request->get('layout_style', 'banquet');

        $availabilityService = app(\App\Services\Marketplace\VenueAvailabilityService::class);
        $isSlotAvailable = true;
        $timeSlotDetails = null;

        if ($dateStr !== null && $startTime !== null && $endTime !== null) {
            $startsAt = Carbon::parse("{$dateStr} {$startTime}");
            $endsAt = Carbon::parse("{$dateStr} {$endTime}");
            $isSlotAvailable = $availabilityService->isSlotAvailable($listing, $startsAt, $endsAt);

            if ($isSlotAvailable) {
                $pricing = $availabilityService->calculatePricing(
                    $listing,
                    $startsAt,
                    $endsAt,
                    $guestCount ?? 1,
                    $layoutStyle
                );
                $timeSlotDetails = [
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                    'duration_units' => $pricing['duration_units'],
                    'time_slot_type' => $pricing['time_slot_type'],
                    'rental_amount_ghs' => $pricing['rental_amount_pesewas'] / 100,
                    'security_deposit_ghs' => $pricing['security_deposit_pesewas'] / 100,
                    'total_amount_ghs' => $pricing['total_amount_pesewas'] / 100,
                    'deposit_required_ghs' => $pricing['deposit_required_pesewas'] / 100,
                ];
            }
        }

        $capacities = $listing->capacity_breakdown ?? [];
        $styleCapacity = isset($capacities[$layoutStyle]) ? (int) $capacities[$layoutStyle] : null;

        $capacityStatus = 'acceptable';
        $capacityMessage = 'Space comfortably fits requested capacity.';

        if ($guestCount !== null) {
            if ($styleCapacity === null || $styleCapacity === 0) {
                $capacityStatus = 'unspecified';
                $capacityMessage = "Venue does not specify a maximum capacity for {$layoutStyle} seating. Please verify layout fit with the host.";
            } elseif ($guestCount > $styleCapacity) {
                $capacityStatus = 'exceeded';
                $capacityMessage = "Requested {$guestCount} guests exceeds the maximum {$layoutStyle} capacity of {$styleCapacity} attendees.";
            } elseif ($guestCount > ($styleCapacity * 0.9)) {
                $capacityStatus = 'near_maximum';
                $capacityMessage = "Requested {$guestCount} guests is near the maximum {$layoutStyle} capacity of {$styleCapacity} attendees.";
            }
        }

        $priceGhs = (! $listing->isPriceOnRequest() && $listing->rental_price_pesewas > 0)
            ? $listing->rental_price_pesewas / 100
            : null;

        $response = [
            'venue' => $listing->title,
            'host' => $listing->shop?->name,
            'target_date' => $dateStr,
            'is_operational' => true,
            'is_slot_available' => $isSlotAvailable,
            'pricing_estimate' => [
                'rate_ghs' => $priceGhs ?? 'Price on request',
                'pricing_model' => $listing->isPriceOnRequest() ? 'Price on request' : str_replace('_', ' ', $listing->pricing_model ?? 'per day'),
                'is_price_on_request' => $listing->isPriceOnRequest(),
            ],
            'time_slot_details' => $timeSlotDetails,
            'capacity_check' => [
                'layout_style' => $layoutStyle,
                'max_capacity' => $styleCapacity,
                'requested_guests' => $guestCount,
                'status' => $capacityStatus,
                'note' => $capacityMessage,
            ],
            'booking_instructions' => 'To place a tentative hold or confirm booking on this date, contact the host at '.$listing->shop?->email.' or call '.$listing->shop?->phone.'.',
            'direct_url' => route('marketplace.venues.show', $listing->slug),
        ];

        return Response::text((string) json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
