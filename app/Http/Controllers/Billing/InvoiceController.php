<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Tenancy\PdfInvoiceService;
use App\Services\Tenancy\TenantContext;

final class InvoiceController extends Controller
{
    public function show(string $id, TenantContext $tenantContext): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $tenant = $tenantContext->getTenant();

        $invoice = Invoice::with('items')
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', Invoice::STATUS_DRAFT) // Safety: tenants never see drafts
            ->findOrFail($id);

        return view('billing.invoices.show', ['invoice' => $invoice, 'tenant' => $tenant]);
    }

    public function download(string $id, TenantContext $tenantContext, PdfInvoiceService $pdfService)
    {
        $tenant = $tenantContext->getTenant();

        $invoice = Invoice::where('tenant_id', $tenant->id)
            ->where('status', '!=', Invoice::STATUS_DRAFT)
            ->findOrFail($id);

        return $pdfService->download($invoice);
    }
}
