<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Enum\UsageMetric;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Billing\BillingNotifier;
use App\Services\Billing\RenewalScheduler;
use App\Services\Billing\SubscriptionRenewalService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BillingController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();

        if (! $tenant instanceof Tenant) {
            $tenant = auth()->user()->latestTenant;
        }

        $transactions = Transaction::where('tenant_id', $tenant->id)
            ->latest()
            ->paginate(10)
            ->through(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'description' => $transaction->description ?? $transaction->type,
                'amount_formatted' => number_format($transaction->amount / 100, 2),
                'currency' => $transaction->currency,
                'status' => $transaction->status,
                'created_at' => $transaction->created_at->format('Y-m-d'),
                'can_refund' => false,
            ]);

        $invoices = Invoice::where('tenant_id', $tenant->id)
            ->where('status', '!=', Invoice::STATUS_DRAFT)
            ->latest()
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'total' => number_format((float) $invoice->total, 2),
                'status' => $invoice->status,
                'created_at' => $invoice->created_at->format('Y-m-d'),
            ]);

        // Analytics: Last 6 months revenue
        $sixMonthsAgo = now()->subMonths(5)->startOfMonth();
        $monthlyData = Transaction::where('tenant_id', $tenant->id)
            ->whereIn('status', ['success', 'succeeded'])
            ->where('created_at', '>=', $sixMonthsAgo)
            ->get()
            ->groupBy(fn ($transaction): string => $transaction->created_at->format('M'));

        $monthlyStats = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthLabel = now()->subMonths($i)->format('M');
            $total = $monthlyData->get($monthLabel, collect())->sum('amount');

            $monthlyStats[] = [
                'label' => $monthLabel,
                'amount' => $total,
                'formatted' => number_format($total / 100, 2),
            ];
        }

        return Inertia::render('Billing/Index', [
            'transactions' => $transactions,
            'invoices' => $invoices,
            'subscription' => $this->planSummary($tenant),
            'accruedMetered' => number_format($this->calculateAccruedMetered($tenant), 2),
            'monthlyStats' => $monthlyStats,
            'currency' => (string) config('services.paystack.currency', 'GHS'),
        ]);
    }

    public function pricing(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->getTenant();
        $packages = Package::where('is_active', true)->with('features')->orderBy('sort_order')->get();

        return Inertia::render('Billing/Pricing', [
            'packages' => $packages->map(fn (Package $package): array => [
                'id' => $package->id,
                'slug' => $package->slug,
                'name' => $package->name,
                'price' => (float) $package->price,
                'yearly_price' => (float) ($package->yearly_price ?? $package->price * 10),
                'is_free' => $package->isFree(),
                'features' => $package->features->pluck('name'),
            ]),
            'currentPackageSlug' => $tenant->package?->slug,
            'currency' => (string) config('services.paystack.currency', 'GHS'),
        ]);
    }

    /**
     * What the tenant is on and what happens next, for the billing page.
     *
     * @return array{package_name: string, is_free: bool, complimentary: bool, status: ?string, period_end: ?string, grace_ends_at: ?string, auto_renews: bool, payment_method: ?string, ends_at_period_end: bool, can_pay_now: bool, renew_amount: ?string}
     */
    private function planSummary(Tenant $tenant): array
    {
        $package = Package::query()->find($tenant->package_id);
        $isFree = ! $package || $package->isFree();
        $current = Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('provider_status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->latest('id')
            ->first();

        // Only plans paid through Paystack one-off charges are renewed here;
        // another provider's subscription renews itself.
        $renewable = $current !== null && str_starts_with((string) $current->provider_id, 'ps_') && ! $isFree && ! $tenant->billing_complimentary;
        $renewPackage = $renewable ? app(SubscriptionRenewalService::class)->packageToRenew($current) : null;
        $daysLeft = $current?->current_period_end ? now()->startOfDay()->diffInDays($current->current_period_end->copy()->startOfDay(), false) : null;
        $autoRenews = $renewPackage !== null && $current->canBeChargedAutomatically();

        return [
            'package_name' => $isFree ? ($package->name ?? 'Free') : $package->name,
            'is_free' => $isFree,
            'complimentary' => (bool) $tenant->billing_complimentary && ! $isFree,
            'status' => $current?->provider_status,
            'period_end' => $current?->current_period_end?->format('j F Y'),
            'grace_ends_at' => $current?->grace_ends_at?->format('j F Y'),
            'auto_renews' => $autoRenews,
            'payment_method' => $current?->authorization_label,
            'ends_at_period_end' => $renewable && $renewPackage === null,
            'can_pay_now' => $renewPackage !== null && ($current->isPastDue() || (! $autoRenews && $daysLeft !== null && $daysLeft <= RenewalScheduler::REMINDER_DAYS[0])),
            'renew_amount' => $renewPackage ? BillingNotifier::money($current->priceMinorFor($renewPackage), (string) config('services.paystack.currency', 'GHS')) : null,
        ];
    }

    private function calculateAccruedMetered(Tenant $tenant): float
    {
        $total = 0.0;
        $start = now()->startOfMonth();

        $metrics = UsageMetric::cases();

        $tenant->load(['usagePrices', 'package.usagePrices']);

        foreach ($metrics as $metric) {
            $usageCount = (float) $tenant->usage()
                ->where('feature_slug', $metric->value)
                ->where('period_start', '>=', $start)
                ->sum('used_count');

            if ($usageCount <= 0) {
                continue;
            }

            $priceModel = $tenant->usagePrices->firstWhere('metric', $metric);

            if (! $priceModel && $tenant->package) {
                $priceModel = $tenant->package->usagePrices->firstWhere('metric', $metric);
            }

            if ($priceModel) {
                $unitPrice = (float) $priceModel->unit_price;
                $perUnits = (float) ($priceModel->unit_quantity ?? 1);

                if ($perUnits > 0) {
                    $total += ($usageCount / $perUnits) * $unitPrice;
                }
            }
        }

        return $total;
    }
}
