<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Tenancy\PdfInvoiceService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class InvoiceController extends Controller
{
    public function show(string $id, TenantContext $tenantContext): Response
    {
        $this->authorize('manage billing');

        $tenant = $tenantContext->getTenant();

        $invoice = Invoice::with('items')
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', Invoice::STATUS_DRAFT) // Safety: tenants never see drafts
            ->findOrFail($id);

        return Inertia::render('Billing/Invoice', [
            'issuer' => (string) config('app.name'),
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'currency' => $invoice->currency ?: (string) config('services.paystack.currency', 'GHS'),
                'issued_at' => $invoice->created_at->format('M j, Y'),
                'due_at' => $invoice->due_at?->format('M j, Y'),
                'paid_at' => $invoice->paid_at?->format('M j, Y'),
                'period' => $invoice->period_start->format('M j').' – '.$invoice->period_end->format('M j, Y'),
                'bill_to' => ['name' => $tenant->name, 'email' => $tenant->email],
                'items' => $invoice->items->map(fn (InvoiceItem $item): array => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'metric' => $item->metric ? Str::headline(mb_strtolower($item->metric->name)) : null,
                    'quantity' => number_format((float) $item->quantity, 2),
                    'unit_price' => number_format((float) $item->unit_price, 4),
                    'subtotal' => number_format((float) $item->subtotal, 2),
                ]),
                'subtotal' => number_format((float) $invoice->subtotal, 2),
                'taxes' => collect($invoice->tax_details ?? [])->map(fn (array $tax): array => [
                    'name' => $tax['name'] ?? 'Tax',
                    'rate' => $tax['rate'] ?? null,
                    'amount' => number_format((float) ($tax['amount'] ?? 0), 2),
                ])->values(),
                'total' => number_format((float) $invoice->total, 2),
                // Checkout accepts issued invoices only, so an overdue or void
                // one must not offer a button that would 404.
                'can_pay' => $invoice->status === Invoice::STATUS_ISSUED,
            ],
        ]);
    }

    public function download(string $id, TenantContext $tenantContext, PdfInvoiceService $pdfService)
    {
        $this->authorize('manage billing');

        $tenant = $tenantContext->getTenant();

        $invoice = Invoice::where('tenant_id', $tenant->id)
            ->where('status', '!=', Invoice::STATUS_DRAFT)
            ->findOrFail($id);

        return $pdfService->download($invoice);
    }
}
