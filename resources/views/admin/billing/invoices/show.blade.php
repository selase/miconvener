@extends('layouts.admin.master')

@section('title', 'Invoice Details: ' . $invoice->number)

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        
        <div class="card card-flush shadow-sm">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <div class="d-flex align-items-center position-relative my-1">
                       <h3 class="fw-bolder">Invoice #{{ $invoice->number }}</h3>
                       <span class="badge badge-light-{{ $invoice->status === 'draft' ? 'warning' : ($invoice->status === 'paid' ? 'success' : 'primary') }} ms-4">
                           {{ strtoupper($invoice->status) }}
                       </span>
                    </div>
                </div>
                <div class="card-toolbar">
                    @if($invoice->status === 'draft')
                        <form id="issueInvoiceForm" action="{{ route('admin.billing.invoices.issue', $invoice) }}" method="POST" class="me-3">
                            @csrf
                            <button type="button" class="btn btn-primary" id="issueInvoiceBtn">
                                Issue Invoice
                            </button>
                        </form>
                    @endif
                    <div class="me-3">
                        <button type="button" class="btn btn-light-success btn-active-success" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                            Actions
                        </button>
                        <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-800 menu-state-bg-light-primary fw-bold w-200px py-3" data-kt-menu="true">
                            @if($invoice->status !== \App\Models\Invoice::STATUS_DRAFT)
                                <div class="menu-item px-3">
                                    <form id="resendInvoiceForm" action="{{ route('admin.billing.invoices.resend', $invoice) }}" method="POST">
                                        @csrf
                                        <a href="javascript:;" id="resendInvoiceBtn" class="menu-link px-3">Resend Email</a>
                                    </form>
                                </div>
                            @endif
                            <div class="menu-item px-3">
                                <a href="{{ route('admin.billing.invoices.download', $invoice) }}" class="menu-link px-3">Download PDF</a>
                            </div>
                            <div class="menu-item px-3">
                                <a href="javascript:window.print();" class="menu-link px-3">Print Invoice</a>
                            </div>
                        </div>
                    </div>
                    <a href="{{ route('admin.billing.invoices.index') }}" class="btn btn-light">Back to List</a>
                </div>
            </div>

            <div class="card-body py-4">
                <div class="row mb-10">
                    <div class="col-md-4">
                        <label class="fw-bold fs-7 text-muted text-uppercase">Billed To</label>
                        <div class="fs-6 fw-bolder">{{ $invoice->tenant->name }}</div>
                        <div class="text-muted">{{ $invoice->tenant->email }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold fs-7 text-muted text-uppercase">Period</label>
                        <div class="fs-6 fw-bolder">{{ $invoice->period_start->format('M d, Y') }} - {{ $invoice->period_end->format('M d, Y') }}</div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <label class="fw-bold fs-7 text-muted text-uppercase">Amount Due</label>
                        <div class="fs-2hx fw-bolder text-dark">${{ number_format((float)$invoice->total, 2) }}</div>
                        <div class="text-muted small">Due by {{ $invoice->due_at->format('M d, Y') }}</div>
                    </div>
                </div>

                <div class="separator separator-dashed my-5"></div>

                <div class="table-responsive">
                    <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                        <thead>
                            <tr class="fw-bolder text-muted bg-light">
                                <th class="ps-4 min-w-300px rounded-start">Description</th>
                                <th class="min-w-100px text-center">Qty</th>
                                <th class="min-w-100px text-center">Unit Price</th>
                                <th class="min-w-120px text-end pe-4 rounded-end">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoice->items as $item)
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex flex-column">
                                            <span class="fw-bolder text-gray-800 fs-6">{{ $item->description }}</span>
                                            @if($item->metric)
                                                <span class="text-muted fs-7">Metric: {{ $item->metric->name }}</span>
                                            @endif
                                            @if($item->meta && isset($item->meta['type']) && $item->meta['type'] === 'adjustment')
                                                <form action="{{ route('admin.billing.invoices.items.destroy', $item) }}" method="POST" class="mt-1 delete-adjustment-form">
                                                    @csrf 
                                                    @method('DELETE')
                                                    <button type="button" class="btn btn-link btn-color-danger p-0 fs-8 remove-adj-btn">Remove Adjustment</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-center">{{ number_format((float)$item->quantity, 2) }}</td>
                                    <td class="text-center">${{ number_format((float)$item->unit_price, 4) }}</td>
                                    <td class="text-end pe-4 fw-bolder">${{ number_format((float)$item->subtotal, 2) }}</td>
                                </tr>
                            @endforeach
                            
                            @if($invoice->status === 'draft')
                                <tr>
                                    <td colspan="4">
                                        <button type="button" class="btn btn-sm btn-light-success border-dashed w-100 py-3" data-bs-toggle="modal" data-bs-target="#addAdjustmentModal">
                                            + Add Manual Adjustment / Credit
                                        </button>
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end fw-bold fs-6">Subtotal</td>
                                <td class="text-end pe-4 fs-6">${{ number_format((float)$invoice->subtotal, 2) }}</td>
                            </tr>
                            @if($invoice->tax_details)
                                @foreach($invoice->tax_details as $tax)
                                    <tr>
                                        <td colspan="3" class="text-end text-muted">{{ $tax['name'] }} ({{ $tax['rate'] }}%)</td>
                                        <td class="text-end pe-4 text-muted">${{ number_format((float)$tax['amount'], 2) }}</td>
                                    </tr>
                                @endforeach
                            @endif
                            <tr class="border-top border-top-dashed">
                                <td colspan="3" class="text-end fw-bolder fs-4">Total</td>
                                <td class="text-end pe-4 fw-bolder fs-4">${{ number_format((float)$invoice->total, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Add Adjustment Modal -->
<div class="modal fade" id="addAdjustmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mw-450px">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="fw-bolder">Add Adjustment</h2>
                <div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i>
                </div>
            </div>
            <form action="{{ route('admin.billing.invoices.adjust', $invoice) }}" method="POST">
                @csrf
                <div class="modal-body py-10 px-lg-17">
                    <div class="fv-row mb-7">
                        <label class="required fw-bold fs-6 mb-2">Description</label>
                        <input type="text" name="description" class="form-control form-control-solid" placeholder="e.g. Service Credit" required>
                    </div>
                    <div class="fv-row mb-7">
                        <label class="required fw-bold fs-6 mb-2">Amount ($)</label>
                        <input type="number" step="0.01" name="amount" class="form-control form-control-solid" placeholder="0.00" required>
                        <div class="text-muted fs-7 mt-2">Use negative values for discounts/credits.</div>
                    </div>
                </div>
                <div class="modal-footer flex-center">
                    <button type="reset" class="btn btn-light me-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Row</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.getElementById('issueInvoiceBtn')?.addEventListener('click', function (e) {
        Swal.fire({
            text: "Ready to issue this invoice? It will become visible to the tenant and an email notification will be sent.",
            icon: "warning",
            showCancelButton: true,
            buttonsStyling: false,
            confirmButtonText: "Yes, issue it!",
            cancelButtonText: "No, cancel",
            customClass: {
                confirmButton: "btn fw-bold btn-primary",
                cancelButton: "btn fw-bold btn-active-light-primary"
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                document.getElementById('issueInvoiceForm').submit();
            }
        });
    });

    // Handle resend confirmation
    document.getElementById('resendInvoiceBtn')?.addEventListener('click', function (e) {
        Swal.fire({
            text: "Are you sure you want to resend the invoice email to the tenant?",
            icon: "info",
            showCancelButton: true,
            buttonsStyling: false,
            confirmButtonText: "Yes, resend it!",
            cancelButtonText: "No, cancel",
            customClass: {
                confirmButton: "btn fw-bold btn-primary",
                cancelButton: "btn fw-bold btn-active-light-primary"
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                document.getElementById('resendInvoiceForm').submit();
            }
        });
    });

    // Handle adjustment removal confirmation
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-adj-btn')) {
            const form = e.target.closest('.delete-adjustment-form');
            Swal.fire({
                text: "Are you sure you want to remove this adjustment line item?",
                icon: "warning",
                showCancelButton: true,
                buttonsStyling: false,
                confirmButtonText: "Yes, remove it!",
                cancelButtonText: "No, cancel",
                customClass: {
                    confirmButton: "btn fw-bold btn-danger",
                    cancelButton: "btn fw-bold btn-active-light-primary"
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        }
    });
</script>
@endpush
