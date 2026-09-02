<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use App\Notifications\InvoiceIssued;
use App\Services\Tenancy\PdfInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

final class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('access-superadmin-dashboard');

        $query = Invoice::with('tenant')->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('tenant', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $invoices = $query->paginate(15)->withQueryString();

        return view('admin.billing.invoices.index', [
            'invoices' => $invoices,
            'breadcrumbs' => [
                ['link' => route('dashboard'), 'name' => __('Home')],
                ['name' => __('Invoices')],
            ],
        ]);
    }

    public function show(string $id): View
    {
        $this->authorize('access-superadmin-dashboard');

        $invoice = Invoice::with(['tenant', 'items'])->findOrFail($id);

        return view('admin.billing.invoices.show', [
            'invoice' => $invoice,
            'breadcrumbs' => [
                ['link' => route('dashboard'), 'name' => __('Home')],
                ['link' => route('admin.billing.invoices.index'), 'name' => __('Invoices')],
                ['name' => $invoice->number],
            ],
        ]);
    }

    public function download(Invoice $invoice, PdfInvoiceService $pdfService)
    {
        $this->authorize('access-superadmin-dashboard');

        return $pdfService->download($invoice);
    }

    public function issue(Invoice $invoice)
    {
        $this->authorize('access-superadmin-dashboard');

        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            return back()->with('error', 'Only draft invoices can be issued.');
        }

        $invoice->update(['status' => Invoice::STATUS_ISSUED]);

        \App\Events\InvoiceIssued::dispatch($invoice);
        $this->notifyTenant($invoice);

        return back()->with('success', "Invoice {$invoice->number} has been issued and notified.");
    }

    public function bulkIssue(Request $request)
    {
        $this->authorize('access-superadmin-dashboard');

        $ids = $request->input('ids', []);

        $invoices = Invoice::whereIn('id', $ids)
            ->where('status', Invoice::STATUS_DRAFT)
            ->get();

        foreach ($invoices as $invoice) {
            $invoice->update(['status' => Invoice::STATUS_ISSUED]);
            \App\Events\InvoiceIssued::dispatch($invoice);
            $this->notifyTenant($invoice);
        }

        return back()->with('success', $invoices->count().' invoices have been issued and notified.');
    }

    public function resend(Invoice $invoice)
    {
        $this->authorize('access-superadmin-dashboard');

        if ($invoice->status === Invoice::STATUS_DRAFT) {
            return back()->with('error', 'Draft invoices cannot be resent. Please issue them first.');
        }

        $this->notifyTenant($invoice);

        return back()->with('success', "Invoice {$invoice->number} notification has been resent.");
    }

    public function addAdjustment(Request $request, Invoice $invoice)
    {
        $this->authorize('access-superadmin-dashboard');

        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            return back()->with('error', 'Adjustments can only be added to draft invoices.');
        }

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => $validated['description'],
            'quantity' => 1.0,
            'unit_price' => $validated['amount'],
            'subtotal' => $validated['amount'],
            'meta' => ['type' => 'adjustment'],
        ]);

        $this->recalculateTotals($invoice);

        return back()->with('success', 'Adjustment added successfully.');
    }

    public function removeAdjustment(InvoiceItem $item)
    {
        $this->authorize('access-superadmin-dashboard');

        $invoice = $item->invoice;

        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            return back()->with('error', 'Items can only be removed from draft invoices.');
        }

        $item->delete();

        $this->recalculateTotals($invoice);

        return back()->with('success', 'Line item removed.');
    }

    public function create(): View
    {
        $this->authorize('access-superadmin-dashboard');

        $tenants = \App\Models\Tenant::orderBy('name')->get(['id', 'name', 'slug']);

        return view('admin.billing.invoices.create', [
            'tenants' => $tenants,
            'breadcrumbs' => [
                ['link' => route('dashboard'), 'name' => __('Home')],
                ['link' => route('admin.billing.invoices.index'), 'name' => __('Invoices')],
                ['name' => __('Create New')],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('access-superadmin-dashboard');

        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'due_date' => 'required|date|after_or_equal:today',
            'currency' => 'required|string|size:3',
        ]);

        $tenant = \App\Models\Tenant::findOrFail($validated['tenant_id']);

        // Generate Invoice Number
        $number = 'INV-'.date('Ymd').'-'.mb_strtoupper(\Illuminate\Support\Str::random(4));

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'number' => $number,
            'period_start' => now(),
            'period_end' => now()->addMonth(), // Default to 1 month coverage expectation
            'due_at' => $validated['due_date'],
            'status' => Invoice::STATUS_DRAFT,
            'currency' => $validated['currency'],
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
            'tax_details' => [],
            'meta' => ['source' => 'ad-hoc'],
        ]);

        return redirect()->route('admin.billing.invoices.show', $invoice)
            ->with('success', 'Draft invoice created. Please add line items.');
    }

    private function notifyTenant(Invoice $invoice): void
    {
        // Find users in this tenant to notify.
        // We look for users with 'admin' role or just all users if it's a small tenant.
        $users = $invoice->tenant->users()->get();

        if ($users->isNotEmpty()) {
            Notification::send($users, new InvoiceIssued($invoice));
        }
    }

    private function recalculateTotals(Invoice $invoice): void
    {
        $subtotal = $invoice->items()->sum('subtotal');
        $taxCalc = Tax::calculateFor((float) $subtotal);

        $invoice->update([
            'subtotal' => $subtotal,
            'tax_total' => $taxCalc['total_tax'],
            'total' => $subtotal + $taxCalc['total_tax'],
            'tax_details' => $taxCalc['taxes'],
        ]);
    }
}
