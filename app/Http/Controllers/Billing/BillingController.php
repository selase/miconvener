<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Enum\UsageMetric;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\Transaction;
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

        $subscription = $tenant->latestSubscription;

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
            'subscription' => $subscription ? [
                'package_name' => $tenant->package?->name,
                'status' => $subscription->provider_status,
                'current_period_end' => $subscription->current_period_end?->format('Y-m-d'),
            ] : null,
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

    private function calculateAccruedMetered(Tenant $tenant): float
    {
        $total = 0.0;
        $start = now()->startOfMonth();

        $metrics = UsageMetric::cases();

        $tenant->load(['usagePrices', 'package.usagePrices']);

        foreach ($metrics as $metric) {
            $usageCount = $tenant->usage()
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
