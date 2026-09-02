<div>
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">

            {{-- Onboarding Checklist (only if not completed) --}}
            @if(!$checklist['onboarding'])
                <div class="card card-flush shadow-sm mb-8">
                    <div class="card-header border-0 pt-5">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-boldest text-dark">Getting Started</span>
                            <span class="text-gray-400 mt-1 fw-bold fs-7">Complete these to set up your organization</span>
                        </h3>
                    </div>
                    <div class="card-body pt-2">
                        <div class="d-flex gap-8 flex-wrap">
                            <div class="d-flex align-items-center">
                                <div class="symbol symbol-30px symbol-circle me-3">
                                    <span class="symbol-label {{ $checklist['branding'] ? 'bg-light-success text-success' : 'bg-light-primary text-primary' }}">
                                        @if($checklist['branding']) <i class="fas fa-check"></i> @else 1 @endif
                                    </span>
                                </div>
                                <a href="{{ route('tenant.settings.index') }}" class="text-dark fw-bold text-hover-primary fs-6 {{ $checklist['branding'] ? 'text-decoration-line-through text-muted' : '' }}">
                                    Customize Branding
                                </a>
                            </div>
                            <div class="d-flex align-items-center">
                                <div class="symbol symbol-30px symbol-circle me-3">
                                    <span class="symbol-label {{ $checklist['team'] ? 'bg-light-success text-success' : 'bg-light-primary text-primary' }}">
                                        @if($checklist['team']) <i class="fas fa-check"></i> @else 2 @endif
                                    </span>
                                </div>
                                <a href="{{ route('tenant.users.index') }}" class="text-dark fw-bold text-hover-primary fs-6 {{ $checklist['team'] ? 'text-decoration-line-through text-muted' : '' }}">
                                    Add Your Team
                                </a>
                            </div>
                            <div class="d-flex align-items-center">
                                <div class="symbol symbol-30px symbol-circle me-3">
                                    <span class="symbol-label bg-light-primary text-primary">3</span>
                                </div>
                                <form action="{{ route('tenant.onboarding.finish') }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-link p-0 text-dark fw-bold text-hover-primary fs-6 border-0">
                                        Mark as Setup Complete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Stats Row --}}
            <div class="row g-5 g-xl-8 mb-5">
                <div class="col-xl-4">
                    <div class="card card-flush shadow-sm h-100">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-gray-400 fw-bold fs-7">Welcome</span>
                                <div class="fs-2hx fw-boldest text-dark">{{ $tenant->name }}</div>
                            </div>
                            <i class="fas fa-building fs-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
