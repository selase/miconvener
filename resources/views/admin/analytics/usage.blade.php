@extends('layouts.admin.master')

@section('title', 'System Usage Analytics')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        
        <!-- Header Controls -->
        <div class="d-flex flex-stack mb-5">
            <h1 class="d-flex align-items-center text-dark fw-bolder fs-3 my-1">Usage Analytics</h1>
            <div class="d-flex align-items-center gap-2">
                <form action="{{ route('admin.billing.analytics.usage') }}" method="GET" class="d-flex align-items-center gap-3">
                    <div class="w-200px">
                        <select name="tenant_id" class="form-select form-select-sm form-select-solid" data-control="select2" data-placeholder="Filter by Tenant" onchange="this.form.submit()">
                            <option value="" {{ !$selectedTenantId ? 'selected' : '' }}>Global / All System</option>
                            @foreach($tenants as $tenant)
                                <option value="{{ $tenant->id }}" {{ $selectedTenantId == $tenant->id ? 'selected' : '' }}>{{ $tenant->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <input type="date" name="start_date" class="form-control form-control-sm form-control-solid" value="{{ $start_date }}" onchange="this.form.submit()">
                        <span class="text-gray-500">to</span>
                        <input type="date" name="end_date" class="form-control form-control-sm form-control-solid" value="{{ $end_date }}" onchange="this.form.submit()">
                    </div>
                    <select name="days" class="form-select form-select-sm form-select-solid w-125px" onchange="this.form.submit()">
                        <option value="1" {{ $days == 1 ? 'selected' : '' }}>Last 24 Hours</option>
                        <option value="7" {{ $days == 7 ? 'selected' : '' }}>Last 7 Days</option>
                        <option value="30" {{ $days == 30 ? 'selected' : '' }}>Last 30 Days</option>
                        <option value="90" {{ $days == 90 ? 'selected' : '' }}>Last 90 Days</option>
                    </select>
                </form>
            </div>
        </div>

        <div class="row g-5 g-xl-10">
            <!-- Global Throughput -->
            <div class="col-xl-8">
                <div class="card card-flush shadow-sm h-100">
                    <div class="card-header pt-7">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-bolder text-dark">Request Throughput</span>
                            <span class="text-muted mt-1 fw-bold fs-7">Global system load over time</span>
                        </h3>
                    </div>
                    <div class="card-body">
                        <x-chart id="throughput-chart" type="line" :data="[
                            'labels' => $requestTrend['labels'],
                            'datasets' => [
                                [
                                    'label' => 'Requests',
                                    'data' => $requestTrend['data'],
                                    'borderColor' => '#009EF7',
                                    'backgroundColor' => 'rgba(0, 158, 247, 0.1)',
                                    'fill' => true,
                                    'tension' => 0.4,
                                    'pointRadius' => 0,
                                ],
                            ],
                        ]" />
                    </div>
                </div>
            </div>

            <!-- Error Distribution -->
            <div class="col-xl-4">
                <div class="card card-flush shadow-sm h-100">
                    <div class="card-header pt-7">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-bolder text-dark">Traffic Health</span>
                            <span class="text-muted mt-1 fw-bold fs-7">Status code distribution</span>
                        </h3>
                    </div>
                    <div class="card-body d-flex flex-column justify-content-center">
                        <x-chart id="health-chart" type="doughnut" :data="[
                            'labels' => $statusBreakdown['labels'],
                            'datasets' => [
                                [
                                    'data' => $statusBreakdown['data'],
                                    'backgroundColor' => ['#50CD89', '#F1416C', '#FFC700', '#009EF7'],
                                ],
                            ],
                        ]" />
                        <div class="mt-8">
                            @foreach($statusBreakdown['labels'] as $index => $label)
                                <div class="d-flex flex-stack mb-3">
                                    <div class="d-flex align-items-center me-2">
                                        <div class="symbol symbol-10px me-3">
                                            <span class="symbol-label" style="background-color: {{ ['#50CD89', '#F1416C', '#FFC700', '#009EF7'][$index % 4] }}"></span>
                                        </div>
                                        <span class="text-gray-800 fw-bold">{{ $label }}</span>
                                    </div>
                                    <span class="text-gray-600 fw-bolder">{{ number_format($statusBreakdown['data'][$index]) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-5 g-xl-10 mt-5">
            <!-- Peak Hours Heatmap -->
            <div class="col-xl-12">
                <div class="card card-flush shadow-sm">
                    <div class="card-header pt-7">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-bolder text-dark">Engine Load Profile</span>
                            <span class="text-muted mt-1 fw-bold fs-7">Aggregated hourly request volume (Peak Hours)</span>
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 justify-content-between">
                            @foreach($peakHours as $hour => $val)
                                @php
                                    $max = max($peakHours) ?: 1;
                                    $opacity = 0.1 + (($val / $max) * 0.9);
                                    $color = $val > ($max * 0.8) ? '#F1416C' : '#009EF7';
                                @endphp
                                <div class="d-flex flex-column align-items-center flex-grow-1 min-w-50px p-3 rounded border border-dashed border-gray-300" 
                                     style="background-color: {{ $color }}{{ dechex(round($opacity * 255)) }};">
                                    <span class="fs-8 fw-bold text-gray-500 mb-1">{{ sprintf('%02d:00', $hour) }}</span>
                                    <span class="fs-5 fw-bolder text-gray-800">{{ number_format($val) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-5 g-xl-10 mt-5">
            <!-- Infrastructure Resources -->
            <div class="col-xl-6">
                <div class="card card-flush shadow-sm h-100">
                    <div class="card-header pt-7">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-bolder text-dark">Cloud Storage</span>
                            <span class="text-muted mt-1 fw-bold fs-7">S3/Object storage consumption (GB)</span>
                        </h3>
                    </div>
                    <div class="card-body">
                        <x-chart id="storage-resource-chart" type="line" :data="[
                            'labels' => $storageTrend['labels'],
                            'datasets' => [
                                [
                                    'label' => 'Total GB',
                                    'data' => $storageTrend['data'],
                                    'borderColor' => '#7239EA',
                                    'backgroundColor' => 'rgba(114, 57, 234, 0.1)',
                                    'fill' => true,
                                ],
                            ],
                        ]" />
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card card-flush shadow-sm h-100">
                    <div class="card-header pt-7">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-bolder text-dark">Database Footprint</span>
                            <span class="text-muted mt-1 fw-bold fs-7">Aggregate DB size across tenants (MB)</span>
                        </h3>
                    </div>
                    <div class="card-body">
                        <x-chart id="db-resource-chart" type="line" :data="[
                            'labels' => $dbTrend['labels'],
                            'datasets' => [
                                [
                                    'label' => 'Total MB',
                                    'data' => $dbTrend['data'],
                                    'borderColor' => '#FFC700',
                                    'backgroundColor' => 'rgba(255, 199, 0, 0.1)',
                                    'fill' => true,
                                ],
                            ],
                        ]" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Metric Details Table -->
        <div class="card card-flush shadow-sm mt-10">
            <div class="card-header pt-7">
                <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bolder text-dark">Metric Catalog</span>
                    <span class="text-muted mt-1 fw-bold fs-7">Definitions of active usage signals</span>
                </h3>
            </div>
            <div class="card-body pt-0">
                <table class="table align-middle table-row-dashed fs-6 gy-5">
                    <thead>
                        <tr class="text-start text-gray-400 fw-bolder fs-7 text-uppercase gs-0">
                            <th>Metric Name</th>
                            <th>Category</th>
                            <th>Unit</th>
                            <th>Attribution</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-bold">
                        @foreach(App\Enum\UsageMetric::cases() as $metric)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="symbol symbol-30px me-3">
                                            <div class="symbol-label bg-light-primary">
                                                <i class="fas fa-chart-line text-primary"></i>
                                            </div>
                                        </div>
                                        {{ $metric->name }}
                                    </div>
                                </td>
                                <td><span class="badge badge-light-info">{{ explode('.', $metric->value)[0] }}</span></td>
                                <td><code>{{ $metric->unit() }}</code></td>
                                <td>Tenant scoped</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
@endsection
