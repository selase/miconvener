@extends('layouts.admin.master')

@section('title', 'Review Venue Merchant — ' . $shop->name)

@section('content')
<div class="row g-5">
    <div class="col-lg-8">
        <div class="card mb-5">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="fw-bolder m-0">Merchant Credentials & Profile</h3>
                </div>
                <div class="card-toolbar">
                    <a href="{{ route('admin.marketplace-verifications.index') }}" class="btn btn-sm btn-light">
                        Back to Queue
                    </a>
                </div>
            </div>

            <div class="card-body py-4">
                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Venue / Business Name</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-800">{{ $shop->name }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Parent Tenant Account</label>
                    <div class="col-lg-8">
                        <span class="badge badge-light-primary fs-7">{{ $shop->tenant?->name }}</span>
                        <div class="fs-8 text-muted mt-1">Tenant ID: {{ $shop->tenant?->id }}</div>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Slug & Marketplace URL</label>
                    <div class="col-lg-8">
                        <code>/marketplace/{{ $shop->slug }}</code>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Location & Physical Address</label>
                    <div class="col-lg-8">
                        <span class="fw-bold fs-6 text-gray-800">{{ $shop->address }}</span>
                        <div class="text-muted">{{ $shop->city }}, {{ $shop->region }}, Ghana</div>
                        @if($shop->latitude && $shop->longitude)
                            <div class="fs-8 text-muted mt-1">Coordinates: {{ $shop->latitude }}, {{ $shop->longitude }}</div>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Official Contact Details</label>
                    <div class="col-lg-8">
                        <div class="fw-bold text-gray-800">Phone: {{ $shop->phone }}</div>
                        <div class="fw-bold text-gray-800">Email: {{ $shop->email }}</div>
                    </div>
                </div>

                @if($shop->description)
                    <div class="row mb-7">
                        <label class="col-lg-4 fw-bold text-muted">Venue Description / Bio</label>
                        <div class="col-lg-8">
                            <p class="text-gray-700 fs-6 whitespace-pre-line">{{ $shop->description }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Active Venue Spaces --}}
        <div class="card mb-5">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="fw-bolder m-0">Spaces Listed by Host ({{ $shop->listings->count() }})</h3>
                </div>
            </div>
            <div class="card-body py-4">
                @forelse($shop->listings as $listing)
                    <div class="d-flex align-items-center border border-dashed border-gray-300 rounded p-4 mb-4">
                        <div class="symbol symbol-60px me-4">
                            @if($listing->primaryMedia)
                                <img src="{{ $listing->primaryMedia->url }}" alt="" class="rounded" />
                            @else
                                <div class="symbol-label bg-light-primary text-primary fw-bold">Space</div>
                            @endif
                        </div>
                        <div class="flex-grow-1">
                            <h4 class="text-gray-800 fw-bold fs-6 mb-1">{{ $listing->title }}</h4>
                            <div class="fs-7 text-muted">
                                Rate: {{ number_format($listing->rental_price_pesewas / 100, 2) }} GHS ({{ $listing->pricing_model }}) |
                                Status: <span class="badge badge-light-{{ $listing->status === 'published' ? 'success' : 'secondary' }} fs-8">{{ $listing->status }}</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-muted text-center py-4">No spaces created yet by this host.</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Action & Verification Sidebar --}}
    <div class="col-lg-4">
        <div class="card card-flush mb-5">
            <div class="card-header pt-6">
                <div class="card-title">
                    <h3 class="fw-bolder">Verification Decision</h3>
                </div>
            </div>
            <div class="card-body">
                <div class="mb-5">
                    <label class="fw-bold text-muted fs-7">Current Status</label>
                    <div class="mt-1">
                        @if($shop->verification_status === 'verified')
                            <span class="badge badge-success fs-7">Verified Host</span>
                            <div class="fs-8 text-muted mt-1">Verified at {{ $shop->verified_at?->format('M d, Y H:i') }} by {{ $shop->verifiedBy?->name ?? 'Admin' }}</div>
                        @elseif($shop->verification_status === 'pending')
                            <span class="badge badge-warning fs-7">Pending Superadmin Review</span>
                        @elseif($shop->verification_status === 'rejected')
                            <span class="badge badge-danger fs-7">Rejected</span>
                            @if($shop->rejection_reason)
                                <div class="fs-8 text-danger mt-1">Reason: {{ $shop->rejection_reason }}</div>
                            @endif
                        @else
                            <span class="badge badge-secondary fs-7">Unverified</span>
                        @endif
                    </div>
                </div>

                {{-- Approve Action --}}
                <form action="{{ route('admin.marketplace-verifications.approve', $shop) }}" method="POST" class="mb-6">
                    @csrf
                    <button type="submit" class="btn btn-success w-100 mb-2" onclick="return confirm('Are you sure you want to verify this merchant?');">
                        Approve & Grant Verified Badge
                    </button>
                    <span class="fs-8 text-muted d-block text-center">Grants verified trust badge and search boost.</span>
                </form>

                <div class="separator separator-dashed my-5"></div>

                {{-- Reject Action --}}
                <form action="{{ route('admin.marketplace-verifications.reject', $shop) }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fs-7 fw-bold">Rejection Reason</label>
                        <textarea name="rejection_reason" rows="3" class="form-control form-control-sm form-control-solid" required placeholder="Specify why credentials were not accepted..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Are you sure you want to reject this verification request?');">
                        Reject Verification
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
