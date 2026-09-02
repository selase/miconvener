@extends('layouts.admin.master')

@section('title', __('Organization Settings'))

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            <form action="{{ route('tenant.settings.update') }}" method="post" enctype="multipart/form-data">
                @csrf
                <div class="row g-5 g-xl-10">
                    <div class="col-md-8">
                        <div class="card card-flush h-lg-100">
                            <div class="card-header pt-7">
                                <h3 class="card-title align-items-start flex-column">
                                    <span class="card-label fw-bolder text-dark">{{ __('Organization Profile') }}</span>
                                    <span class="text-muted mt-1 fw-bold fs-7">{{ __('Update your organization information and branding') }}</span>
                                </h3>
                            </div>
                            <div class="card-body pt-5">
                                <div class="fv-row mb-7 text-center">
                                    <div class="symbol symbol-100px symbol-circle mb-7">
                                        @if($tenant->logo)
                                            <img src="{{ Storage::url($tenant->logo) }}" alt="Logo" />
                                        @else
                                            <img src="{{ $tenant->gravatar }}" alt="Logo" />
                                        @endif
                                    </div>
                                    <div class="mx-auto" style="max-width: 400px;">
                                        <label for="logo" class="fw-bold fs-6 mb-2 d-block">{{ __('Change Logo') }}</label>
                                        <input type="file" name="logo" class="form-control form-control-solid @error('logo') is-invalid @enderror" />
                                        @error('logo')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row g-9 mb-7">
                                    <div class="col-md-6 fv-row">
                                        <label for="name" class="required fw-bold fs-6 mb-2">{{ __('Organization Name') }}</label>
                                        <input type="text" name="name" class="form-control form-control-solid @error('name') is-invalid @enderror" value="{{ old('name', $tenant->name) }}" />
                                        @error('name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-6 fv-row">
                                        <label for="primary_color" class="fw-bold fs-6 mb-2">{{ __('Primary Branding Color') }}</label>
                                        <input type="color" name="primary_color" class="form-control form-control-color w-100 @error('primary_color') is-invalid @enderror" value="{{ old('primary_color', $tenant->meta['primary_color'] ?? '#009EF7') }}" />
                                        @error('primary_color')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="row g-9 mb-7">
                                    <div class="col-md-6 fv-row">
                                        <label for="email" class="required fw-bold fs-6 mb-2">{{ __('Support Email') }}</label>
                                        <input type="email" name="email" class="form-control form-control-solid @error('email') is-invalid @enderror" value="{{ old('email', $tenant->email) }}" />
                                        @error('email')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-6 fv-row">
                                        <label for="phone_number" class="required fw-bold fs-6 mb-2">{{ __('Support Phone') }}</label>
                                        <input type="text" name="phone_number" class="form-control form-control-solid @error('phone_number') is-invalid @enderror" value="{{ old('phone_number', $tenant->phone_number) }}" />
                                        @error('phone_number')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="text-end pt-5">
                                    <button type="submit" class="btn btn-primary">
                                        <span class="indicator-label">{{ __('Save Changes') }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card card-flush mb-5">
                            <div class="card-header border-0 pt-6">
                                <div class="card-title">
                                    {{ __('Plan Information') }}
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="d-flex flex-stack mr-5 mb-5">
                                    <span class="fw-boldest text-gray-800 fs-6">Current Plan:</span>
                                    <span class="badge badge-light-primary fw-bolder">{{ $tenant->package->name ?? 'None' }}</span>
                                </div>
                                <div class="d-flex flex-stack mr-5 mb-5">
                                    <span class="fw-boldest text-gray-800 fs-6">Status:</span>
                                    <span class="badge badge-light-{{ $tenant->status->value === 'active' ? 'success' : 'warning' }} fw-bolder">{{ ucfirst($tenant->status->value) }}</span>
                                </div>
                                <div class="separator separator-dashed my-3"></div>
                                <div class="text-muted fs-7 mb-5">
                                    Want more features? Upgrade your plan to unlock custom domains, commerce, and higher usage limits.
                                </div>
                                <a href="{{ route('tenant.pricing') }}" class="btn btn-sm btn-light-primary w-100">Choose Plan</a>
                            </div>
                        </div>

                        <div class="card card-flush mb-5">
                            <div class="card-header border-0 pt-6">
                                <div class="card-title">
                                    {{ __('Security Enforcement') }}
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="form-check form-switch form-check-custom form-check-solid">
                                    <input class="form-check-input" type="checkbox" name="require_2fa" value="1" id="require_2fa" {{ $tenant->require_2fa ? 'checked' : '' }} />
                                    <label class="form-check-label fw-bold text-gray-700" for="require_2fa">
                                        {{ __('Require 2FA for all members') }}
                                    </label>
                                </div>
                                <div class="text-muted fs-7 mt-2">
                                    {{ __('When enabled, all users must configure Two-Factor Authentication to access the dashboard.') }}
                                </div>
                            </div>
                        </div>

                        @feature(\App\Services\Tenancy\FeatureService::FEATURE_CUSTOM_DOMAINS)
                        <div class="card card-flush mb-5 border-primary border-dashed">
                            <div class="card-header border-0 pt-6">
                                <div class="card-title">
                                    {{ __('Custom Domain') }}
                                </div>
                                <div class="card-toolbar">
                                    <span class="badge badge-light-{{ $tenant->custom_domain_status === 'active' ? 'success' : 'warning' }}">
                                        {{ ucfirst($tenant->custom_domain_status) }}
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="fv-row mb-5">
                                    <label class="fw-bold fs-6 mb-2">{{ __('Your Domain') }}</label>
                                    <input type="text" name="custom_domain" class="form-control form-control-solid" placeholder="app.yourdomain.com" value="{{ old('custom_domain', $tenant->custom_domain) }}" />
                                    <div class="text-muted fs-7 mt-2">
                                        Point a <code>CNAME</code> record for this domain to <code>{{ parse_url(config('app.url'), PHP_URL_HOST) }}</code>
                                    </div>
                                </div>
                                
                                @if($tenant->custom_domain && $tenant->custom_domain_status !== 'active')
                                    <div class="alert alert-light-warning d-flex align-items-center p-5 mb-5">
                                        <span class="svg-icon svg-icon-2hx svg-icon-warning me-4">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </span>
                                        <div class="d-flex flex-column">
                                            <h4 class="mb-1 text-warning">Domain Unverified</h4>
                                            <span>It may take up to 24 hours for DNS changes to propagate.</span>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                        @else
                        <div class="card card-flush mb-5 bg-light-secondary">
                            <div class="card-header border-0 pt-6">
                                <div class="card-title text-gray-400">
                                    {{ __('Custom Domain') }}
                                </div>
                                <div class="card-toolbar">
                                    <span class="badge badge-light-primary">PRO</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <p class="text-gray-600">Enterprise domains are available on the <strong>Scale</strong> plan and above.</p>
                                <a href="#" class="btn btn-sm btn-light-primary disabled">Upgrade to Unlock</a>
                            </div>
                        </div>
                        @endfeature

                        <div class="card card-flush">
                            <div class="card-header border-0 pt-6">
                                <div class="card-title">
                                    {{ __('Data Isolation') }}
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="d-flex flex-stack mr-5 mb-5">
                                    <span class="fw-boldest text-gray-800 fs-6">Mode:</span>
                                    <code class="fs-7">{{ strtoupper($tenant->isolation_mode) }}</code>
                                </div>
                                <div class="d-flex flex-stack mr-5 mb-5">
                                    <span class="fw-boldest text-gray-800 fs-6">Driver:</span>
                                    <code class="fs-7">{{ strtoupper($tenant->db_driver) }}</code>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection