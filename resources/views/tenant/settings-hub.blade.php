@extends('layouts.admin.master')

@section('title', __('Settings'))

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            @php
                $activeTenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();
            @endphp

            {{-- Organization --}}
            <div class="row g-5 g-xl-8 mb-8">
                <div class="col-12">
                    <h4 class="text-gray-800 fw-bolder mb-0">Organization</h4>
                    <span class="text-muted fs-7">Manage your organization profile, branding, and configuration.</span>
                </div>

                <div class="col-md-6 col-xl-4">
                    <a href="{{ route('tenant.settings.index') }}" class="card card-flush shadow-sm h-100 hover-elevate-up">
                        <div class="card-body d-flex align-items-center">
                            <div class="symbol symbol-50px me-5">
                                <span class="symbol-label bg-light-primary">
                                    <i class="fas fa-cog fs-2x text-primary"></i>
                                </span>
                            </div>
                            <div>
                                <span class="text-dark fw-bolder fs-5 d-block">Org Settings</span>
                                <span class="text-muted fw-bold fs-7">Name, logo, branding</span>
                            </div>
                        </div>
                    </a>
                </div>

                @if($activeTenant->featureEnabled('white_label'))
                    <div class="col-md-6 col-xl-4">
                        <a href="{{ route('tenant.settings.white-label') }}" class="card card-flush shadow-sm h-100 hover-elevate-up">
                            <div class="card-body d-flex align-items-center">
                                <div class="symbol symbol-50px me-5">
                                    <span class="symbol-label bg-light-info">
                                        <i class="fas fa-palette fs-2x text-info"></i>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-dark fw-bolder fs-5 d-block">White Label</span>
                                    <span class="text-muted fw-bold fs-7">Custom domain & styling</span>
                                </div>
                            </div>
                        </a>
                    </div>
                @endif

                @if($activeTenant->featureEnabled('commerce'))
                    <div class="col-md-6 col-xl-4">
                        <a href="{{ route('tenant.settings.payments.index') }}" class="card card-flush shadow-sm h-100 hover-elevate-up">
                            <div class="card-body d-flex align-items-center">
                                <div class="symbol symbol-50px me-5">
                                    <span class="symbol-label bg-light-success">
                                        <i class="fas fa-credit-card fs-2x text-success"></i>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-dark fw-bolder fs-5 d-block">Merchant Payments</span>
                                    <span class="text-muted fw-bold fs-7">Payment gateway config</span>
                                </div>
                            </div>
                        </a>
                    </div>
                @endif

                @if($activeTenant->featureEnabled('llm_usage'))
                    <div class="col-md-6 col-xl-4">
                        <a href="{{ route('tenant.llm-usage.index') }}" class="card card-flush shadow-sm h-100 hover-elevate-up">
                            <div class="card-body d-flex align-items-center">
                                <div class="symbol symbol-50px me-5">
                                    <span class="symbol-label bg-light-warning">
                                        <i class="fas fa-chart-bar fs-2x text-warning"></i>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-dark fw-bolder fs-5 d-block">LLM Usage</span>
                                    <span class="text-muted fw-bold fs-7">Token consumption & balance</span>
                                </div>
                            </div>
                        </a>
                    </div>
                @endif

                @if($activeTenant->featureEnabled('llm_byok'))
                    <div class="col-md-6 col-xl-4">
                        <a href="{{ route('tenant.llm-config.index') }}" class="card card-flush shadow-sm h-100 hover-elevate-up">
                            <div class="card-body d-flex align-items-center">
                                <div class="symbol symbol-50px me-5">
                                    <span class="symbol-label bg-light-danger">
                                        <i class="fas fa-key fs-2x text-danger"></i>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-dark fw-bolder fs-5 d-block">LLM Providers</span>
                                    <span class="text-muted fw-bold fs-7">Bring your own API keys</span>
                                </div>
                            </div>
                        </a>
                    </div>
                @endif
            </div>

            {{-- Team --}}
            <div class="row g-5 g-xl-8 mb-8">
                <div class="col-12">
                    <h4 class="text-gray-800 fw-bolder mb-0">Team</h4>
                    <span class="text-muted fs-7">Manage users, roles, and permissions.</span>
                </div>

                @can('read user')
                    <div class="col-md-6 col-xl-4">
                        <a href="{{ route('tenant.users.index') }}" class="card card-flush shadow-sm h-100 hover-elevate-up">
                            <div class="card-body d-flex align-items-center">
                                <div class="symbol symbol-50px me-5">
                                    <span class="symbol-label bg-light-primary">
                                        <i class="fas fa-users fs-2x text-primary"></i>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-dark fw-bolder fs-5 d-block">Users</span>
                                    <span class="text-muted fw-bold fs-7">Invite & manage team members</span>
                                </div>
                            </div>
                        </a>
                    </div>
                @endcan

                @can('read role')
                    <div class="col-md-6 col-xl-4">
                        <a href="{{ route('tenant.roles.index') }}" class="card card-flush shadow-sm h-100 hover-elevate-up">
                            <div class="card-body d-flex align-items-center">
                                <div class="symbol symbol-50px me-5">
                                    <span class="symbol-label bg-light-info">
                                        <i class="fas fa-shield-alt fs-2x text-info"></i>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-dark fw-bolder fs-5 d-block">Roles & Permissions</span>
                                    <span class="text-muted fw-bold fs-7">Access control & policies</span>
                                </div>
                            </div>
                        </a>
                    </div>
                @endcan
            </div>

            {{-- Billing --}}
            <div class="row g-5 g-xl-8 mb-8">
                <div class="col-12">
                    <h4 class="text-gray-800 fw-bolder mb-0">Billing</h4>
                    <span class="text-muted fs-7">Manage your subscription, plans, and invoices.</span>
                </div>

                <div class="col-md-6 col-xl-4">
                    <a href="{{ route('tenant.pricing') }}" class="card card-flush shadow-sm h-100 hover-elevate-up">
                        <div class="card-body d-flex align-items-center">
                            <div class="symbol symbol-50px me-5">
                                <span class="symbol-label bg-light-success">
                                    <i class="fas fa-box-open fs-2x text-success"></i>
                                </span>
                            </div>
                            <div>
                                <span class="text-dark fw-bolder fs-5 d-block">Plans & Upgrades</span>
                                <span class="text-muted fw-bold fs-7">View & change your plan</span>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-md-6 col-xl-4">
                    <a href="{{ route('billing.index') }}" class="card card-flush shadow-sm h-100 hover-elevate-up">
                        <div class="card-body d-flex align-items-center">
                            <div class="symbol symbol-50px me-5">
                                <span class="symbol-label bg-light-warning">
                                    <i class="fas fa-file-invoice-dollar fs-2x text-warning"></i>
                                </span>
                            </div>
                            <div>
                                <span class="text-dark fw-bolder fs-5 d-block">Billing & Invoices</span>
                                <span class="text-muted fw-bold fs-7">Payment history & receipts</span>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-md-6 col-xl-4">
                    <a href="{{ route('tenant.settings.usage') }}" class="card card-flush shadow-sm h-100 hover-elevate-up">
                        <div class="card-body d-flex align-items-center">
                            <div class="symbol symbol-50px me-5">
                                <span class="symbol-label bg-light-primary">
                                    <i class="fas fa-tachometer-alt fs-2x text-primary"></i>
                                </span>
                            </div>
                            <div>
                                <span class="text-dark fw-bolder fs-5 d-block">Usage & Quotas</span>
                                <span class="text-muted fw-bold fs-7">Track limits & consumption</span>
                            </div>
                        </div>
                    </a>
                </div>

                @if($activeTenant->featureEnabled('commerce'))
                    <div class="col-md-6 col-xl-4">
                        <a href="{{ route('tenant.finance.index') }}" class="card card-flush shadow-sm h-100 hover-elevate-up">
                            <div class="card-body d-flex align-items-center">
                                <div class="symbol symbol-50px me-5">
                                    <span class="symbol-label bg-light-danger">
                                        <i class="fas fa-chart-line fs-2x text-danger"></i>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-dark fw-bolder fs-5 d-block">Finance & Sales</span>
                                    <span class="text-muted fw-bold fs-7">Revenue & transactions</span>
                                </div>
                            </div>
                        </a>
                    </div>
                @endif
            </div>

            {{-- Preferences --}}
            <div class="row g-5 g-xl-8 mb-8">
                <div class="col-12">
                    <h4 class="text-gray-800 fw-bolder mb-0">Preferences</h4>
                    <span class="text-muted fs-7">Manage notifications and personal preferences.</span>
                </div>

                <div class="col-md-6 col-xl-4">
                    <a href="{{ route('tenant.settings.notifications') }}" class="card card-flush shadow-sm h-100 hover-elevate-up">
                        <div class="card-body d-flex align-items-center">
                            <div class="symbol symbol-50px me-5">
                                <span class="symbol-label bg-light-info">
                                    <i class="fas fa-bell fs-2x text-info"></i>
                                </span>
                            </div>
                            <div>
                                <span class="text-dark fw-bolder fs-5 d-block">Notifications</span>
                                <span class="text-muted fw-bold fs-7">Email & push preferences</span>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
