@extends('layouts.admin.master')

@section('title', __('locale.menu.dashboard'))

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <!--begin::Container-->
        <div id="kt_content_container" class="container-xxl">
            @if(session('onboarding_just_completed'))
                <!--begin::Welcome Banner-->
                <div
                    class="alert alert-dismissible bg-light-primary border border-primary border-dashed d-flex flex-column flex-sm-row p-5 mb-10">
                    <!--begin::Icon-->
                    <span class="svg-icon svg-icon-2hx svg-icon-primary me-4 mb-5 mb-sm-0">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <path opacity="0.3"
                                d="M12 22C13.6569 22 15 20.6569 15 19C15 17.3431 13.6569 16 12 16C10.3431 16 9 17.3431 9 19C9 20.6569 10.3431 22 12 22Z"
                                fill="currentColor"></path>
                            <path
                                d="M19 15V18C19 18.6 18.6 19 18 19H6C5.4 19 5 18.6 5 18V15C5 14.4 5.4 14 6 14H18C18.6 14 19 14.4 19 15ZM12 12C14.2091 12 16 10.2091 16 8C16 5.79086 14.2091 4 12 4C9.79086 4 8 5.79086 8 8C8 10.2091 9.79086 12 12 12Z"
                                fill="currentColor"></path>
                        </svg>
                    </span>
                    <!--end::Icon-->

                    <!--begin::Wrapper-->
                    <div class="d-flex flex-column pe-0 pe-sm-10">
                        <h5 class="mb-1">Welcome to your new organization!</h5>
                        <span>You've successfully completed the onboarding. Here's your dashboard where you can manage users,
                            track analytics, and more.</span>
                    </div>
                    <!--end::Wrapper-->

                    <!--begin::Close-->
                    <button type="button"
                        class="position-absolute position-sm-relative m-2 m-sm-0 top-0 end-0 btn btn-icon ms-sm-auto"
                        data-bs-dismiss="alert">
                        <i class="bi bi-x fs-1 text-primary"></i>
                    </button>
                    <!--end::Close-->
                </div>
                <!--end::Welcome Banner-->
            @endif

            @php
                $currentTenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();
            @endphp

            @if($currentTenant && !$currentTenant->onboarding_completed_at && !session('onboarding_just_completed'))
                <!--begin::Incomplete Onboarding Banner-->
                <div
                    class="alert alert-dismissible bg-light-warning border border-warning border-dashed d-flex flex-column flex-sm-row p-5 mb-10">
                    <!--begin::Icon-->
                    <span class="svg-icon svg-icon-2hx svg-icon-warning me-4 mb-5 mb-sm-0">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <rect opacity="0.3" x="2" y="2" width="20" height="20" rx="10" fill="currentColor"></rect>
                            <rect x="11" y="14" width="7" height="2" rx="1" transform="rotate(-90 11 14)" fill="currentColor">
                            </rect>
                            <rect x="11" y="17" width="2" height="2" rx="1" transform="rotate(-90 11 17)" fill="currentColor">
                            </rect>
                        </svg>
                    </span>
                    <!--end::Icon-->

                    <!--begin::Wrapper-->
                    <div class="d-flex flex-column pe-0 pe-sm-10">
                        <h5 class="mb-1 text-warning">Finish Setting Up Your Organization</h5>
                        <span>You have some pending steps in the setup wizard.
                            <a href="{{ request()->route('subdomain') ? route('tenant.onboarding.wizard') : route('onboarding.wizard') }}"
                                class="fw-bolder text-warning text-decoration-underline">Click here to complete it now.</a>
                        </span>
                    </div>
                    <!--end::Wrapper-->

                    <!--begin::Close-->
                    <button type="button"
                        class="position-absolute position-sm-relative m-2 m-sm-0 top-0 end-0 btn btn-icon ms-sm-auto"
                        data-bs-dismiss="alert">
                        <i class="bi bi-x fs-1 text-warning"></i>
                    </button>
                    <!--end::Close-->
                </div>
                <!--end::Incomplete Onboarding Banner-->
            @endif

            <div class="row g-5 g-xl-8">
                @can('user analytics')
                    <div class="col-xl-3">
                        <a href="#" class="card bg-body hoverable card-xl-stretch mb-xl-8">
                            <div class="card-body">
                                <span class="svg-icon svg-icon-primary svg-icon-3x ms-n1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none">
                                        <path
                                            d="M16.0173 9H15.3945C14.2833 9 13.263 9.61425 12.7431 10.5963L12.154 11.7091C12.0645 11.8781 12.1072 12.0868 12.2559 12.2071L12.6402 12.5183C13.2631 13.0225 13.7556 13.6691 14.0764 14.4035L14.2321 14.7601C14.2957 14.9058 14.4396 15 14.5987 15H18.6747C19.7297 15 20.4057 13.8774 19.912 12.945L18.6686 10.5963C18.1487 9.61425 17.1285 9 16.0173 9Z"
                                            fill="currentColor" />
                                        <rect opacity="0.3" x="14" y="4" width="4" height="4" rx="2" fill="currentColor" />
                                        <path
                                            d="M4.65486 14.8559C5.40389 13.1224 7.11161 12 9 12C10.8884 12 12.5961 13.1224 13.3451 14.8559L14.793 18.2067C15.3636 19.5271 14.3955 21 12.9571 21H5.04292C3.60453 21 2.63644 19.5271 3.20698 18.2067L4.65486 14.8559Z"
                                            fill="currentColor" />
                                        <rect opacity="0.3" x="6" y="5" width="6" height="6" rx="3" fill="currentColor" />
                                    </svg>
                                </span>
                                <!--end::Svg Icon-->
                                <div class="text-gray-900 fw-bolder fs-2 mb-2 mt-5" data-bs-toggle="tooltip"
                                    data-bs-placement="top" title="Monitor your organization's growth here.">
                                    {{ \App\Models\User::count() }}
                                </div>
                                <div class="fw-bold text-gray-400">{{ __('locale.menu.users') }}</div>
                            </div>
                            <!--end::Body-->
                        </a>
                        <!--end::Statistics Widget 5-->
                    </div>
                @endcan

                @can('read audit-trail')
                    <div class="col-xl-3">
                        <a href="#" class="card bg-dark hoverable card-xl-stretch mb-xl-8">
                            <div class="card-body">
                                <span class="svg-icon svg-icon-gray-100 svg-icon-3x ms-n1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none">
                                        <path opacity="0.3"
                                            d="M18 21.6C16.3 21.6 15 20.3 15 18.6V2.50001C15 2.20001 14.6 1.99996 14.3 2.19996L13 3.59999L11.7 2.3C11.3 1.9 10.7 1.9 10.3 2.3L9 3.59999L7.70001 2.3C7.30001 1.9 6.69999 1.9 6.29999 2.3L5 3.59999L3.70001 2.3C3.50001 2.1 3 2.20001 3 3.50001V18.6C3 20.3 4.3 21.6 6 21.6H18Z"
                                            fill="currentColor"></path>
                                        <path
                                            d="M12 12.6H11C10.4 12.6 10 12.2 10 11.6C10 11 10.4 10.6 11 10.6H12C12.6 10.6 13 11 13 11.6C13 12.2 12.6 12.6 12 12.6ZM9 11.6C9 11 8.6 10.6 8 10.6H6C5.4 10.6 5 11 5 11.6C5 12.2 5.4 12.6 6 12.6H8C8.6 12.6 9 12.2 9 11.6ZM9 7.59998C9 6.99998 8.6 6.59998 8 6.59998H6C5.4 6.59998 5 6.99998 5 7.59998C5 8.19998 5.4 8.59998 6 8.59998H8C8.6 8.59998 9 8.19998 9 7.59998ZM13 7.59998C13 6.99998 12.6 6.59998 12 6.59998H11C10.4 6.59998 10 6.99998 10 7.59998C10 8.19998 10.4 8.59998 11 8.59998H12C12.6 8.59998 13 8.19998 13 7.59998ZM13 15.6C13 15 12.6 14.6 12 14.6H10C9.4 14.6 9 15 9 15.6C9 16.2 9.4 16.6 10 16.6H12C12.6 16.6 13 16.2 13 15.6Z"
                                            fill="currentColor"></path>
                                        <path
                                            d="M15 18.6C15 20.3 16.3 21.6 18 21.6C19.7 21.6 21 20.3 21 18.6V12.5C21 12.2 20.6 12 20.3 12.2L19 13.6L17.7 12.3C17.3 11.9 16.7 11.9 16.3 12.3L15 13.6V18.6Z"
                                            fill="currentColor"></path>
                                    </svg>
                                </span>
                                <div class="text-gray-100 fw-bolder fs-2 mb-2 mt-5" data-bs-toggle="tooltip"
                                    data-bs-placement="top" title="Keep track of all system activities and changes.">
                                    +{{ \Spatie\Activitylog\Models\Activity::count() }}</div>
                                <div class="fw-bold text-gray-100">{{ __('locale.labels.activity_log') }}</div>
                            </div>
                        </a>
                    </div>
                @endcan

                @can('access-superadmin-dashboard')
                <div class="col-xl-3">
                    <a href="{{ route('admin.billing.subscriptions.index') }}" class="card bg-warning hoverable card-xl-stretch mb-xl-8">
                        <div class="card-body">
                            <span class="svg-icon svg-icon-white svg-icon-3x ms-n1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                    <path opacity="0.3" d="M20 15H4C2.9 15 2 14.1 2 13V7C2 6.4 2.4 6 3 6H21C21.6 6 22 6.4 22 7V13C22 14.1 21.1 15 20 15ZM13 12H11C10.5 12 10 12.4 10 13V16C10 16.5 10.4 17 11 17H13C13.6 17 14 16.6 14 16V13C14 12.4 13.6 12 13 12Z" fill="currentColor"></path>
                                    <path d="M14 6V5H10V6H8V5C8 3.9 8.9 3 10 3H14C15.1 3 16 3.9 16 5V6H14ZM20 15H14V16C14 16.6 13.5 17 13 17H11C10.5 17 10 16.6 10 16V15H4C3.6 15 3.3 14.9 3 14.7V18C3 19.1 3.9 20 5 20H19C20.1 20 21 19.1 21 18V14.7C20.7 14.9 20.4 15 20 15Z" fill="currentColor"></path>
                                </svg>
                            </span>
                            <div class="text-white fw-bolder fs-2 mb-2 mt-5">{{ $activeSubscriptions ?? 0 }}</div>
                            <div class="fw-bold text-white">Active Subscriptions</div>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3">
                    <a href="{{ route('admin.billing.transactions.index') }}" class="card bg-info hoverable card-xl-stretch mb-5 mb-xl-8">
                        <div class="card-body">
                            <span class="svg-icon svg-icon-white svg-icon-3x ms-n1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                    <path opacity="0.3" d="M10.9607 12.9128H18.8607C19.4607 12.9128 19.9607 13.4128 19.8607 14.0128C19.2607 19.0128 14.4607 22.7128 9.26068 21.7128C5.66068 21.0128 2.86071 18.2128 2.16071 14.6128C1.16071 9.31284 4.96069 4.61281 9.86069 4.01281C10.4607 3.91281 10.9607 4.41281 10.9607 5.01281V12.9128Z" fill="currentColor"></path>
                                    <path d="M12.9607 10.9128V3.01281C12.9607 2.41281 13.4607 1.91281 14.0607 2.01281C16.0607 2.21281 17.8607 3.11284 19.2607 4.61284C20.6607 6.01284 21.5607 7.91285 21.8607 9.81285C21.9607 10.4129 21.4607 10.9128 20.8607 10.9128H12.9607Z" fill="currentColor"></path>
                                </svg>
                            </span>
                            <div class="text-white fw-bolder fs-2 mb-2 mt-5">
                                ${{ number_format(($totalSuccessVolume ?? 0) / 100, 0) }}
                            </div>
                            <div class="fw-bold text-white">Total Revenue</div>
                        </div>
                    </a>
                </div>
                @endcan
            </div>

            <div class="row">
                @can('user analytics')
                        <div class="col-xl-8">
                            <!--begin::Users Chart-->
                            <div class="card card-flush shadow-sm" id="kt_users_trend_widget">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h3 class="card-title">{{ strtoupper(__('locale.labels.users_by_role')) }}</h3>
                                    <form action="{{ route('dashboard') }}" method="GET" class="d-flex align-items-center">
                                        <select name="days" class="form-select form-select-sm form-select-solid"
                                            onchange="this.form.submit()">
                                            <option value="7" {{ $days == 7 ? 'selected' : '' }}>Last 7 Days</option>
                                            <option value="30" {{ $days == 30 ? 'selected' : '' }}>Last 30 Days</option>
                                            <option value="90" {{ $days == 90 ? 'selected' : '' }}>Last 90 Days</option>
                                        </select>
                                    </form>
                                </div>
                                <div class="card-body py-5">
                                    <x-chart id="users-trend-chart" type="line" :data="[
                        'labels' => $userTrendData['labels'],
                        'datasets' => [
                            [
                                'label' => 'New Users (Last ' . $days . ' Days)',
                                'data' => $userTrendData['data'],
                                'borderColor' => '#009EF7',
                                'backgroundColor' => 'rgba(0, 158, 247, 0.2)',
                                'fill' => true,
                            ],
                        ],
                    ]" />
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <!--begin::Getting Started Checklist-->
                            <div class="card card-xl-stretch mb-xl-8" id="kt_getting_started_widget">
                                <div class="card-header border-0">
                                    <h3 class="card-title fw-bolder text-dark">GETTING STARTED</h3>
                                </div>
                                <div class="card-body pt-2">
                                    <!-- Item 1 -->
                                    <div class="d-flex align-items-center mb-7">
                                        <div class="symbol symbol-30px symbol-circle me-3">
                                            <span
                                                class="symbol-label {{ $checklist['onboarding'] ? 'bg-light-success' : 'bg-light-primary' }}">
                                                @if ($checklist['onboarding'])
                                                    <i class="fas fa-check text-success"></i>
                                                @else
                                                    <span class="text-primary fw-bolder">1</span>
                                                @endif
                                            </span>
                                        </div>
                                        <div class="flex-grow-1">
                                            <span
                                                class="text-dark fw-bolder fs-6 {{ $checklist['onboarding'] ? 'text-decoration-line-through text-muted' : '' }}">Complete
                                                Onboarding</span>
                                        </div>
                                    </div>

                                    <!-- Item 2 -->
                                    <div class="d-flex align-items-center mb-7">
                                        <div class="symbol symbol-30px symbol-circle me-3">
                                            <span
                                                class="symbol-label {{ $checklist['team'] ? 'bg-light-success' : 'bg-light-primary' }}">
                                                @if ($checklist['team'])
                                                    <i class="fas fa-check text-success"></i>
                                                @else
                                                    <span class="text-primary fw-bolder">2</span>
                                                @endif
                                            </span>
                                        </div>
                                        <div class="flex-grow-1">
                                            <a href="{{ route('users.index') }}"
                                                class="text-dark fw-bolder text-hover-primary fs-6 {{ $checklist['team'] ? 'text-decoration-line-through text-muted' : '' }}">Manage
                                                Your Team</a>
                                            <span class="text-muted d-block fw-bold">Add more colleagues.</span>
                                        </div>
                                    </div>

                                    <!-- Item 3 -->
                                    <div class="d-flex align-items-center mb-7">
                                        <div class="symbol symbol-30px symbol-circle me-3">
                                            <span
                                                class="symbol-label {{ $checklist['branding'] ? 'bg-light-success' : 'bg-light-primary' }}">
                                                @if ($checklist['branding'])
                                                    <i class="fas fa-check text-success"></i>
                                                @else
                                                    <span class="text-primary fw-bolder">3</span>
                                                @endif
                                            </span>
                                        </div>
                                        <div class="flex-grow-1">
                                            <a href="#"
                                                class="text-dark fw-bolder text-hover-primary fs-6 {{ $checklist['branding'] ? 'text-decoration-line-through text-muted' : '' }}">Refine
                                                Branding</a>
                                            <span class="text-muted d-block fw-bold">Logo & theme.</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!--end::Getting Started Checklist-->

                            <!--begin::Recent Users Widget-->
                            <div class="card card-xl-stretch mb-xl-8" id="kt_recent_users_widget">
                                <div class="card-header border-0">
                                    <h3 class="card-title fw-bolder text-dark">{{ strtoupper(__('locale.labels.recent_users')) }}
                                    </h3>
                                </div>
                                <div class="card-body pt-2">
                                    @foreach ($users as $user)
                                        <div class="d-flex align-items-center mb-7">
                                            <div class="symbol symbol-50px symbol-circle me-5">
                                                @if (!empty($user->photo))
                                                    <img src="{{ Storage::disk('local')->url($user->photo) }}" alt="user photo">
                                                @else
                                                    <img src="{{ $user->gravatar }}" alt="user photo">
                                                @endif
                                            </div>
                                            <div class="flex-grow-1">
                                                <a href="{{ route('users.show', $user->uuid) }}"
                                                    class="text-dark fw-bolder text-hover-primary fs-6">{{ $user->displayName() }}</a>
                                                <span class="text-muted d-block fw-bold">
                                                    {{ $user->roles()->first()?->name ?? 'N/A' }}
                                                </span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <!--end::Recent Users Widget-->
                        </div>
                @endcan
            </div>

            <!-- Expanded Analytics Row -->
            <div class="row g-5 g-xl-8 mt-5">
                <!-- Tenant Growth Trend -->
                <div class="col-xl-4">
                    <div class="card card-flush shadow-sm h-100" id="kt_tenant_growth_widget">
                        <div class="card-header">
                            <h3 class="card-title">Tenant Growth (Last {{ $days }} Days)</h3>
                        </div>
                        <div class="card-body">
                            <x-chart id="tenant-growth-chart" type="line" :data="[
            'labels' => $tenantGrowthTrend['labels'],
            'datasets' => [
                [
                    'label' => 'New Tenants',
                    'data' => $tenantGrowthTrend['data'],
                    'borderColor' => '#50CD89',
                    'backgroundColor' => 'rgba(80, 205, 137, 0.2)',
                    'fill' => true,
                ],
            ],
        ]" />
                        </div>
                    </div>
                </div>

                <!-- Tenant Status Distribution -->
                <div class="col-xl-4">
                    <div class="card card-flush shadow-sm h-100" id="kt_tenant_status_widget">
                        <div class="card-header">
                            <h3 class="card-title" data-bs-toggle="tooltip"
                                title="Current distribution of tenant statuses.">Tenant Status</h3>
                        </div>
                        <div class="card-body">
                            <x-chart id="tenant-status-chart" type="doughnut" :data="[
            'labels' => $tenantStatusDistribution['labels'],
            'datasets' => [
                [
                    'data' => $tenantStatusDistribution['data'],
                    'backgroundColor' => ['#009EF7', '#F1416C', '#FFC700', '#50CD89'], // Standard Theme Colors
                ],
            ],
        ]" />
                        </div>
                    </div>
                </div>

                <!-- Top Tenants by Users -->
                <div class="col-xl-4">
                    <div class="card card-flush shadow-sm h-100" id="kt_top_tenants_widget">
                        <div class="card-header">
                            <h3 class="card-title" data-bs-toggle="tooltip"
                                title="Top 5 tenants based on active user count.">Top 5 Tenants (by Users)</h3>
                        </div>
                        <div class="card-body">
                            <x-chart id="top-tenants-chart" type="bar" :data="[
            'labels' => $topTenantsByUsers['labels'],
            'datasets' => [
                [
                    'label' => 'User Count',
                    'data' => $topTenantsByUsers['data'],
                    'backgroundColor' => '#7239EA',
                ],
            ],
        ]" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Premium Dashboard Row: Activity & Resource Usage -->
            <div class="row g-5 g-xl-8 mt-5">
                <div class="col-xl-6">
                    <div class="card card-flush shadow-sm h-100" style="background: linear-gradient(135deg, #1e1e2d 0%, #2b2b40 100%);">
                        <div class="card-header pt-7">
                            <h3 class="card-title align-items-start flex-column">
                                <span class="card-label fw-bolder text-white">Login Activity</span>
                                <span class="text-gray-500 mt-1 fw-bold fs-7">Real-time session telemetry</span>
                            </h3>
                        </div>
                        <div class="card-body">
                            <x-chart id="login-activity-chart" type="line" :data="[
                                'labels' => $loginTrend['labels'],
                                'datasets' => [
                                    [
                                        'label' => 'Sessions',
                                        'data' => $loginTrend['data'],
                                        'borderColor' => '#009EF7',
                                        'backgroundColor' => 'rgba(0, 158, 247, 0.1)',
                                        'fill' => true,
                                        'tension' => 0.4,
                                        'pointRadius' => 4,
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
                                <span class="card-label fw-bolder text-dark">Resource Growth</span>
                                <span class="text-muted mt-1 fw-bold fs-7">Enterprise feature consumption</span>
                            </h3>
                        </div>
                        <div class="card-body">
                            <x-chart id="usage-growth-chart" type="bar" :data="[
                                'labels' => $usageGrowth['labels'],
                                'datasets' => [
                                    [
                                        'label' => 'Units Consumed',
                                        'data' => $usageGrowth['data'],
                                        'backgroundColor' => ['#50CD89', '#009EF7', '#F1416C', '#FFC700', '#7239EA', '#009EF7', '#50CD89'],
                                        'borderRadius' => 5,
                                    ],
                                ],
                            ]" />
                        </div>
                    </div>
                </div>
            </div>

            @hasrole('Superadmin')
            <div class="row g-5 g-xl-8 mt-5">
                <div class="col-xl-6">
                    <div class="card card-flush shadow-sm h-100">
                        <div class="card-header">
                            <h3 class="card-title">Provisioning Distribution (Isolation Modes)</h3>
                        </div>
                        <div class="card-body">
                            <x-chart id="isolation-mode-chart" type="pie" :data="[
                                'labels' => $isolationModeDistribution['labels'],
                                'datasets' => [
                                    [
                                        'data' => $isolationModeDistribution['data'],
                                        'backgroundColor' => ['#50CD89', '#009EF7', '#F1416C'],
                                    ],
                                ],
                            ]" />
                        </div>
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="card card-flush shadow-sm h-100">
                        <div class="card-header border-0 pt-5">
                            <h3 class="card-title align-items-start flex-column">
                                <span class="card-label fw-bolder text-dark">Infrastructure Overview</span>
                                <span class="text-muted mt-1 fw-bold fs-7">System-wide resource allocation</span>
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-stack mb-5">
                                <div class="d-flex align-items-center me-2">
                                    <div class="symbol symbol-50px me-3">
                                        <div class="symbol-label bg-light-success">
                                            <i class="fas fa-database text-success fs-2"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="#" class="fs-6 text-gray-800 text-hover-primary fw-bolder">Shared Pool</a>
                                        <div class="fs-7 text-muted fw-bold">Multi-tenant PostgreSQL</div>
                                    </div>
                                </div>
                                <div class="badge badge-light-success fw-bolder">Healthy</div>
                            </div>
                            <div class="d-flex flex-stack mb-5">
                                <div class="d-flex align-items-center me-2">
                                    <div class="symbol symbol-50px me-3">
                                        <div class="symbol-label bg-light-primary">
                                            <i class="fas fa-server text-primary fs-2"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="#" class="fs-6 text-gray-800 text-hover-primary fw-bolder">Dedicated Clusters</a>
                                        <div class="fs-7 text-muted fw-bold">Provisioned per-tenant instances</div>
                                    </div>
                                </div>
                                <div class="badge badge-light-primary fw-bolder">Active</div>
                            </div>
                             <div class="d-flex flex-stack">
                                <div class="d-flex align-items-center me-2">
                                    <div class="symbol symbol-50px me-3">
                                        <div class="symbol-label bg-light-warning">
                                            <i class="fas fa-cloud text-warning fs-2"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="#" class="fs-6 text-gray-800 text-hover-primary fw-bolder">S3 Storage</a>
                                        <div class="fs-7 text-muted fw-bold">Global Tenant Bucket</div>
                                    </div>
                                </div>
                                <div class="badge badge-light-warning fw-bolder">Checking</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endhasrole

            @hasrole('Superadmin')
            <div class="row mb-10 mt-10">
                <div class="col-md-6 mb-5">
                    <div class="card">
                        <div class="card-header border-0 pt-6">
                            <div class="card-titile">
                                <h3>{{ strtoupper(__('locale.labels.browsers')) }}</h3>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="browser-session" style="width: 100%;height:300px;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 mb-5">
                    <div class="card">
                        <div class="card-header border-0 pt-6">
                            <div class="card-titile">
                                <h3>{{ strtoupper(__('locale.labels.locations')) }}</h3>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="location-session" style="width: 100%;height:300px;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 mb-5">
                    <div class="card">
                        <div class="card-header border-0 pt-6">
                            <div class="card-titile">
                                <h3>{{ strtoupper(__('locale.labels.platform_or_os')) }}</h3>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="platform-session" style="width: 100%;height:300px;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 mb-5">
                    <div class="card">
                        <div class="card-header border-0 pt-6">
                            <div class="card-titile">
                                <h3>{{ strtoupper(__('locale.labels.devices')) }}</h3>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="client-device-session" style="width: 100%;height:300px;"></div>
                        </div>
                    </div>
                </div>
            </div>
            @endhasrole

        </div>
        <!--end::Container-->
    </div>
