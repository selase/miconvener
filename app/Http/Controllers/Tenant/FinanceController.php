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
use Inertia\Inertia;
use Inertia\Response;

final class FinanceController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(Request $request): Response
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

        $transactions = $query->paginate(15)->withQueryString()->through(fn (MerchantTransaction $transaction): array => [
            'id' => $transaction->id,
            'provider_transaction_id' => $transaction->provider_transaction_id,
            'customer_name' => $transaction->customer_name,
            'customer_email' => $transaction->customer_email,
            'amount' => $transaction->amount,
            'amount_formatted' => number_format($transaction->amount / 100, 2),
            'currency' => $transaction->currency,
            'status' => $transaction->status,
            'type' => $transaction->type,
            'provider' => $transaction->provider,
            'created_at' => $transaction->created_at->format('Y-m-d H:i'),
            'can_refund' => $transaction->status === 'succeeded' && $transaction->type === 'payment',
        ]);

        $stats = [
            'total_volume' => MerchantTransaction::where('tenant_id', $tenant->id)->where('status', 'succeeded')->sum('amount'),
            'transaction_count' => MerchantTransaction::where('tenant_id', $tenant->id)->where('type', 'payment')->count(),
            'refund_volume' => MerchantTransaction::where('tenant_id', $tenant->id)->where('type', 'refund')->sum('amount'),
        ];

        return Inertia::render('Tenant/Finance/Index', [
            'transactions' => $transactions,
            'stats' => $stats,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function refund(Request $request, MerchantTransaction $transaction, PaymentGateway $gateway)
    {
        $tenant = $this->tenantContext->getTenant();

        if ($transaction->tenant_id !== $tenant->id) {
            abort(403);
        }

        $request->attributes->set('payment_context', 'commerce');
        $merchantGateway = app(PaymentGateway::class);

        try {
            $refundId = $merchantGateway->refund($transaction->provider_transaction_id);

            MerchantTransaction::create([
                'tenant_id' => $tenant->id,
                'provider' => $transaction->provider,
                'provider_transaction_id' => $refundId !== 'pending' ? $refundId : 'REF_'.$transaction->provider_transaction_id,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'status' => 'succeeded',
                'type' => 'refund',
                'description' => 'Refund for '.$transaction->provider_transaction_id,
                'customer_email' => $transaction->customer_email,
                'meta' => ['refund_id' => $refundId],
            ]);

            $transaction->update(['status' => 'refunded']);

            return back()->with('success', 'Refund processed successfully.');

        } catch (Exception $e) {
            Log::error('Merchant refund failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Refund failed: '.$e->getMessage());
        }
    }
}
