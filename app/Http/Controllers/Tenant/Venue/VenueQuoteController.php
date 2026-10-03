<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Venue;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceQuote;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\Marketplace\MarketplaceVendorService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class VenueQuoteController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MarketplaceVendorService $vendorService
    ) {}

    public function index(Request $request, string $subdomain): Response
    {
        Gate::authorize('manage venue');

        /** @var Tenant $tenant */
        $tenant = $this->tenantContext->getTenant();
        $shop = Shop::query()->where('tenant_id', $tenant->id)->first();

        if (! $shop) {
            return Inertia::render('Tenant/Venue/Quotes/Index', [
                'shop' => null,
                'quotes' => ['data' => [], 'links' => []],
                'stats' => [
                    'pending_quotes' => 0,
                    'accepted_quotes' => 0,
                    'total_quoted_pesewas' => 0,
                ],
                'filters' => [],
            ]);
        }

        $status = $request->query('status');
        $search = $request->query('search');

        $query = MarketplaceQuote::query()
            ->with(['listing', 'shop'])
            ->where('tenant_id', $tenant->id);

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('quote_reference', 'like', "%{$search}%")
                    ->orWhere('planner_name', 'like', "%{$search}%")
                    ->orWhere('planner_email', 'like', "%{$search}%")
                    ->orWhere('event_title', 'like', "%{$search}%");
            });
        }

        $quotes = $query->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (MarketplaceQuote $q) => $q->toPayload());

        $pendingCount = MarketplaceQuote::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', MarketplaceQuote::STATUS_PENDING_QUOTE)
            ->count();

        $acceptedCount = MarketplaceQuote::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', MarketplaceQuote::STATUS_ACCEPTED)
            ->count();

        $totalQuoted = (int) MarketplaceQuote::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [MarketplaceQuote::STATUS_QUOTED, MarketplaceQuote::STATUS_ACCEPTED])
            ->sum('total_amount_pesewas');

        return Inertia::render('Tenant/Venue/Quotes/Index', [
            'shop' => $shop,
            'quotes' => $quotes,
            'stats' => [
                'pending_quotes' => $pendingCount,
                'accepted_quotes' => $acceptedCount,
                'total_quoted_pesewas' => $totalQuoted,
            ],
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
        ]);
    }

    public function show(string $subdomain, MarketplaceQuote $quote): Response
    {
        Gate::authorize('manage venue');
        $this->authorizeQuoteOwnership($quote);

        $quote->load(['listing', 'shop', 'plannerTenant', 'event']);

        return Inertia::render('Tenant/Venue/Quotes/Show', [
            'quote' => $quote->toPayload(),
            'listing' => $quote->listing,
        ]);
    }

    public function storeProposal(Request $request, string $subdomain, MarketplaceQuote $quote): RedirectResponse
    {
        Gate::authorize('manage venue');
        $this->authorizeQuoteOwnership($quote);

        if (! in_array($quote->status, [MarketplaceQuote::STATUS_PENDING_QUOTE, MarketplaceQuote::STATUS_QUOTED], true)) {
            return back()->with('error', 'Proposals cannot be modified once accepted or closed.');
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price_pesewas' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'delivery_fee_pesewas' => ['nullable', 'integer', 'min:0'],
            'tax_pesewas' => ['nullable', 'integer', 'min:0'],
            'deposit_required_pesewas' => ['nullable', 'integer', 'min:0'],
            'valid_until' => ['nullable', 'date', 'after:now'],
            'vendor_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->vendorService->submitProposal($quote, $validated);

        return redirect()->route('tenant.venue.quotes.show', ['subdomain' => $subdomain, 'quote' => $quote->id])
            ->with('success', 'Quotation proposal sent to client successfully.');
    }

    public function reject(Request $request, string $subdomain, MarketplaceQuote $quote): RedirectResponse
    {
        Gate::authorize('manage venue');
        $this->authorizeQuoteOwnership($quote);

        if (! in_array($quote->status, [MarketplaceQuote::STATUS_PENDING_QUOTE, MarketplaceQuote::STATUS_QUOTED], true)) {
            return back()->with('error', 'Cannot decline a quotation that has already been accepted or finalized.');
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $quote->update([
            'status' => MarketplaceQuote::STATUS_REJECTED,
            'vendor_notes' => $validated['reason'] ?? null,
        ]);

        return redirect()->route('tenant.venue.quotes.show', ['subdomain' => $subdomain, 'quote' => $quote->id])
            ->with('success', 'Quotation request marked as declined.');
    }

    private function authorizeQuoteOwnership(MarketplaceQuote $quote): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->tenantContext->getTenant();
        if ($quote->tenant_id !== $tenant->id) {
            abort(403, 'Unauthorized quotation access');
        }
    }
}
