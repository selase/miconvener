<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Billing\BillingNotifier;
use App\Services\Billing\SubscriptionRenewalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BillingController extends Controller
{
    /**
     * Display a global list of all transactions.
     */
    public function transactions(Request $request): View
    {
        $this->authorize('access-superadmin-dashboard');

        $query = Transaction::with('tenant')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('provider_transaction_id', 'like', "%{$search}%")
                    ->orWhereHas('tenant', function ($q) use ($search): void {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $transactions = $query->paginate(15)->withQueryString();

        return view('admin.billing.transactions.index', [
            'transactions' => $transactions,
        ]);
    }

    /**
     * Every tenant's plan and renewal state: what they're on, whether they're
     * past due or complimentary, when they next renew, and how they'll pay.
     * Built from Tenant rather than Subscription so a tenant with no
     * subscription row (Free, or never billed) still appears.
     */
    public function subscriptions(Request $request): View
    {
        $this->authorize('access-superadmin-dashboard');

        $query = Tenant::query()
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $status = $request->input('status');

        if ($status === 'complimentary') {
            $query->where('billing_complimentary', true);
        } elseif ($status === 'free') {
            // whereHas('package', ...) needs the relation's return type declared
            // for PHPStan to recognize it; Tenant::package() isn't (a wider
            // typing pass belongs in its own change), so this filters by a
            // plain subquery on Package instead.
            $query->whereIn('package_id', Package::query()->where('is_free', true)->select('id'));
        } elseif (in_array($status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE], true)) {
            $query->where('billing_complimentary', false)
                ->whereHas('subscriptions', fn ($q) => $q->where('provider_id', 'like', 'ps\_%')->where('provider_status', $status));
        }

        $tenants = $query->paginate(20)->withQueryString();

        $renewals = app(SubscriptionRenewalService::class);
        $rows = $tenants->getCollection()->map(function (Tenant $tenant) use ($renewals): array {
            $subscription = Subscription::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider_id', 'like', 'ps\_%')
                ->latest('id')
                ->first();

            $package = Package::query()->find($tenant->package_id);
            $isFree = $package === null || $package->isFree();
            $current = $subscription && ! $isFree && in_array($subscription->provider_status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE], true)
                ? $subscription
                : null;
            $renewPackage = $current && ! $tenant->billing_complimentary ? $renewals->packageToRenew($current) : null;

            return [
                'tenant' => $tenant,
                'plan_name' => $package === null ? 'Free' : $package->name,
                'is_free' => $isFree,
                'complimentary' => (bool) $tenant->billing_complimentary && ! $isFree,
                'status' => $current?->provider_status,
                'renews_or_ends_on' => $current?->current_period_end?->format('j M Y'),
                'grace_ends_on' => $current?->grace_ends_at?->format('j M Y'),
                'payment_method' => $current?->authorization_label,
                'renew_amount' => $renewPackage ? BillingNotifier::money($current->priceMinorFor($renewPackage), (string) config('services.paystack.currency', 'GHS')) : null,
            ];
        });

        return view('admin.billing.subscriptions.index', [
            'tenants' => $tenants,
            'rows' => $rows,
        ]);
    }

    /**
     * Comp or un-comp a tenant's plan from the admin UI, mirroring
     * `billing:complimentary`.
     */
    public function toggleComplimentary(Tenant $tenant): RedirectResponse
    {
        $this->authorize('access-superadmin-dashboard');

        $tenant->forceFill(['billing_complimentary' => ! $tenant->billing_complimentary])->save();

        // This layout's toast only reads 'message' + 'status'; a 'success' key
        // (the convention several sibling admin controllers use) is never shown.
        return back()->with([
            'message' => $tenant->billing_complimentary
                ? "{$tenant->name} is now complimentary."
                : "{$tenant->name} is billed again.",
            'status' => 'success',
        ]);
    }
}
