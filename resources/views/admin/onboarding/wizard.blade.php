@extends('layouts.admin.master')

@section('title', 'Welcome — Get Started')

@push('styles')
<style>
    @@keyframes pop-in {
        0%   { opacity: 0; transform: scale(0.6); }
        70%  { transform: scale(1.08); }
        100% { opacity: 1; transform: scale(1); }
    }
    @@keyframes check-draw {
        from { stroke-dashoffset: 60; }
        to   { stroke-dashoffset: 0; }
    }
    @@keyframes fade-up {
        from { opacity: 0; transform: translateY(14px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .pop-in  { animation: pop-in  0.5s cubic-bezier(.36,.07,.19,.97) forwards; }
    .fade-up { animation: fade-up 0.5s ease-out forwards; opacity: 0; }
    .check-path { stroke-dasharray: 60; animation: check-draw 0.5s ease-out 0.4s forwards; stroke-dashoffset: 60; }
    .step-card { transition: box-shadow 0.2s, border-color 0.2s; }
    .step-card:hover { box-shadow: 0 4px 24px rgba(59,130,246,.12) !important; border-color: #93c5fd !important; }
</style>
@endpush

@section('content')
<div class="container-xxl py-10">
    @php
        $routePrefix = request()->route('subdomain') ? 'tenant.onboarding.' : 'onboarding.';
        $packageName = session('subscription_activated', '');
    @endphp

    {{-- ── Subscription confirmed banner ── --}}
    @if ($packageName)
        <div class="fade-up d-flex align-items-center gap-4 rounded-3 border border-success bg-light-success px-6 py-4 mb-8" style="animation-delay:.05s">
            <span class="pop-in d-inline-flex align-items-center justify-content-center rounded-circle bg-success" style="width:40px;height:40px;flex-shrink:0">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path class="check-path" d="M5 13l4 4L19 7"/>
                </svg>
            </span>
            <div>
                <div class="fw-boldest text-dark fs-5">{{ $packageName }} subscription activated!</div>
                <div class="text-muted fs-7">Your payment was successful. You're all set.</div>
            </div>
        </div>
    @endif

    {{-- ── Header ── --}}
    <div class="text-center mb-12 fade-up" style="animation-delay:.1s">
        <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-5"
            style="width:72px;height:72px;background:linear-gradient(135deg,#3b82f6,#6366f1)">
            <i class="fas fa-rocket text-white fs-2x"></i>
        </div>
        <h1 class="fw-boldest text-dark fs-2tx mb-3">Welcome to {{ $tenant->name }}!</h1>
        <p class="text-muted fw-bold fs-5 mx-auto" style="max-width:440px">
            Let's get your workspace ready. You can always come back to these steps from your settings.
        </p>
    </div>

    {{-- ── Step cards ── --}}
    <div class="row g-6 mb-10">

        {{-- Step 1: Branding --}}
        <div class="col-lg-4 fade-up" style="animation-delay:.2s">
            <div class="card card-flush shadow-sm step-card h-100 border border-2 border-transparent rounded-3">
                <div class="card-body p-8">
                    <div class="d-flex align-items-center justify-content-between mb-6">
                        <span class="badge badge-light-primary fs-8 fw-boldest px-3 py-2">Step 1</span>
                        <div class="d-flex align-items-center justify-content-center rounded-circle bg-light-primary"
                            style="width:40px;height:40px">
                            <i class="fas fa-paint-brush text-primary fs-4"></i>
                        </div>
                    </div>
                    <h4 class="fw-boldest text-dark mb-2">Set up your branding</h4>
                    <p class="text-muted fs-7 mb-7">Add your organization name and logo so your workspace feels like home.</p>

                    <form action="{{ route($routePrefix . 'branding.update') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-4">
                            <label class="form-label fw-bold fs-7">Organization name</label>
                            <input class="form-control form-control-solid form-control-sm" type="text"
                                name="name" value="{{ $tenant->name }}" required />
                        </div>
                        <div class="mb-6">
                            <label class="form-label fw-bold fs-7">Logo <span class="text-muted fw-normal">(optional)</span></label>
                            <input class="form-control form-control-solid form-control-sm" type="file"
                                name="logo" accept="image/*" />
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-save me-2"></i>Save & continue
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Step 2: Invite team --}}
        <div class="col-lg-4 fade-up" style="animation-delay:.4s">
            <div class="card card-flush shadow-sm step-card h-100 border border-2 border-transparent rounded-3">
                <div class="card-body p-8 d-flex flex-column">
                    <div class="d-flex align-items-center justify-content-between mb-6">
                        <span class="badge badge-light-warning fs-8 fw-boldest px-3 py-2">Step 2</span>
                        <div class="d-flex align-items-center justify-content-center rounded-circle bg-light-warning"
                            style="width:40px;height:40px">
                            <i class="fas fa-users text-warning fs-4"></i>
                        </div>
                    </div>
                    <h4 class="fw-boldest text-dark mb-2">Invite your team</h4>
                    <p class="text-muted fs-7 mb-7">Add teammates so they can join your workspace.</p>

                    <div class="mt-auto">
                        <a href="{{ route('tenant.users.index', ['subdomain' => $tenant->slug]) }}"
                            class="btn btn-warning btn-sm w-100">
                            <i class="fas fa-user-plus me-2"></i>Invite teammates
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Skip to dashboard ── --}}
    <div class="text-center fade-up" style="animation-delay:.5s">
        <form action="{{ route($routePrefix . 'finish') }}" method="POST" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-light text-muted fw-bold">
                Skip setup — take me to my dashboard
                <i class="fas fa-arrow-right ms-2 fs-7"></i>
            </button>
        </form>
        <p class="text-muted fs-8 mt-3">You can configure everything from <strong>Settings</strong> at any time.</p>
    </div>
</div>
@endsection
