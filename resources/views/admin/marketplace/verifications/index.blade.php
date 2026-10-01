@extends('layouts.admin.master')

@section('title', 'Marketplace Merchant Verifications')

@section('content')
<div class="row g-5">
    <div class="col-12">
        @if(session('success'))
            <div class="alert alert-success d-flex align-items-center p-5 mb-5">
                <span class="svg-icon svg-icon-2hx svg-icon-success me-4">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path opacity="0.3" d="M20.5543 4.37824L12.1798 2.02473C12.0626 1.99176 11.9376 1.99176 11.8203 2.02473L3.44572 4.37824C3.18118 4.45258 3 4.6807 3 4.93945V13.569C3 14.6914 3.48509 15.8404 4.4417 16.984C5.17231 17.8575 6.18314 18.7345 7.446 19.5909C9.56752 21.0295 11.5866 21.922 11.7779 21.9934C11.849 22.02 11.9243 22.0336 12 22.0336C12.0757 22.0336 12.151 22.02 12.2221 21.9934C12.4134 21.922 14.4325 21.0295 16.554 19.5909C17.8169 18.7345 18.8277 17.8575 19.5583 16.984C20.5149 15.8404 21 14.6914 21 13.569V4.93945C21 4.6807 20.8189 4.45258 20.5543 4.37824Z" fill="currentColor"/><path d="M10.5606 11.3042L8.23223 8.97583C7.84171 8.58531 7.20854 8.58531 6.81802 8.97583C6.42749 9.36635 6.42749 9.99952 6.81802 10.39L9.85355 13.4255C10.2441 13.8161 10.8772 13.8161 11.2678 13.4255L17.182 7.51132C17.5725 7.12079 17.5725 6.48763 17.182 6.0971C16.7915 5.70658 16.1583 5.70658 15.7678 6.0971L10.5606 11.3042Z" fill="currentColor"/></svg>
                </span>
                <div class="d-flex flex-column">
                    <h4 class="mb-1 text-success">Success</h4>
                    <span>{{ session('success') }}</span>
                </div>
            </div>
        @endif

        <div class="card mb-5">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="fw-bolder m-0">Venue Host Verification Queue</h3>
                </div>
                <div class="card-toolbar">
                    <div class="d-flex gap-2">
                        <a href="{{ route('admin.marketplace-verifications.index', ['status' => 'all']) }}"
                           class="btn btn-sm {{ $currentStatus === 'all' ? 'btn-primary' : 'btn-light' }}">
                            All
                        </a>
                        <a href="{{ route('admin.marketplace-verifications.index', ['status' => 'pending']) }}"
                           class="btn btn-sm {{ $currentStatus === 'pending' ? 'btn-warning' : 'btn-light' }}">
                            Pending Review
                        </a>
                        <a href="{{ route('admin.marketplace-verifications.index', ['status' => 'verified']) }}"
                           class="btn btn-sm {{ $currentStatus === 'verified' ? 'btn-success' : 'btn-light' }}">
                            Verified
                        </a>
                        <a href="{{ route('admin.marketplace-verifications.index', ['status' => 'unverified']) }}"
                           class="btn btn-sm {{ $currentStatus === 'unverified' ? 'btn-secondary' : 'btn-light' }}">
                            Unverified
                        </a>
                        <a href="{{ route('admin.marketplace-verifications.index', ['status' => 'rejected']) }}"
                           class="btn btn-sm {{ $currentStatus === 'rejected' ? 'btn-danger' : 'btn-light' }}">
                            Rejected
                        </a>
                    </div>
                </div>
            </div>

            <div class="card-body py-4">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-5">
                        <thead>
                            <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                <th>Merchant / Venue</th>
                                <th>Tenant Account</th>
                                <th>Location</th>
                                <th>Active Spaces</th>
                                <th>Verification Status</th>
                                <th>Submitted / Updated</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-bold">
                            @forelse($shops as $shop)
                                <tr>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <a href="{{ route('admin.marketplace-verifications.show', $shop) }}" class="text-gray-800 text-hover-primary mb-1">
                                                {{ $shop->name }}
                                            </a>
                                            <span class="fs-8 text-muted">{{ $shop->email }} | {{ $shop->phone }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-light-primary">{{ $shop->tenant?->name ?? 'N/A' }}</span>
                                        <div class="fs-8 text-muted">{{ $shop->tenant?->slug }}.{{ config('session.domain') }}</div>
                                    </td>
                                    <td>
                                        <span>{{ $shop->city }}, {{ $shop->region }}</span>
                                    </td>
                                    <td>
                                        <span class="badge badge-light-info">{{ $shop->listings_count }} spaces</span>
                                    </td>
                                    <td>
                                        @if($shop->verification_status === 'verified')
                                            <span class="badge badge-light-success">Verified</span>
                                        @elseif($shop->verification_status === 'pending')
                                            <span class="badge badge-light-warning">Pending Review</span>
                                        @elseif($shop->verification_status === 'rejected')
                                            <span class="badge badge-light-danger">Rejected</span>
                                        @else
                                            <span class="badge badge-light-secondary">Unverified</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="fs-7 text-muted">{{ $shop->updated_at->format('M d, Y H:i') }}</span>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.marketplace-verifications.show', $shop) }}" class="btn btn-sm btn-light btn-active-light-primary">
                                            Review & Action
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-8 text-muted">
                                        No venue merchants match the selected status filter.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $shops->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
