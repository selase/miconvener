<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Billing;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\TenantAddon;
use App\Services\Billing\TenantAddonService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class TenantAddonController extends Controller
{
    public function __construct(
        private readonly TenantAddonService $addonService
    ) {}

    public function index(Request $request, TenantContext $tenantContext, string $subdomain): Response
    {
        $this->authorize('manage billing');
        $tenant = $tenantContext->getTenant();

        $activeAddons = $tenant->addons()
            ->with('event:id,name,slug')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TenantAddon $addon): array => [
                'id' => $addon->id,
                'name' => $addon->name,
                'addon_type' => $addon->addon_type,
                'quantity' => $addon->quantity,
                'total_price' => $addon->formattedPrice(),
                'billing_interval' => $addon->billing_interval,
                'status' => $addon->status,
                'is_active' => $addon->isActive(),
                'event_name' => $addon->event?->name,
                'period_start' => $addon->period_start?->format('M d, Y'),
                'period_end' => $addon->period_end?->format('M d, Y'),
                'can_cancel' => $addon->status === TenantAddon::STATUS_ACTIVE && in_array($addon->billing_interval, [TenantAddon::INTERVAL_MONTHLY, TenantAddon::INTERVAL_YEARLY], true),
            ]);

        $purchasedSeats = $tenant->purchasedTeamSeatsCount();
        $totalSeatLimit = $tenant->totalTeamSeatLimit();
        $baseSeats = $totalSeatLimit !== null ? max(0, $totalSeatLimit - $purchasedSeats) : null;

        $events = Event::where('tenant_id', $tenant->id)
            ->published()
            ->orderByDesc('starts_at')
            ->limit(20)
            ->get(['id', 'name', 'slug', 'starts_at'])
            ->map(fn (Event $e): array => [
                'id' => $e->id,
                'name' => $e->name,
                'date' => $e->starts_at->format('M d, Y'),
            ]);

        return Inertia::render('Billing/Addons', [
            'catalog' => $this->addonService->getCatalog(),
            'activeAddons' => $activeAddons,
            'summary' => [
                'current_plan' => $tenant->package->name ?? 'Free',
                'team_users_count' => $tenant->users()->count(),
                'base_seats' => $baseSeats,
                'purchased_extra_seats' => $purchasedSeats,
                'total_seats_limit' => $totalSeatLimit,
                'usher_passes_count' => $tenant->purchasedUsherPassesCount(),
                'live_polling_enabled' => $tenant->canUseLivePolling(),
                'sms_balance' => (int) TenantAddon::where('tenant_id', $tenant->id)->active()->ofType(TenantAddon::TYPE_SMS_PACK)->sum('quantity'),
                'email_balance' => (int) TenantAddon::where('tenant_id', $tenant->id)->active()->ofType(TenantAddon::TYPE_EMAIL_PACK)->sum('quantity'),
            ],
            'events' => $events,
            'currency' => (string) config('services.paystack.currency', 'GHS'),
        ]);
    }

    public function checkout(Request $request, TenantContext $tenantContext, string $subdomain): SymfonyResponse
    {
        $this->authorize('manage billing');
        $tenant = $tenantContext->getTenant();

        $validated = $request->validate([
            'addon_key' => ['required', 'string', \Illuminate\Validation\Rule::in($this->addonService->purchasableKeys())],
            'multiplier' => ['nullable', 'integer', 'min:1', 'max:50'],
            'event_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('events', 'id')->where('tenant_id', $tenant->id)],
        ]);

        $session = $this->addonService->initializeCheckout(
            $tenant,
            $request->user(),
            $validated['addon_key'],
            [
                'multiplier' => (int) ($validated['multiplier'] ?? 1),
                'event_id' => $validated['event_id'] ?? null,
            ]
        );

        return Inertia::location($session['checkout_url']);
    }

    public function cancel(Request $request, TenantContext $tenantContext, string $subdomain, TenantAddon $addon): RedirectResponse
    {
        $this->authorize('manage billing');
        $tenant = $tenantContext->getTenant();

        $this->addonService->cancelAddon($tenant, $addon);

        return redirect()->route('billing.addons.index', ['subdomain' => $tenant->slug])
            ->with('success', "{$addon->name} cancelled. It will remain active until the end of its billing period.");
    }
}
