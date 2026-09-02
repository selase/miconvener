@extends('layouts.admin.master')

@section('title', 'Global Subscriptions')

@section('content')
<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <div class="d-flex align-items-center position-relative my-1">
                <span class="svg-icon svg-icon-1 position-absolute ms-6">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                        <rect opacity="0.5" x="17.0365" y="15.1223" width="8.15546" height="2" rx="1" transform="rotate(45 17.0365 15.1223)" fill="currentColor" />
                        <path d="M11 19C6.55556 19 3 15.4444 3 11C3 6.55556 6.55556 3 11 3C15.4444 3 19 6.55556 19 11C19 15.4444 15.4444 19 11 19ZM11 5C7.53333 5 5 7.53333 5 11C5 14.4667 7.53333 17 11 17C14.4667 17 17 14.4667 17 11C17 7.53333 14.4667 5 11 5Z" fill="currentColor" />
                    </svg>
                </span>
                <form action="{{ route('admin.billing.subscriptions.index') }}" method="GET">
                    <input type="text" name="search" class="form-control form-control-solid w-250px ps-14" placeholder="Search subscriptions" value="{{ request('search') }}" />
                </form>
            </div>
        </div>
         <div class="card-toolbar">
            <div class="d-flex justify-content-end" data-kt-user-table-toolbar="base">
                <form action="{{ route('admin.billing.subscriptions.index') }}" method="GET" class="d-flex gap-2">
                     <select name="status" class="form-select form-select-solid w-150px" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        <option value="expired" {{ request('status') === 'expired' ? 'selected' : '' }}>Expired</option>
                    </select>
                </form>
            </div>
        </div>
    </div>
    <div class="card-body py-4">
        <div class="table-responsive">
            <table class="table align-middle table-row-dashed fs-6 gy-5">
                <thead>
                    <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                        <th class="min-w-125px">Tenant</th>
                        <th class="min-w-125px">Plan</th>
                        <th class="min-w-125px">Amount</th>
                        <th class="min-w-125px">Frequency</th>
                        <th class="min-w-125px">Status</th>
                        <th class="min-w-125px">Next Billing</th>
                    </tr>
                </thead>
                <tbody class="text-gray-600 fw-bold">
                    @forelse ($subscriptions as $subscription)
                        <tr>
                            <td>
                                <div class="d-flex flex-column">
                                    @if($subscription->tenant)
                                        <a href="{{ route('tenants.show', $subscription->tenant->uuid) }}" class="text-gray-800 text-hover-primary mb-1">
                                            {{ $subscription->tenant->name }}
                                        </a>
                                        <span>{{ $subscription->tenant->email }}</span>
                                    @else
                                        <span class="text-muted">Unknown Tenant</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                {{ $subscription->plan ?? 'N/A' }}
                            </td>
                            <td>
                                {{ \App\Libraries\Helper::formatAmountWithCurrencySymbol($subscription->amount) }}
                            </td>
                             <td>
                                {{ ucfirst($subscription->frequency ?? 'month') }}
                            </td>
                            <td>
                                @if($subscription->status === 'active')
                                    <div class="badge badge-light-success fw-bolder">Active</div>
                                @else
                                    <div class="badge badge-light-secondary fw-bolder">{{ ucfirst($subscription->status) }}</div>
                                @endif
                            </td>
                            <td>
                                {{ $subscription->ends_at ? $subscription->ends_at->format('M d, Y') : 'Auto-renew' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center">No subscriptions found</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-end">
             {{ $subscriptions->links() }}
        </div>
    </div>
</div>
@endsection
