@extends('layouts.admin.master')

@section('title', 'Invoice ' . $invoice->number)

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        
        <div class="card shadow-sm">
            <div class="card-body p-lg-20">
                <!-- Invoice Header -->
                <div class="d-flex flex-column flex-sm-row gap-7 gap-md-10 justify-content-between">
                    <div class="flex-root d-flex flex-column">
                        <div class="mb-5">
                            <h1 class="fw-boldest text-gray-800 fs-2hx">INVOICE</h1>
                        </div>
                        <div class="fw-bold fs-4 text-muted">#{{ $invoice->number }}</div>
                    </div>
                    
                    <div class="flex-root d-flex flex-column text-sm-end">
                        <x-application-logo class="h-45px mb-5 mw-150px" />
                        <div class="fw-bold fs-6 text-gray-600">
                            {{ config('app.name') }} HQ<br />
                            123 Main Street<br />
                            Accra, Ghana
                        </div>
                    </div>
                </div>

                <!-- Billing Info -->
                <div class="row g-5 mb-10 mt-5">
                    <div class="col-sm-6">
                        <div class="fw-bold fs-7 text-muted text-uppercase mb-1">BILL TO</div>
                        <div class="fw-boldest fs-4 text-gray-800 text-hover-primary mb-1">{{ $invoice->tenant->name }}</div>
                        <div class="fw-bold fs-7 text-gray-600">
                            {{ $invoice->tenant->email }}<br />
                            Ghana
                        </div>
                    </div>
                    <div class="col-sm-6 text-sm-end">
                        <div class="fw-bold fs-7 text-muted text-uppercase mb-1">DATES</div>
                        <div class="fw-bold fs-6 text-gray-800">
                            Issued: {{ $invoice->created_at->format('M d, Y') }}<br />
                            Due: {{ $invoice->due_at->format('M d, Y') }}
                        </div>
                    </div>
                </div>

                <!-- Line Items -->
                <div class="table-responsive border-bottom mb-10">
                    <table class="table align-middle table-row-dashed fs-6 gy-5 gs-0">
                        <thead>
                            <tr class="fw-boldest fs-7 text-muted text-uppercase">
                                <th class="min-w-175px pb-2">Description</th>
                                <th class="min-w-70px text-end pb-2">Qty</th>
                                <th class="min-w-100px text-end pb-2">Rate</th>
                                <th class="min-w-100px text-end pb-2">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="fw-bold text-gray-600">
                            @foreach($invoice->items as $item)
                                <tr>
                                    <td class="d-flex align-items-center">
                                        <div class="d-flex flex-column">
                                            <span class="fw-boldest text-gray-800 fs-6">{{ $item->description }}</span>
                                            @if($item->metric)
                                                <span class="fs-7 text-muted">Metered Usage: {{ $item->metric->name }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-end">{{ number_format((float)$item->quantity, 2) }}</td>
                                    <td class="text-end">${{ number_format((float)$item->unit_price, 4) }}</td>
                                    <td class="text-end text-dark fw-boldest">${{ number_format((float)$item->subtotal, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Totals -->
                <div class="d-flex justify-content-end mb-10">
                    <div class="mw-300px w-100">
                        <div class="d-flex flex-stack mb-3">
                            <div class="fw-bold fs-6 text-muted">Subtotal</div>
                            <div class="fw-boldest fs-6 text-gray-800">${{ number_format((float)$invoice->subtotal, 2) }}</div>
                        </div>
                        @if($invoice->tax_details)
                            @foreach($invoice->tax_details as $tax)
                                <div class="d-flex flex-stack mb-3">
                                    <div class="fw-bold fs-6 text-muted">{{ $tax['name'] }} ({{ $tax['rate'] }}%)</div>
                                    <div class="fw-boldest fs-6 text-gray-800">${{ number_format((float)$tax['amount'], 2) }}</div>
                                </div>
                            @endforeach
                        @endif
                        <div class="d-flex flex-stack mb-3 border-top pt-3">
                            <div class="fw-boldest fs-3 text-gray-800">Total</div>
                            <div class="fw-boldest fs-3 text-dark">${{ number_format((float)$invoice->total, 2) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Action Footer -->
                <div class="d-flex flex-stack flex-wrap gap-2 pt-10 border-top">
                    <div class="d-flex gap-3">
                        <button type="button" class="btn btn-light-primary fw-boldest" onclick="window.print();">Print Invoice</button>
                        <a href="{{ route('billing.invoices.download', $invoice->id) }}" class="btn btn-light fw-boldest">Download PDF</a>
                    </div>
                    
                    @if($invoice->status !== 'paid')
                        <form action="{{ route('billing.checkout') }}" method="POST">
                            @csrf
                            <input type="hidden" name="invoice_id" value="{{ $invoice->id }}">
                            <button type="submit" class="btn btn-primary fw-boldest">Pay Invoice Now</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
