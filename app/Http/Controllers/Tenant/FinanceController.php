<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\Payment\PaystackGateway;
use App\Services\Payment\StripeGateway;
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

    public function refund(Request $request, string $subdomain, MerchantTransaction $transaction)
    {
        $tenant = $this->tenantContext->getTenant();

        $this->authorize('update event');

        if (! $tenant->featureEnabled('commerce')) {
            abort(403);
        }

        if ($transaction->tenant_id !== $tenant->id) {
            abort(403);
        }

        if ($transaction->status !== 'succeeded' || $transaction->type !== 'payment') {
            return back()->with('error', 'This transaction has already been refunded or is not eligible for a refund.');
        }

        $merchantGateway = $this->resolveTenantGatewayFor($tenant, $transaction);

        if (! $merchantGateway) {
            return back()->with(
                'error',
                "Refund failed: no active {$transaction->provider} gateway is configured for this organizer. Configure it under Settings > Payments and try again."
            );
        }

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

    /**
     * Resolves the gateway that actually took this charge, using the tenant's
     * own credentials for the transaction's own provider. Deliberately avoids
     * the PaymentGateway container binding: that binding picks whichever
     * active gateway sorts first (so a Paystack charge could be handed to
     * Stripe) and, with no active gateway at all, silently falls back to the
     * platform's own credentials — refunding a tenant's customer out of the
     * platform's account. Returns null when the tenant has no active gateway
     * for this provider.
     */
    private function resolveTenantGatewayFor(Tenant $tenant, MerchantTransaction $transaction): ?PaymentGateway
    {
        $gateway = TenantPaymentGateway::where('tenant_id', $tenant->id)
            ->where('provider', $transaction->provider)
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            return null;
        }

        $config = [
            'secret_key' => $gateway->api_key_encrypted,
            'public_key' => $gateway->public_key_encrypted,
        ];

        return match ($transaction->provider) {
            'paystack' => new PaystackGateway($config),
            'stripe' => new StripeGateway(app(\Stripe\StripeClient::class), $config),
            default => null,
        };
    }
}
