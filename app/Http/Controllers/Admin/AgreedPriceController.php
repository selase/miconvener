<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateAgreedPriceRequest;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\BillingNotifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Superadmin entry of an Enterprise organisation's negotiated price. Checkout
 * and renewals charge it, the organisation sees it on its Billing page, and is
 * emailed whenever it is set or changed.
 */
final class AgreedPriceController extends Controller
{
    public function show(Tenant $tenant): View
    {
        $this->authorize('access-superadmin-dashboard');

        $subscription = $this->paidSubscription($tenant);

        return view('admin.tenants.agreed-price', [
            'tenant' => $tenant,
            'isEnterprise' => $tenant->package?->slug === 'enterprise',
            'subscription' => $subscription,
        ]);
    }

    public function update(UpdateAgreedPriceRequest $request, Tenant $tenant, BillingNotifier $notifier): RedirectResponse
    {
        $package = $tenant->package;

        if ($package?->slug !== 'enterprise') {
            return back()->with('error', 'Agreed prices are for Enterprise. Move the organisation to Enterprise first (Edit).');
        }

        $price = $request->validated('price');

        $tenant->update([
            'agreed_price_pesewas' => $price === null ? null : (int) round((float) $price * 100),
            'agreed_price_interval' => $price === null ? null : $request->validated('interval'),
        ]);

        if ($price === null) {
            return back()->with('success', 'Agreed price removed. Enterprise checkout is closed until a new price is set.');
        }

        $subscription = $this->paidSubscription($tenant);

        // The next renewal must cover the period the price is for.
        $subscription?->update(['interval' => $tenant->agreed_price_interval]);

        $emailed = $notifier->agreedPriceSet($tenant, $package, $subscription !== null);

        return back()->with('success', 'Agreed price saved'.($emailed ? ' and emailed to the organisation.' : '. The organisation already had this price, so no email was sent.'));
    }

    private function paidSubscription(Tenant $tenant): ?Subscription
    {
        return Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider_id', 'like', 'ps\_%')
            ->whereIn('provider_status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->latest('id')
            ->first();
    }
}