@endsection

@push('custom-scripts')
    @include('admin.audit-trail.partials.login-history-chartsjs')

    <!-- Guided Tour (Driver.js) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.0.1/dist/driver.css" />
    <script src="https://cdn.jsdelivr.net/npm/driver.js@1.0.1/dist/driver.js.iife.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            @if (session('onboarding_just_completed'))
                const driver = window.driver.js.driver;
                const driverObj = driver({
                    showProgress: true,
                    steps: [{
                        element: '#kt_getting_started_widget',
                        popover: {
                            title: 'Getting Started',
                            description: 'Follow these steps to finish setting up your organization.',
                            side: "left",
                            align: 'start'
                        }
                    },
                    {
                        element: '#kt_users_trend_widget',
                        popover: {
                            title: 'Monitor Growth',
                            description: 'Track how many new users are joining your organization over time.',
                            side: "bottom",
                            align: 'start'
                        }
                    },
                    {
                        element: '#kt_recent_users_widget',
                        popover: {
                            title: 'Recent Activity',
                            description: 'See who recently joined your team.',
                            side: "left",
                            align: 'start'
                        }
                    },
                    {
                        element: '#kt_tenant_growth_widget',
                        popover: {
                            title: 'Tenant Analytics',
                            description: 'View system-wide tenant growth and status statistics.',
                            side: "top",
                            align: 'start'
                        }
                    },
                    ]
                });

                setTimeout(() => {
                    driverObj.drive();
                }, 500);
            @endif
                    });
    </script>
@endpush