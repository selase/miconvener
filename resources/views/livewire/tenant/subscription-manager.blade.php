<div x-data="{ showConfirm: @entangle('confirmationType').live }" @keydown.escape.window="$wire.dismissConfirmation()">

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="alert alert-success d-flex align-items-center p-5 mb-7">
            <i class="fas fa-check-circle text-success me-4 fs-2"></i>
            <span class="fw-bold">{{ session('success') }}</span>
        </div>
    @endif
    @if(session('info'))
        <div class="alert alert-primary d-flex align-items-center p-5 mb-7">
            <i class="fas fa-info-circle text-primary me-4 fs-2"></i>
            <span class="fw-bold">{{ session('info') }}</span>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger d-flex align-items-center p-5 mb-7">
            <i class="fas fa-exclamation-circle text-danger me-4 fs-2"></i>
            <span class="fw-bold">{{ session('error') }}</span>
        </div>
    @endif

    {{-- Current Plan Summary Card --}}
    <div class="card card-flush shadow-sm mb-8">
        <div class="card-header border-0 pt-6">
            <h3 class="card-title fw-boldest text-dark">Your Subscription</h3>
            <div class="card-toolbar gap-3">
                @if($this->subscription && $this->subscription->isActive())
                    <button wire:click="openCancelConfirmation" class="btn btn-sm btn-light-danger">
                        <i class="fas fa-times-circle me-1"></i> Cancel Plan
                    </button>
                @endif
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="row g-5">
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <div class="symbol symbol-50px symbol-circle me-4">
                            <span class="symbol-label bg-light-primary">
                                <i class="fas fa-layer-group text-primary fs-2"></i>
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-400 fw-bold fs-7 d-block">Current Plan</span>
                            <span class="text-dark fw-boldest fs-5">{{ $this->currentPackage?->name ?? 'Free' }}</span>
                            @if($this->subscription?->hasPendingChange())
                                <span class="badge badge-light-warning ms-2 fs-8">Change Scheduled</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <div class="symbol symbol-50px symbol-circle me-4">
                            <span class="symbol-label bg-light-success">
                                <i class="fas fa-circle-check text-success fs-2"></i>
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-400 fw-bold fs-7 d-block">Status</span>
                            @if($this->subscription?->isActive())
                                <span class="badge badge-light-success fw-boldest">Active</span>
                            @elseif($this->subscription?->onGracePeriod())
                                <span class="badge badge-light-warning fw-boldest">Grace Period</span>
                            @else
                                <span class="badge badge-light-primary fw-boldest">Free Tier</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <div class="symbol symbol-50px symbol-circle me-4">
                            <span class="symbol-label bg-light-info">
                                <i class="fas fa-calendar-alt text-info fs-2"></i>
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-400 fw-bold fs-7 d-block">Next Billing</span>
                            @if($this->subscription?->current_period_end)
                                <span class="text-dark fw-boldest">
                                    {{ $this->subscription->current_period_end->format('M d, Y') }}
                                </span>
                            @else
                                <span class="text-muted fw-bold">—</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            @if($this->subscription?->hasPendingChange())
                <div class="separator my-6"></div>
                <div class="notice d-flex bg-light-warning rounded border-warning border border-dashed p-6">
                    <i class="fas fa-clock text-warning me-4 fs-2"></i>
                    <div>
                        <span class="fw-boldest text-dark d-block">Scheduled Change</span>
                        <span class="text-gray-600 fw-bold fs-7">
                            Your plan will switch to
                            <strong>{{ $this->subscription->pendingPackage?->name }}</strong>
                            at the end of your current billing period
                            @if($this->subscription->current_period_end)
                                ({{ $this->subscription->current_period_end->format('M d, Y') }})
                            @endif.
                        </span>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Billing Interval Toggle --}}
    <div class="d-flex justify-content-center mb-8">
        <div class="nav-group nav-group-fluid rounded border border-gray-300 p-1 bg-white d-inline-flex gap-2">
            <button wire:click="setInterval('month')"
                class="btn btn-sm {{ $interval === 'month' ? 'btn-primary' : 'btn-light' }} fw-boldest px-6">
                Monthly
            </button>
            <button wire:click="setInterval('year')"
                class="btn btn-sm {{ $interval === 'year' ? 'btn-primary' : 'btn-light' }} fw-boldest px-6">
                Annual
                <span class="badge badge-light-success ms-2 fs-8">Save 17%</span>
            </button>
        </div>
    </div>

    {{-- Plan Cards --}}
    <div class="row g-6 mb-8">
        @foreach($this->packages as $package)
            @php
                $isCurrent       = $this->currentPackage?->id === $package->id;
                $currentSort     = $this->currentPackage?->sort_order ?? 0;
                $isUpgrade       = !$isCurrent && $package->sort_order > $currentSort;
                $isDowngrade     = !$isCurrent && !$package->isFree() && $package->sort_order < $currentSort;
                $isFreeTier      = $package->isFree() && !$isCurrent;
                $displayPrice    = $interval === 'year'
                    ? ($package->yearly_price ?? $package->price * 10)
                    : $package->price;
                $hasPlanCode     = (bool) $package->getPlanCodeForInterval($interval);
            @endphp
            <div class="col-xl-3 col-md-6">
                <div class="card card-flush shadow-sm h-100 {{ $isCurrent ? 'border border-primary border-2' : '' }} position-relative">

                    @if($package->slug === 'pro')
                        <div class="ribbon ribbon-top ribbon-vertical">
                            <div class="ribbon-label bg-primary fw-boldest fs-8" style="top:0;right:12px;padding:4px 8px;border-radius:0 0 6px 6px;">
                                Popular
                            </div>
                        </div>
                    @endif

                    <div class="card-body d-flex flex-column p-8">
                        {{-- Plan Header --}}
                        <div class="mb-6">
                            @if($isCurrent)
                                <span class="badge badge-light-primary fw-boldest mb-3">Current Plan</span>
                            @endif
                            <h3 class="fw-boldest text-dark mb-1">{{ $package->name }}</h3>
                            <p class="text-gray-500 fw-bold fs-7 mb-0">{{ $package->description }}</p>
                        </div>

                        {{-- Pricing --}}
                        <div class="mb-7">
                            @if($package->isFree())
                                <div class="fs-2hx fw-boldest text-dark">Free</div>
                                <span class="text-gray-400 fw-bold fs-7">forever</span>
                            @else
                                <div class="d-flex align-items-end gap-1">
                                    <span class="text-primary fw-boldest fs-4">$</span>
                                    <span class="fs-2hx fw-boldest text-dark lh-1">
                                        {{ number_format((float) $displayPrice, 0) }}
                                    </span>
                                    <span class="text-gray-400 fw-bold fs-7 mb-1">
                                        /{{ $interval === 'year' ? 'yr' : 'mo' }}
                                    </span>
                                </div>
                                @if($interval === 'year' && $package->yearly_price)
                                    <span class="text-success fw-bold fs-8">
                                        Billed annually · Save ${{ number_format((float)($package->price * 12 - $package->yearly_price), 0) }}/yr
                                    </span>
                                @endif
                            @endif
                        </div>

                        <div class="separator mb-6"></div>

                        {{-- Feature List --}}
                        <div class="flex-grow-1 mb-8">
                            @foreach($package->features->take(8) as $feature)
                                <div class="d-flex align-items-center mb-4" wire:key="feat-{{ $package->id }}-{{ $feature->id }}">
                                    <span class="svg-icon svg-icon-2 svg-icon-success me-3 flex-shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                            <rect opacity="0.3" x="2" y="2" width="20" height="20" rx="10" fill="currentColor"/>
                                            <path d="M10.4343 12.4343L8.75 10.75C8.33579 10.3358 7.66421 10.3358 7.25 10.75C6.83579 11.1642 6.83579 11.8358 7.25 12.25L10.2929 15.2929C10.6834 15.6834 11.3166 15.6834 11.7071 15.2929L17.25 9.75C17.6642 9.33579 17.6642 8.66421 17.25 8.25C16.8358 7.83579 16.1642 7.83579 15.75 8.25L10.4343 12.4343Z" fill="currentColor"/>
                                        </svg>
                                    </span>
                                    <span class="text-gray-700 fw-bold fs-7">
                                        {{ $feature->name }}
                                        @if($feature->pivot->value && !in_array($feature->pivot->value, ['1', 'true', '-1']))
                                            <span class="text-primary fw-boldest">{{ $feature->pivot->value === '-1' ? 'Unlimited' : $feature->pivot->value }}</span>
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                            @if($package->features->count() > 8)
                                <div class="text-muted fw-bold fs-8 mt-2">
                                    + {{ $package->features->count() - 8 }} more features
                                </div>
                            @endif
                        </div>

                        {{-- CTA Button --}}
                        @if($isCurrent)
                            <button class="btn btn-light-primary btn-sm w-100 fw-boldest" disabled>
                                <i class="fas fa-check me-2"></i>Current Plan
                            </button>
                        @elseif($isUpgrade && !$hasPlanCode)
                            <button class="btn btn-secondary btn-sm w-100 fw-boldest" disabled title="Plan code not configured">
                                Contact Sales
                            </button>
                        @elseif($isUpgrade)
                            <form method="POST" action="{{ route('billing.checkout') }}">
                                @csrf
                                <input type="hidden" name="plan" value="{{ $package->slug }}">
                                <input type="hidden" name="interval" value="{{ $interval }}">
                                <button type="submit" class="btn btn-primary btn-sm w-100 fw-boldest">
                                    <span wire:loading.remove>
                                        <i class="fas fa-arrow-up me-2"></i>Upgrade to {{ $package->name }}
                                    </span>
                                    <span wire:loading>
                                        <span class="spinner-border spinner-border-sm me-2"></span>Redirecting...
                                    </span>
                                </button>
                            </form>
                        @elseif($isDowngrade)
                            <button wire:click="requestPlanChange('{{ $package->slug }}')"
                                class="btn btn-light-warning btn-sm w-100 fw-boldest">
                                <i class="fas fa-arrow-down me-2"></i>Downgrade to {{ $package->name }}
                            </button>
                        @elseif($isFreeTier)
                            <button wire:click="requestPlanChange('{{ $package->slug }}')"
                                class="btn btn-light-danger btn-sm w-100 fw-boldest">
                                <i class="fas fa-times-circle me-2"></i>Switch to Free
                            </button>
                        @else
                            <form method="POST" action="{{ route('billing.checkout') }}">
                                @csrf
                                <input type="hidden" name="plan" value="{{ $package->slug }}">
                                <input type="hidden" name="interval" value="{{ $interval }}">
                                <button type="submit" class="btn btn-primary btn-sm w-100 fw-boldest">
                                    Get Started
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Confirmation Modal (Downgrade / Cancel) --}}
    @if($confirmationType)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,0.5);">
            <div class="modal-dialog modal-dialog-centered mw-500px">
                <div class="modal-content">
                    <div class="modal-header border-0 pb-0">
                        <h4 class="fw-boldest text-dark">
                            @if($confirmationType === 'cancel')
                                <i class="fas fa-times-circle text-danger me-2"></i>Cancel Subscription
                            @else
                                <i class="fas fa-arrow-down text-warning me-2"></i>Confirm Downgrade
                            @endif
                        </h4>
                        <button wire:click="dismissConfirmation" class="btn btn-icon btn-sm btn-light ms-auto">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body pt-4">
                        @if($confirmationType === 'cancel')
                            <p class="text-gray-600 fw-bold mb-4">
                                Are you sure you want to cancel your subscription?
                            </p>
                            <div class="notice d-flex bg-light-danger rounded border-danger border border-dashed p-5 mb-4">
                                <i class="fas fa-exclamation-triangle text-danger me-3 fs-4 mt-1"></i>
                                <div>
                                    <span class="fw-boldest text-dark d-block mb-2">What happens next:</span>
                                    <ul class="text-gray-600 fw-bold fs-7 mb-0 ps-3">
                                        <li>You keep your current features until the billing period ends</li>
                                        <li>At period end, your account downgrades to the <strong>Free plan</strong></li>
                                        <li>Data is preserved — you can resubscribe at any time</li>
                                    </ul>
                                </div>
                            </div>
                        @else
                            @php $targetPackage = $this->packages->firstWhere('slug', $confirmingPlanSlug); @endphp
                            <p class="text-gray-600 fw-bold mb-4">
                                You're about to downgrade to the <strong>{{ $targetPackage?->name }}</strong> plan.
                            </p>
                            <div class="notice d-flex bg-light-warning rounded border-warning border border-dashed p-5 mb-4">
                                <i class="fas fa-clock text-warning me-3 fs-4 mt-1"></i>
                                <div>
                                    <span class="fw-boldest text-dark d-block mb-2">Scheduled change:</span>
                                    <ul class="text-gray-600 fw-bold fs-7 mb-0 ps-3">
                                        <li>You keep your current plan features until the billing period ends</li>
                                        <li>The downgrade takes effect on your next renewal date</li>
                                        <li>You can upgrade again at any time</li>
                                    </ul>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button wire:click="dismissConfirmation" class="btn btn-light fw-boldest">
                            Keep My Plan
                        </button>
                        <button wire:click="applyConfirmedChange" class="btn {{ $confirmationType === 'cancel' ? 'btn-danger' : 'btn-warning' }} fw-boldest">
                            <span wire:loading.remove wire:target="applyConfirmedChange">
                                @if($confirmationType === 'cancel') Cancel Subscription @else Confirm Downgrade @endif
                            </span>
                            <span wire:loading wire:target="applyConfirmedChange">
                                <span class="spinner-border spinner-border-sm me-2"></span>Processing...
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>
