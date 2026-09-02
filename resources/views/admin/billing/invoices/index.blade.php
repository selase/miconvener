@extends('layouts.admin.master')

@section('title', 'System Invoices')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="card card-flush shadow-sm">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <div class="d-flex align-items-center position-relative my-1">
                        <span class="svg-icon svg-icon-1 position-absolute ms-6">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                <rect opacity="0.5" x="17.0365" y="15.1223" width="8.15546" height="2" rx="1" transform="rotate(45 17.0365 15.1223)" fill="currentColor" />
                                <path d="M11 19C6.55556 19 3 15.4444 3 11C3 6.55556 6.55556 3 11 3C15.4444 3 19 6.55556 19 11C19 15.4444 15.4444 19 11 19ZM11 5C7.53333 5 5 7.53333 5 11C5 14.4667 7.53333 17 11 17C14.4667 17 17 14.4667 17 11C17 7.53333 14.4667 5 11 5Z" fill="currentColor" />
                            </svg>
                        </span>
                        <form action="{{ route('admin.billing.invoices.index') }}" method="GET">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control form-control-solid w-250px ps-14" placeholder="Search Invoices" />
                        </form>
                    </div>
                </div>
                </div>
                <div class="card-toolbar">
                    <div class="d-flex justify-content-end" data-kt-user-table-toolbar="base">
                        <a href="{{ route('admin.billing.invoices.create') }}" class="btn btn-primary">
                            <span class="svg-icon svg-icon-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                    <rect opacity="0.5" x="11.364" y="20.364" width="16" height="2" rx="1" transform="rotate(-90 11.364 20.364)" fill="currentColor"></rect>
                                    <rect x="4.36396" y="11.364" width="16" height="2" rx="1" fill="currentColor"></rect>
                                </svg>
                            </span>
                            Create Invoice
                        </a>
                    </div>
                </div>
            </div>
            <div class="card-body pt-0">
                <form id="bulkActionForm" action="{{ route('admin.billing.invoices.bulk-issue') }}" method="POST">
                    @csrf
                    <div class="d-flex justify-content-end mb-4 invisible" id="bulkActions">
                        <button type="button" id="bulkIssueBtn" class="btn btn-sm btn-primary">
                            Issue Selected
                        </button>
                    </div>

                    <table class="table align-middle table-row-dashed fs-6 gy-5">
                        <thead>
                            <tr class="text-start text-gray-400 fw-bolder fs-7 text-uppercase gs-0">
                                <th class="w-10px pe-2">
                                    <div class="form-check form-check-sm form-check-custom form-check-solid me-3">
                                        <input class="form-check-input" type="checkbox" data-kt-check="true" data-kt-check-target="#bulkActionForm .row-check" value="1" />
                                    </div>
                                </th>
                                <th>Invoice #</th>
                                <th>Tenant</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Period</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-bold">
                            @foreach($invoices as $invoice)
                                <tr>
                                    <td>
                                        <div class="form-check form-check-sm form-check-custom form-check-solid">
                                            <input class="form-check-input row-check" type="checkbox" name="ids[]" value="{{ $invoice->id }}" {{ $invoice->status !== 'draft' ? 'disabled' : '' }} />
                                        </div>
                                    </td>
                                    <td><span class="text-gray-800 fw-bolder">{{ $invoice->number }}</span></td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <a href="{{ route('tenants.edit', $invoice->tenant->uuid) }}" class="text-gray-800 text-hover-primary mb-1">{{ $invoice->tenant->name }}</a>
                                            <span class="fs-7 text-muted">{{ $invoice->tenant->email }}</span>
                                        </div>
                                    </td>
                                    <td>${{ number_format((float)$invoice->total, 2) }}</td>
                                    <td>
                                        <span class="badge badge-light-{{ $invoice->status === 'paid' ? 'success' : ($invoice->status === 'draft' ? 'warning' : 'primary') }}">
                                            {{ strtoupper($invoice->status) }}
                                        </span>
                                    </td>
                                    <td>{{ $invoice->period_start->format('M Y') }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.billing.invoices.show', $invoice->id) }}" class="btn btn-sm btn-light btn-active-light-primary">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </form>
                <div class="mt-5">
                    {{ $invoices->links() }}
                </div>
            </div>

@push('scripts')
<script>
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('row-check') || e.target.getAttribute('data-kt-check') === 'true') {
            const checked = document.querySelectorAll('.row-check:checked').length;
            const container = document.getElementById('bulkActions');
            if (checked > 0) {
                container.classList.remove('invisible');
            } else {
                container.classList.add('invisible');
            }
        }
    });

    // Simple multi-check helper
    const mainCheck = document.querySelector('[data-kt-check="true"]');
    if (mainCheck) {
        mainCheck.addEventListener('change', function() {
            const targets = document.querySelectorAll(this.dataset.ktCheckTarget);
            targets.forEach(t => {
                if (!t.disabled) t.checked = this.checked;
            });
        });
    }
    // Bulk issue confirmation
    document.getElementById('bulkIssueBtn')?.addEventListener('click', function() {
        const count = document.querySelectorAll('.row-check:checked').length;
        Swal.fire({
            text: `Are you sure you want to issue ${count} selected invoices? They will become visible to the tenants immediately.`,
            icon: "warning",
            showCancelButton: true,
            buttonsStyling: false,
            confirmButtonText: "Yes, issue them!",
            cancelButtonText: "No, cancel",
            customClass: {
                confirmButton: "btn fw-bold btn-primary",
                cancelButton: "btn fw-bold btn-active-light-primary"
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                document.getElementById('bulkActionForm').submit();
            }
        });
    });
</script>
@endpush
        </div>
    </div>
</div>
@endsection
