<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

final class MarketplaceMcpController extends Controller
{
    public function show(Request $request): Response|HttpResponse
    {
        if (! $request->acceptsHtml()) {
            return response('', 405)->header('Allow', 'POST');
        }

        $tools = [
            [
                'name' => 'search_venues',
                'title' => 'Search Venue Spaces',
                'description' => 'Search and discover conference halls, ballrooms, and event spaces across Ghana with semantic vector matching, city filtering, and capacity breakdowns.',
                'arguments' => [
                    'query' => ['type' => 'string', 'description' => 'Natural language requirement or keywords (e.g. "ocean view ballroom with backup generator in Accra")'],
                    'city' => ['type' => 'string', 'description' => 'Target city in Ghana (e.g. Accra, Kumasi, Takoradi)'],
                    'min_capacity' => ['type' => 'integer', 'description' => 'Minimum required seating capacity'],
                    'capacity_style' => ['type' => 'string', 'description' => 'Seating arrangement: banquet, theater, cocktail, or classroom'],
                    'max_price_ghs' => ['type' => 'number', 'description' => 'Maximum budget in Ghana Cedis (GHS)'],
                ],
                'example' => [
                    'query' => 'Executive boardroom with standby generator',
                    'city' => 'Accra',
                    'min_capacity' => 50,
                ],
            ],
            [
                'name' => 'get_venue_details',
                'title' => 'Get Venue Space Details',
                'description' => 'Retrieve comprehensive specifications, room dimensions, included vs excluded amenities, house rules, and host contact for a specific venue space.',
                'arguments' => [
                    'slug' => ['type' => 'string', 'required' => true, 'description' => 'The unique slug identifier of the venue space (e.g. "palm-court-ocean-suite")'],
                ],
                'example' => [
                    'slug' => 'palm-court-ocean-suite',
                ],
            ],
            [
                'name' => 'check_venue_availability',
                'title' => 'Check Venue Space Availability',
                'description' => 'Check if a venue space is operational, evaluate capacity constraints for a target guest count, and estimate rental pricing.',
                'arguments' => [
                    'slug' => ['type' => 'string', 'required' => true, 'description' => 'The venue space slug identifier'],
                    'date' => ['type' => 'string', 'description' => 'Target date in YYYY-MM-DD format (must be today or in the future)'],
                    'guest_count' => ['type' => 'integer', 'description' => 'Expected number of attendees'],
                    'layout_style' => ['type' => 'string', 'description' => 'Desired layout: banquet, theater, cocktail, or classroom'],
                ],
                'example' => [
                    'slug' => 'palm-court-ocean-suite',
                    'guest_count' => 120,
                    'layout_style' => 'banquet',
                ],
            ],
            [
                'name' => 'request_venue_quote',
                'title' => 'Request Venue Quote',
                'description' => 'Formulate a structured booking inquiry draft with a unique reference code, attendee count, estimated costs, and host contact details.',
                'arguments' => [
                    'venue_slug' => ['type' => 'string', 'required' => true, 'description' => 'The venue space slug identifier'],
                    'planner_name' => ['type' => 'string', 'required' => true, 'description' => 'Full name of event planner'],
                    'planner_email' => ['type' => 'string', 'required' => true, 'description' => 'Contact email address'],
                    'event_type' => ['type' => 'string', 'required' => true, 'description' => 'Nature of event (e.g. Summit, Wedding, Annual General Meeting)'],
                    'event_date' => ['type' => 'string', 'required' => true, 'description' => 'Event date (YYYY-MM-DD)'],
                    'guest_count' => ['type' => 'integer', 'required' => true, 'description' => 'Estimated attendee count'],
                ],
                'example' => [
                    'venue_slug' => 'palm-court-ocean-suite',
                    'planner_name' => 'Kojo Mensah',
                    'planner_email' => 'kojo@example.com',
                    'event_type' => 'Corporate Annual Summit',
                    'event_date' => '2026-11-20',
                    'guest_count' => 100,
                ],
            ],
        ];

        /** @var \App\Models\Tenant|null $partnerTenant */
        $partnerTenant = $request->attributes->get('mcp_partner_tenant');
        /** @var \App\Models\TenantApiKey|null $partnerApiKey */
        $partnerApiKey = $request->attributes->get('mcp_partner_api_key');

        return Inertia::render('Public/Marketplace/Mcp/Index', [
            'server' => [
                'name' => 'MiConvener Marketplace Concierge',
                'version' => '1.0.0',
                'protocol' => 'JSON-RPC 2.0 (Model Context Protocol)',
                'endpoint' => url('/mcp/marketplace'),
                'transport' => 'Streamable HTTP (POST)',
                'rate_limit' => $partnerTenant !== null ? '300 requests / minute (Partner Elevated)' : '60 requests / minute (Public)',
                'description' => 'Empowers AI assistants, Claude Desktop, Cursor, and event planners to discover venue spaces across Ghana, inspect technical specs, check availability, and formulate quote requests.',
            ],
            'partner' => $partnerTenant !== null ? [
                'is_partner' => true,
                'tenant_id' => $partnerTenant->id,
                'tenant_name' => $partnerTenant->name,
                'api_key_name' => $partnerApiKey?->name,
                'rate_limit_per_minute' => 300,
            ] : null,
            'tools' => $tools,
        ]);
    }
}
