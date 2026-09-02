<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\MerchantTransaction;
use App\Services\Tenancy\TenantContext;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

final class FinanceController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Display a listing of merchant transactions.
     */
    public function index(Request $request): View
    {
        $tenant = $this->tenantContext->getTenant();

        if (! $tenant->featureEnabled('commerce')) {
            abort(403);
        }

        $query = MerchantTransaction::where('tenant_id', $tenant->id)->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('provider_transaction_id', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $transactions = $query->paginate(15);

        // Stats for cards
        $stats = [
            'total_volume' => MerchantTransaction::where('tenant_id', $tenant->id)->where('status', 'succeeded')->sum('amount'),
            'transaction_count' => MerchantTransaction::where('tenant_id', $tenant->id)->where('type', 'payment')->count(),
            'refund_volume' => MerchantTransaction::where('tenant_id', $tenant->id)->where('type', 'refund')->sum('amount'),
        ];

        // Analytics: Last 6 months revenue (aggregated in DB, not in PHP)
        $sixMonthsAgo = now()->subMonths(5)->startOfMonth();
        $monthlyRaw = MerchantTransaction::where('tenant_id', $tenant->id)
            ->where('status', 'succeeded')
            ->where('type', 'payment')
            ->where('created_at', '>=', $sixMonthsAgo)
            ->selectRaw("to_char(created_at, 'Mon') as month_label, sum(amount) as total_amount")
            ->groupByRaw("to_char(created_at, 'Mon'), date_trunc('month', created_at)")
            ->orderByRaw("date_trunc('month', created_at)")
            ->pluck('total_amount', 'month_label');

        $monthlyStats = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i)->format('M');
            $monthlyStats[] = ['label' => $month, 'amount' => ($monthlyRaw->get($month, 0)) / 100];
        }

        $breadcrumbs = [
            ['link' => route('tenant.dashboard'), 'name' => __('Dashboard')],
            ['link' => '#', 'name' => __('Finance & Sales')],
        ];

        return view('tenant.finance.index', [
            'transactions' => $transactions,
            'stats' => $stats,
            'monthlyStats' => $monthlyStats,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    /**
     * Process a refund for a transaction.
     */
    public function refund(Request $request, MerchantTransaction $transaction, PaymentGateway $gateway)
    {
        $tenant = $this->tenantContext->getTenant();

        if ($transaction->tenant_id !== $tenant->id) {
            abort(403);
        }

        // IMPORTANT: Set commerce context for the gateway factory!
        $request->attributes->set('payment_context', 'commerce');
        // Re-resolve or let Laravel handle it if it wasn't instantiated yet.
        // Actually, since $gateway is injected, we need to make sure the resolve happened WITH the attribute set.
        // Usually, method injection happens during call.
        // If we want to be SURE, we pull it from app() AFTER setting the attribute.
        $merchantGateway = app(PaymentGateway::class);

        try {
            $result = $merchantGateway->refund($transaction->provider_transaction_id);

            // Create refund record
            MerchantTransaction::create([
                'tenant_id' => $tenant->id,
                'provider' => $transaction->provider,
                'provider_transaction_id' => $result['id'] ?? 'REF_'.$transaction->provider_transaction_id,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'status' => 'succeeded',
                'type' => 'refund',
                'description' => 'Refund for '.$transaction->provider_transaction_id,
                'customer_email' => $transaction->customer_email,
                'meta' => $result,
            ]);

            $transaction->update(['status' => 'refunded']);

            return back()->with('success', 'Refund processed successfully.');

        } catch (Exception $e) {
            Log::error('Merchant refund failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Refund failed: '.$e->getMessage());
        }
    }
}
