<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\EventLedgerEntry;
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

        // A platform_default tenant's charges are collected on the platform's own
        // Paystack account and never touch a TenantPaymentGateway, so they never
        // produce a MerchantTransaction row — querying that table here always
        // returned zero rows and zeroed-out stats for them. EventLedgerEntry is
        // written for every event-ticket charge/refund regardless of settlement
        // mode (see EventFinanceController), so it is the complete record for
        // these tenants. An own_gateway tenant keeps reading MerchantTransaction
        // so its figures are unaffected by this change and a single charge is
        // never summed from both sources.
        return $tenant->isPlatformDefaultSettlement()
            ? $this->ledgerBackedIndex($request, $tenant)
            : $this->merchantTransactionBackedIndex($request, $tenant);
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

    private function merchantTransactionBackedIndex(Request $request, Tenant $tenant): Response
    {
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

    /**
     * Mirrors merchantTransactionBackedIndex() for platform_default tenants,
     * reading EventLedgerEntry instead of MerchantTransaction. 'collected' and
     * 'refunded' are computed exactly as EventFinanceController defines them
     * (gross_amount summed by type) so the tenant-wide totals agree with the
     * per-event panel they are rolling up.
     */
    private function ledgerBackedIndex(Request $request, Tenant $tenant): Response
    {
        $query = EventLedgerEntry::where('tenant_id', $tenant->id)
            ->whereIn('type', [EventLedgerEntry::TYPE_CHARGE, EventLedgerEntry::TYPE_REFUND])
            ->with('registration')
            ->latest('created_at');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('provider_reference', 'like', "%{$search}%")
                    ->orWhereHas('registration', function ($registrationQuery) use ($search): void {
                        $registrationQuery->where('email', 'like', "%{$search}%")
                            ->orWhere('full_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $type = match ($request->input('status')) {
                'succeeded' => EventLedgerEntry::TYPE_CHARGE,
                'refunded' => EventLedgerEntry::TYPE_REFUND,
                // 'pending' and 'failed' have no ledger equivalent: an entry is
                // only ever written once a charge or refund has succeeded.
                default => null,
            };

            $type !== null ? $query->where('type', $type) : $query->whereRaw('1 = 0');
        }

        $transactions = $query->paginate(15)->withQueryString()->through(fn (EventLedgerEntry $entry): array => [
            'id' => $entry->id,
            'provider_transaction_id' => $entry->provider_reference,
            'customer_name' => $entry->registration?->full_name,
            'customer_email' => $entry->registration?->email,
            'amount' => $entry->gross_amount,
            'amount_formatted' => number_format($entry->gross_amount / 100, 2),
            'currency' => $entry->currency,
            'status' => $entry->type === EventLedgerEntry::TYPE_REFUND ? 'refunded' : 'succeeded',
            'type' => $entry->type === EventLedgerEntry::TYPE_REFUND ? 'refund' : 'payment',
            'provider' => $entry->provider,
            'created_at' => $entry->created_at->format('Y-m-d H:i'),
            // Refunds for a platform-collected ticket are issued by cancelling
            // the registration (see EventRegistrationController::cancel()), not
            // through MerchantTransaction::refund() — this view never offers it.
            'can_refund' => false,
        ]);

        $stats = [
            'total_volume' => (int) EventLedgerEntry::where('tenant_id', $tenant->id)->where('type', EventLedgerEntry::TYPE_CHARGE)->sum('gross_amount'),
            'transaction_count' => EventLedgerEntry::where('tenant_id', $tenant->id)->where('type', EventLedgerEntry::TYPE_CHARGE)->count(),
            'refund_volume' => (int) EventLedgerEntry::where('tenant_id', $tenant->id)->where('type', EventLedgerEntry::TYPE_REFUND)->sum('gross_amount'),
        ];

        return Inertia::render('Tenant/Finance/Index', [
            'transactions' => $transactions,
            'stats' => $stats,
            'filters' => $request->only(['search', 'status']),
        ]);
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
