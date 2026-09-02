@extends('layouts.admin.master')

@section('title', __('Finance & Sales'))

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        
        <!-- Statistics Summary & Chart -->
        <div class="row g-5 g-xl-10 mb-5 mb-xl-10">
            <div class="col-xl-4">
                <div class="row g-5 g-xl-10 h-100">
                    <div class="col-12 h-md-50 mb-5">
                        <div class="card card-flush h-100">
                            <div class="card-header pt-5">
                                <div class="card-title d-flex flex-column">
                                    <div class="d-flex align-items-center">
                                        <span class="fs-4 fw-bold text-gray-400 me-1">$</span>
                                        <span class="fs-2hx fw-bolder text-dark me-2 lh-1 ls-n2">{{ number_format($stats['total_volume'] / 100, 2) }}</span>
                                    </div>
                                    <span class="text-gray-400 pt-1 fw-bold fs-6">Gross Sales Volume</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 h-md-50">
                        <div class="card card-flush h-100">
                            <div class="card-header pt-5">
                                <div class="card-title d-flex flex-column">
                                    <span class="fs-2hx fw-bolder text-dark me-2 lh-1 ls-n2">{{ $stats['transaction_count'] }}</span>
                                    <span class="text-gray-400 pt-1 fw-bold fs-6">Successful Payments</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-8">
                <div class="card card-flush h-md-100">
                    <div class="card-header pt-5">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-bolder text-dark">Revenue Performance</span>
                            <span class="text-gray-400 mt-1 fw-bold fs-7">Last 6 months sales history</span>
                        </h3>
                    </div>
                    <div class="card-body pt-0 ps-4 pr-4">
                        <div id="kt_finance_chart" style="height: 200px"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-flush">
            <div class="card-header align-items-center py-5 gap-2 gap-md-5">
                <div class="card-title">
                    <form action="" method="GET" class="d-flex align-items-center position-relative my-1">
                        <span class="svg-icon svg-icon-1 position-absolute ms-4">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" name="search" value="{{ request('search') }}" class="form-control form-control-solid w-250px ps-14" placeholder="Search Transactions..." />
                    </form>
                </div>
                <div class="card-toolbar d-flex gap-3">
                    <div class="w-150px">
                        <select class="form-select form-select-solid" data-control="select2" data-hide-search="true" onchange="window.location.href = '?status=' + this.value">
                            <option value="">All Statuses</option>
                            <option value="succeeded" {{ request('status') === 'succeeded' ? 'selected' : '' }}>Succeeded</option>
                            <option value="refunded" {{ request('status') === 'refunded' ? 'selected' : '' }}>Refunded</option>
                            <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="card-body pt-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-5">
                        <thead>
                            <tr class="text-start text-gray-400 fw-bolder fs-7 text-uppercase gs-0">
                                <th class="min-w-150px">Transaction ID</th>
                                <th class="min-w-150px">Customer</th>
                                <th class="min-w-100px text-end">Amount</th>
                                <th class="min-w-100px text-center">Status</th>
                                <th class="min-w-100px text-center">Date</th>
                                <th class="text-end min-w-70px">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="fw-bold text-gray-600">
                            @forelse($transactions as $transaction)
                                <tr>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <span class="text-gray-800 fw-bolder mb-1">{{ $transaction->provider_transaction_id }}</span>
                                            <span class="fs-7 text-muted">{{ ucfirst($transaction->provider) }}・{{ ucfirst($transaction->type) }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <span class="text-gray-800 fw-bolder">{{ $transaction->customer_email ?: 'No Email' }}</span>
                                            <span class="fs-7 text-muted">{{ $transaction->customer_name }}</span>
                                        </div>
                                    </td>
                                    <td class="text-end pe-0">
                                        <span class="text-gray-800 fw-boldest fs-5">
                                            @if($transaction->type === 'refund') - @endif
                                            ${{ number_format($transaction->amount / 100, 2) }}
                                        </span>
                                        <span class="fs-7 text-muted d-block">{{ strtoupper($transaction->currency) }}</span>
                                    </td>
                                    <td class="text-center">
                                        @php
                                            $badgeClass = match($transaction->status) {
                                                'succeeded', 'paid' => 'success',
                                                'refunded' => 'primary',
                                                'pending' => 'warning',
                                                default => 'danger',
                                            };
                                        @endphp
                                        <span class="badge badge-light-{{ $badgeClass }} fs-7 fw-bolder">{{ strtoupper($transaction->status) }}</span>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-bolder">{{ $transaction->created_at->format('M d, Y') }}</span>
                                        <span class="fs-7 text-muted d-block">{{ $transaction->created_at->format('H:i') }}</span>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-light btn-active-light-primary" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                            Actions <i class="fas fa-chevron-down fs-9 ms-1"></i>
                                        </button>
                                        <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-bold fs-7 w-125px py-4" data-kt-menu="true">
                                            <div class="menu-item px-3">
                                                <a href="#" class="menu-link px-3" onclick="alert('Metadata: ' + JSON.stringify({{ json_encode($transaction->meta) }}))">Details</a>
                                            </div>
                                            @if($transaction->status === 'succeeded' && $transaction->type === 'payment')
                                                <div class="menu-item px-3">
                                                    <form action="{{ route('tenant.finance.refund', $transaction->id) }}" method="POST" onsubmit="return confirm('Are you sure you want to refund this transaction?')">
                                                        @csrf
                                                        <button type="submit" class="menu-link px-3 border-0 bg-transparent text-danger w-100 text-start">Issue Refund</button>
                                                    </form>
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-10">
                                        <span class="text-muted">No transactions found for this period.</span>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-5">
                    {{ $transactions->links() }}
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
            name: 'Gross Revenue',
            data: @json(collect($monthlyStats)->pluck('amount'))
        }],
        chart: {
            fontFamily: 'inherit',
            type: 'bar',
            height: 200,
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
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            labels: {
                formatter: function (val) { return "$" + val.toFixed(0); }
            }
        },
        tooltip: {
            y: {
                formatter: function (val) { return "$" + val.toFixed(2); }
            }
        }
    };

    var chart = new ApexCharts(document.querySelector("#kt_finance_chart"), options);
    chart.render();
</script>
@endpush
@endsection
