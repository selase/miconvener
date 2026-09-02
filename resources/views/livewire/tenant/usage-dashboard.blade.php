<div>
    {{-- Header --}}
    <div class="card card-flush shadow-sm mb-8">
        <div class="card-header border-0 pt-6">
            <div>
                <h3 class="card-title fw-boldest text-dark mb-1">Usage & Quotas</h3>
                <span class="text-gray-400 fw-bold fs-7">
                    {{ now()->format('F Y') }} billing period ·
                    <span class="text-primary">{{ $package?->name ?? 'Free' }} plan</span>
                </span>
            </div>
            <div class="card-toolbar">
                <a href="{{ route('tenant.pricing') }}" class="btn btn-sm btn-light-primary">
                    <i class="fas fa-arrow-up me-1"></i> Upgrade Plan
                </a>
            </div>
        </div>
    </div>

    {{-- Quota Progress Bars --}}
    @if($usageRows->isNotEmpty())
        <div class="card card-flush shadow-sm mb-8">
            <div class="card-header border-0 pt-6">
                <h4 class="card-title fw-bolder text-dark">Plan Limits</h4>
            </div>
            <div class="card-body pt-0">
                @foreach($usageRows as $row)
                    <div class="mb-7">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-dark fw-bold fs-6">{{ $row['name'] }}</span>
                            <span class="fw-boldest text-{{ $row['color'] }} fs-7">
                                {{ number_format($row['used']) }} / {{ number_format($row['limit']) }}
                                @if($row['pct'] >= 90)
                                    <span class="badge badge-light-danger ms-2">Near limit</span>
                                @endif
                            </span>
                        </div>
                        <div class="h-8px bg-light-{{ $row['color'] }} rounded w-100">
                            <div class="bg-{{ $row['color'] }} rounded h-8px transition-width"
                                 style="width: {{ $row['pct'] }}%"
                                 role="progressbar"
                                 aria-valuenow="{{ $row['pct'] }}"
                                 aria-valuemin="0"
                                 aria-valuemax="100">
                            </div>
                        </div>
                        <div class="d-flex justify-content-between mt-1">
                            <span class="text-muted fs-8">{{ $row['pct'] }}% used</span>
                            <span class="text-muted fs-8">{{ number_format($row['limit'] - $row['used']) }} remaining</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Team Seats --}}
    @if($seatLimit !== null)
        @php
            $seatPct = $seatLimit > 0 ? min((int) round(($teamCount / $seatLimit) * 100), 100) : 0;
            $seatColor = $seatPct >= 90 ? 'danger' : ($seatPct >= 70 ? 'warning' : 'success');
        @endphp
        <div class="card card-flush shadow-sm mb-8">
            <div class="card-header border-0 pt-6">
                <h4 class="card-title fw-bolder text-dark">Team Seats</h4>
                <div class="card-toolbar">
                    <a href="{{ route('tenant.users.index') }}" class="btn btn-sm btn-light">Manage Team</a>
                </div>
            </div>
            <div class="card-body pt-0">
                <div class="d-flex align-items-center gap-5 mb-4">
                    <div class="symbol symbol-65px">
                        <span class="symbol-label bg-light-{{ $seatColor }} fs-2hx fw-boldest text-{{ $seatColor }}">
                            {{ $teamCount }}
                        </span>
                    </div>
                    <div>
                        <div class="fs-2hx fw-boldest text-dark">
                            {{ $teamCount }} <span class="fs-5 text-muted fw-bold">of {{ $seatLimit }} seats used</span>
                        </div>
                        <div class="text-muted fs-7">{{ $seatLimit - $teamCount }} seats available</div>
                    </div>
                </div>
                <div class="h-8px bg-light-{{ $seatColor }} rounded">
                    <div class="bg-{{ $seatColor }} rounded h-8px" style="width: {{ $seatPct }}%"></div>
                </div>
            </div>
        </div>
    @endif

    {{-- Enabled Features --}}
    @if($enabledFeatures->isNotEmpty())
        <div class="card card-flush shadow-sm mb-8">
            <div class="card-header border-0 pt-6">
                <h4 class="card-title fw-bolder text-dark">Included Features</h4>
            </div>
            <div class="card-body pt-0">
                <div class="row g-4">
                    @foreach($enabledFeatures as $feature)
                        <div class="col-md-6 col-xl-4">
                            <div class="d-flex align-items-center gap-3 p-3 rounded bg-light-success">
                                <i class="fas fa-check-circle text-success fs-4"></i>
                                <div>
                                    <div class="text-dark fw-bold fs-7">{{ $feature->name }}</div>
                                    @if($feature->description)
                                        <div class="text-muted fs-8">{{ Str::limit($feature->description, 60) }}</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Empty state --}}
    @if($usageRows->isEmpty() && $seatLimit === null && $enabledFeatures->isEmpty())
        <div class="card card-flush shadow-sm">
            <div class="card-body d-flex flex-column align-items-center py-15">
                <i class="fas fa-chart-bar fs-3x text-gray-300 mb-5"></i>
                <h4 class="fw-bolder text-dark mb-2">No usage data yet</h4>
                <p class="text-muted text-center mb-6">
                    Usage metrics will appear here once your plan has active limits.
                </p>
                <a href="{{ route('tenant.pricing') }}" class="btn btn-primary">View Plans</a>
            </div>
        </div>
    @endif
</div>
