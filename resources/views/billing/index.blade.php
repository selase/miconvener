@extends('layouts.admin.master')

@section('title', 'Billing & Subscription')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        
        <div class="row g-5 g-xl-10 mb-5 mb-xl-10">
            <!-- Subscription Summary -->
            <div class="col-md-6 col-lg-6 col-xl-6 col-xxl-3">
                <div class="card card-flush h-md-50 mb-5 mb-xl-10">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <div class="d-flex align-items-center">
                                <span class="fs-4 fw-bold text-gray-400 me-1">$</span>
                                <span class="fs-2hx fw-bolder text-dark me-2 lh-1 ls-n2">{{ number_format((float)$tenant->package?->price ?? 0, 2) }}</span>
                                <span class="badge badge-light-success fs-base">
                                    {{ ucfirst($tenant->package?->interval ?? 'Free') }}
                                </span>
                            </div>
                            <span class="text-gray-400 pt-1 fw-bold fs-6">Base Plan: {{ $tenant->package?->name ?? 'None' }}</span>
                        </div>
                    </div>
                    <div class="card-body d-flex flex-column justify-content-end pe-0">
                        <span class="fs-6 fw-bolder text-gray-800 d-block mb-2">Next Invoice: {{ $subscription?->current_period_end?->format('M d, Y') ?? 'N/A' }}</span>
                        <div class="d-flex flex-stack flex-wrap">
                            <div class="symbol-group symbol-hover flex-nowrap">
                                <span class="badge badge-light-primary fw-bold px-4 py-2">
                                    {{ ucfirst($subscription?->provider_status ?? $tenant->status->value ?? $tenant->status) }}
                                </span>
                            </div>
                            <a href="{{ route('tenant.pricing') }}" class="btn btn-sm btn-primary">Upgrade Plan</a>
                        </div>
                    </div>
                </div>

                <!-- Projected Spend Card (Metered) -->
                <div class="card card-flush h-md-50 mb-5 mb-xl-10">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bolder text-dark me-2 lh-1 ls-n2">Projected</span>
                            <span class="text-gray-400 pt-1 fw-bold fs-6">Accrued Metered Charges</span>
                        </div>
                    </div>
                    <div class="card-body d-flex align-items-end pt-0">
                        @php
                            $basePrice = (float)($tenant->package?->price ?? 0);
                            $meteredAmt = (float)($accruedMetered ?? 0);
                            $progressPct = $basePrice > 0 ? min((int)round(($meteredAmt / $basePrice) * 100), 100) : 0;
                            $barColor = $progressPct >= 90 ? 'danger' : ($progressPct >= 70 ? 'warning' : 'primary');
                        @endphp
                        <div class="d-flex align-items-center flex-column mt-3 w-100">
                            <div class="d-flex justify-content-between w-100 mt-auto mb-2">
                                <span class="fw-boldest fs-6 text-dark">${{ number_format($meteredAmt, 2) }}</span>
                                <span class="fw-bolder fs-6 text-gray-400">{{ $progressPct }}% of plan</span>
                            </div>
                            <div class="h-8px mx-3 w-100 bg-light-{{ $barColor }} rounded">
                                <div class="bg-{{ $barColor }} rounded h-8px" role="progressbar"
                                    style="width: {{ $progressPct }}%"
                                    aria-valuenow="{{ $progressPct }}" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Spending Analytics Chart -->
            <div class="col-md-6 col-lg-6 col-xl-6 col-xxl-9">
                <div class="card card-flush h-md-100">
                    <div class="card-header pt-5">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-bolder text-dark">Spending Over Time</span>
                            <span class="text-muted mt-1 fw-bold fs-7">Last 6 months revenue contribution</span>
                        </h3>
                        <div class="card-toolbar">
                             <a href="{{ route('tenant.settings.billing') }}" class="btn btn-sm btn-light">Billing Settings</a>
                        </div>
                    </div>
                    <div class="card-body pt-0 ps-4 pr-4">
                         <div id="kt_billing_chart" style="height: 300px"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoices & Transactions -->
        <div class="row g-5 g-xl-10">
            <div class="col-xl-8">
                <div class="card card-flush shadow-sm">
                    <div class="card-header pt-7">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-bolder text-dark">Invoice History</span>
                            <span class="text-muted mt-1 fw-bold fs-7">All officially issued invoices</span>
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                                <thead>
                                    <tr class="fw-bolder text-muted">
                                        <th class="min-w-150px">Invoice Number</th>
                                        <th class="min-w-100px">Period</th>
                                        <th class="min-w-100px">Amount</th>
                                        <th class="min-w-100px">Status</th>
                                        <th class="min-w-100px text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($invoices as $invoice)
                                        <tr>
                                            <td><span class="fw-bolder text-gray-800">{{ $invoice->number }}</span></td>
                                            <td>{{ $invoice->period_start->format('M Y') }}</td>
                                            <td>${{ number_format((float)$invoice->total, 2) }}</td>
                                            <td>
                                                <span class="badge badge-light-{{ $invoice->status === 'paid' ? 'success' : 'primary' }}">
                                                    {{ strtoupper($invoice->status) }}
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <a href="{{ route('billing.invoices.show', $invoice->id) }}" class="btn btn-sm btn-light-primary">View</a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">No invoices issued yet.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card card-flush shadow-sm">
                    <div class="card-header pt-7">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-bolder text-dark">Payment History</span>
                        </h3>
                    </div>
                    <div class="card-body">
                        @forelse($transactions as $transaction)
                            <div class="d-flex flex-stack mb-5">
                                <div class="d-flex align-items-center me-3">
                                    <div class="symbol symbol-35px me-3">
                                        <span class="symbol-label bg-light-{{ $transaction->status === 'success' ? 'success' : 'warning' }}">
                                            <i class="fas fa-receipt text-{{ $transaction->status === 'success' ? 'success' : 'warning' }}"></i>
                                        </span>
                                    </div>
                                    <div class="d-flex flex-column">
                                        <span class="text-gray-800 fw-bolder fs-6">{{ $transaction->provider_transaction_id ?: 'Payment' }}</span>
                                        <span class="text-muted fs-7">{{ $transaction->created_at->format('M d, Y') }}</span>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <span class="text-gray-800 fw-boldest d-block">${{ number_format($transaction->amount / 100, 2) }}</span>
                                    <div class="d-flex align-items-center justify-content-end gap-2">
                                        <span class="badge badge-light-{{ $transaction->status === 'success' ? 'success' : 'warning' }} fs-8">{{ strtoupper($transaction->status) }}</span>
                                        @if($transaction->status === 'success' || $transaction->status === 'succeeded')
                                            <form action="{{ route('billing.refund', ['transaction' => $transaction->id, 'subdomain' => $tenant->slug]) }}" method="POST" onsubmit="return confirm('Issue refund?')">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-light-danger py-1 px-2 fs-9" title="Refund">
                                                    Refund
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="text-center text-muted py-10">No payments found.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
    var options = {
        series: [{
            name: 'Spending',
            data: @json(collect($monthlyStats)->pluck('amount')->map(fn($v) => $v / 100))
        }],
        chart: {
            fontFamily: 'inherit',
            type: 'bar',
            height: 300,
            toolbar: { show: false }
        },
        plotOptions: {
            bar: {
                borderRadius: 4,
                columnWidth: '50%',
            }
        },
        colors: ['#009EF7'],
        xaxis: {
            categories: @json(collect($monthlyStats)->pluck('label')),
        }
    };

    var chart = new ApexCharts(document.querySelector("#kt_billing_chart"), options);
    chart.render();
</script>
@endpush
@endsection