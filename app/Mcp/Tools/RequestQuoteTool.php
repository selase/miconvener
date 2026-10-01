<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\StoreListing;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class RequestQuoteTool extends Tool
{
    protected string $name = 'request_venue_quote';

    protected string $title = 'Request Venue Quote';

    public function description(): string
    {
        return 'Prepare and formulate a formal venue inquiry and quote request for an event planner to submit to the venue host.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'venue_slug' => $schema->string()->description('Slug of the venue space')->required(),
            'planner_name' => $schema->string()->description('Name of the planner, company, or organizing committee')->required(),
            'planner_email' => $schema->string()->description('Planner contact email address')->required(),
            'planner_phone' => $schema->string()->description('Planner telephone number (Ghana or international)'),
            'event_type' => $schema->string()->description('Category of event (e.g., "Medical Conference", "AGM", "Tech Summit", "Gala Dinner", "Wedding Reception")')->required(),
            'event_date' => $schema->string()->description('Target event date in YYYY-MM-DD format')->required(),
            'guest_count' => $schema->integer()->min(1)->description('Expected number of attendees')->required(),
            'notes' => $schema->string()->description('Special logistics requirements (e.g. stage dimensions, sound engineer, 3-phase power, catering setup)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'venue_slug' => ['required', 'string', 'max:255'],
            'planner_name' => ['required', 'string', 'max:255'],
            'planner_email' => ['required', 'email', 'max:255'],
            'planner_phone' => ['nullable', 'string', 'max:50'],
            'event_type' => ['required', 'string', 'max:100'],
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'guest_count' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $slug = (string) $request->get('venue_slug');

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
            return Response::error("Venue space '{$slug}' not found.");
        }

        /** @var \App\Models\Tenant|null $partnerTenant */
        $partnerTenant = request()->attributes->get('mcp_partner_tenant');
        /** @var \App\Models\TenantApiKey|null $partnerApiKey */
        $partnerApiKey = request()->attributes->get('mcp_partner_api_key');

        $dateStr = (string) $request->get('event_date');
        $startsAt = Carbon::parse($dateStr)->setTime(9, 0);
        $endsAt = Carbon::parse($dateStr)->setTime(17, 0);

        $bookingService = app(\App\Services\Marketplace\VenueBookingService::class);
        try {
            $booking = $bookingService->createBooking($listing, [
                'planner_name' => $request->get('planner_name'),
                'planner_email' => $request->get('planner_email'),
                'planner_phone' => $request->get('planner_phone'),
                'planner_company' => $partnerTenant?->name,
                'event_type' => $request->get('event_type'),
                'guest_count' => (int) $request->get('guest_count'),
                'layout_style' => 'banquet',
                'starts_at' => $startsAt->toDateTimeString(),
                'ends_at' => $endsAt->toDateTimeString(),
                'special_requests' => $request->get('notes'),
                'request_quote_only' => true,
            ], null, $partnerTenant);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = implode(' ', array_map(fn ($msgs) => implode(' ', (array) $msgs), $e->errors()));

            return Response::error('Venue quote validation failed: '.$errors);
        }

        $inquiryRef = $booking->booking_reference;
        $date = Carbon::parse($dateStr)->toFormattedDateString();
        $rateGhs = (! $listing->isPriceOnRequest() && $listing->rental_price_pesewas > 0)
            ? $listing->rental_price_pesewas / 100
            : null;
        $depositGhs = (! $listing->isPriceOnRequest() && $listing->security_deposit_pesewas)
            ? $listing->security_deposit_pesewas / 100
            : 0;

        $inquiry = [
            'inquiry_reference' => $inquiryRef,
            'status' => 'submitted',
            'venue' => [
                'title' => $listing->title,
                'host_name' => $listing->shop?->name,
                'host_email' => $listing->shop?->email,
                'host_phone' => $listing->shop?->phone,
            ],
            'planner' => [
                'name' => $request->get('planner_name'),
                'email' => $request->get('planner_email'),
                'phone' => $request->get('planner_phone') ?? 'Not provided',
            ],
            'event_details' => [
                'type' => $request->get('event_type'),
                'target_date' => $date,
                'attendees' => (int) $request->get('guest_count'),
                'special_requirements' => $request->get('notes') ?? 'Standard venue terms',
            ],
            'estimated_costs' => [
                'space_rate_ghs' => $rateGhs ?? 'Price on request',
                'pricing_model' => $listing->isPriceOnRequest() ? 'Price on request' : str_replace('_', ' ', $listing->pricing_model ?? 'per day'),
                'security_deposit_ghs' => $depositGhs,
                'is_price_on_request' => $listing->isPriceOnRequest(),
            ],
            'next_steps' => $partnerTenant !== null
                ? "This priority partner inquiry reference ({$inquiryRef}) has been routed to the host inbox at {$listing->shop?->email}. You can track status or proceed to booking via the link below."
                : "This venue inquiry reference ({$inquiryRef}) has been routed to the host inbox at {$listing->shop?->email}. You can track status or proceed to booking via the link below.",
            'booking_url' => route('marketplace.bookings.show', $booking->booking_reference),
        ];

        if ($partnerTenant !== null) {
            $inquiry['partner'] = [
                'is_partner_inquiry' => true,
                'partner_id' => $partnerTenant->id,
                'partner_name' => $partnerTenant->name,
                'tier' => 'Corporate Agency / Planner',
                'api_key_name' => $partnerApiKey?->name,
            ];
        }

        return Response::text((string) json_encode($inquiry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
