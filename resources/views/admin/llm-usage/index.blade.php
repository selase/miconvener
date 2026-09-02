@extends('layouts.admin.master')

@section('title', 'Global LLM Usage Dashboard')

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            <!-- Summary Cards -->
            <div class="row g-5 g-xl-10 mb-5 mb-xl-10">
                <div class="col-md-4">
                    <div class="card card-flush h-md-100">
                        <div class="card-header pt-5">
                            <div class="card-title d-flex flex-column">
                                <span
                                    class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ number_format($summary->total_tokens ?? 0) }}</span>
                                <span class="text-gray-500 pt-1 fw-semibold fs-6">Total Tokens (All Time)</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-flush h-md-100">
                        <div class="card-header pt-5">
                            <div class="card-title d-flex flex-column">
                                <span
                                    class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">${{ number_format($summary->total_cost ?? 0, 2) }}</span>
                                <span class="text-gray-500 pt-1 fw-semibold fs-6">Total Estimated Cost</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-flush h-md-100">
                        <div class="card-header pt-5">
                            <div class="card-title d-flex flex-column">
                                <span
                                    class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ number_format($summary->total_requests ?? 0) }}</span>
                                <span class="text-gray-500 pt-1 fw-semibold fs-6">Total Requests</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-5 g-xl-10">
                <!-- Top Tenants Table -->
                <div class="col-xl-8">
                    <div class="card card-flush h-xl-100">
                        <div class="card-header pt-7">
                            <h3 class="card-title align-items-start flex-column">
                                <span class="card-label fw-bold text-gray-800">Top Tenants by Usage (Last 30 Days)</span>
                            </h3>
                        </div>
                        <div class="card-body pt-2">
                            <div class="table-responsive">
                                <table class="table align-middle table-row-dashed fs-6 gy-3">
                                    <thead>
                                        <tr class="text-start text-gray-500 fw-bold fs-7 text-uppercase gs-0">
                                            <th>Tenant</th>
                                            <th>Subdomain</th>
                                            <th>Total Tokens</th>
                                            <th class="text-end">Estimated Cost</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-gray-600 fw-semibold">
                                        @forelse($topTenants as $usage)
                                            <tr>
                                                <td>{{ $usage->tenant_name }}</td>
                                                <td><code>{{ $usage->tenant_slug }}</code></td>
                                                <td>{{ number_format($usage->total_tokens) }}</td>
                                                <td class="text-end">${{ number_format($usage->total_cost, 4) }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="text-center">No data found.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Models Table -->
                <div class="col-xl-4">
                    <div class="card card-flush h-xl-100">
                        <div class="card-header pt-7">
                            <h3 class="card-title align-items-start flex-column">
                                <span class="card-label fw-bold text-gray-800">Model Distribution</span>
                            </h3>
                        </div>
                        <div class="card-body pt-2">
                            <div class="table-responsive">
                                <table class="table align-middle table-row-dashed fs-6 gy-3">
                                    <thead>
                                        <tr class="text-start text-gray-500 fw-bold fs-7 text-uppercase gs-0">
                                            <th>Model</th>
                                            <th class="text-end">Tokens</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-gray-600 fw-semibold">
                                        @foreach($topModels as $usage)
                                            <tr>
                                                <td><code>{{ $usage->model }}</code></td>
                                                <td class="text-end">{{ number_format($usage->total_tokens) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Daily Trend Placeholder (Chart logic can be added later) -->
            <div class="card card-flush mt-5">
                <div class="card-header pt-7">
                    <h3 class="card-title align-items-start flex-column">
                        <span class="card-label fw-bold text-gray-800">Daily Usage Trend (Last 30 Days)</span>
                    </h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed fs-6 gy-3">
                            <thead>
                                <tr class="text-start text-gray-500 fw-bold fs-7 text-uppercase gs-0">
                                    <th>Date</th>
                                    <th>Total Tokens</th>
                                    <th class="text-end">Estimated Cost</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600 fw-semibold">
                                @foreach($dailyTrend as $trend)
                                    <tr>
                                        <td>{{ is_string($trend->day) ? $trend->day : $trend->day->format('Y-m-d') }}</td>
                                        <td>{{ number_format((float) $trend->total_tokens) }}</td>
                                        <td class="text-end">${{ number_format((float) $trend->total_cost, 4) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection