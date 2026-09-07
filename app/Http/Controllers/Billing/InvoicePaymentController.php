<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

final class InvoicePaymentController extends Controller
{
    public function checkout(Request $request, PaymentGateway $gateway, TenantContext $tenantContext): \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse
    {
        $tenant = $tenantContext->getTenant();
        if (! $tenant instanceof \App\Models\Tenant) {
            abort(404, 'Tenant context not found.');
        }

        $invoiceId = $request->input('invoice_id');
        $invoice = Invoice::where('tenant_id', $tenant->id)
            ->where('status', Invoice::STATUS_ISSUED) // Only issued invoices can be paid
            ->findOrFail($invoiceId);

        $driver = config('services.payment.default', 'stripe');
        $metaKey = "{$driver}_id";
        $customerId = $tenant->meta[$metaKey] ?? null;

        if (! $customerId) {
            // Use the email of the person paying (current user) or tenant default
            $customerId = $gateway->createCustomer($request->user()->email, $tenant->name);

            $meta = $tenant->meta ?? [];
            $meta[$metaKey] = $customerId;
            $tenant->update(['meta' => $meta]);
        }

        // Convert to cents/kobo (Laravel stores as float/decimal in DB)
        $amount = (int) round((float) $invoice->total * 100);

        $redirectUrl = route('billing.invoices.show', $invoice->id);

        $checkoutUrl = $gateway->createOneTimeCheckoutSession(
            $customerId,
            $amount,
            $invoice->currency ?: 'USD',
            $redirectUrl,
            [
                'invoice_id' => $invoice->id,
                'type' => 'metered_invoice',
                'source' => config('services.paystack.metadata_source'),
                'description' => "Invoice #{$invoice->number} for {$tenant->name}",
            ]
        );

        return redirect($checkoutUrl);
    }
}
