@extends('layouts.admin.master')

@section('title', 'Plan Renewals')

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
                    <input type="text" name="search" class="form-control form-control-solid w-250px ps-14" placeholder="Search organizations" value="{{ request('search') }}" />
                </form>
            </div>
        </div>
        <div class="card-toolbar">
            <div class="d-flex justify-content-end" data-kt-user-table-toolbar="base">
                <form action="{{ route('admin.billing.subscriptions.index') }}" method="GET" class="d-flex gap-2">
                    <select name="status" class="form-select form-select-solid w-200px" onchange="this.form.submit()">
                        <option value="">All plans</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Renewing normally</option>
                        <option value="past_due" {{ request('status') === 'past_due' ? 'selected' : '' }}>Past due</option>
                        <option value="complimentary" {{ request('status') === 'complimentary' ? 'selected' : '' }}>Complimentary</option>
                        <option value="free" {{ request('status') === 'free' ? 'selected' : '' }}>Free plan</option>
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
                        <th class="min-w-175px">Organization</th>
                        <th class="min-w-100px">Plan</th>
                        <th class="min-w-150px">Status</th>
                        <th class="min-w-125px">Renews / ends</th>
                        <th class="min-w-150px">Payment method</th>
                        <th class="min-w-100px text-end">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-gray-600 fw-bold">
                    @forelse ($rows as $row)
                        @php($tenant = $row['tenant'])
                        <tr>
                            <td>
                                <div class="d-flex flex-column">
                                    <a href="{{ route('tenants.show', $tenant->uuid) }}" class="text-gray-800 text-hover-primary mb-1">
                                        {{ $tenant->name }}
                                    </a>
                                    <span>{{ $tenant->email }}</span>
                                </div>
                            </td>
                            <td>{{ $row['plan_name'] }}</td>
                            <td>
                                @if ($row['complimentary'])
                                    <div class="badge badge-light-info fw-bolder">Complimentary</div>
                                @elseif ($row['is_free'])
                                    <div class="badge badge-light-secondary fw-bolder">Free plan</div>
                                @elseif ($row['status'] === 'past_due')
                                    <div class="badge badge-light-danger fw-bolder">Past due</div>
                                    @if ($row['grace_ends_on'])
                                        <div class="text-muted fs-8 mt-1">Grace ends {{ $row['grace_ends_on'] }}</div>
                                    @endif
                                @elseif ($row['status'] === 'active')
                                    <div class="badge badge-light-success fw-bolder">Renewing normally</div>
                                @else
                                    <div class="badge badge-light-secondary fw-bolder">No plan payment</div>
                                @endif
                            </td>
                            <td>
                                {{ $row['renews_or_ends_on'] ?? '—' }}
                                @if ($row['renew_amount'])
                                    <div class="text-muted fs-8 mt-1">{{ $row['renew_amount'] }}</div>
                                @endif
                            </td>
                            <td>{{ $row['payment_method'] ?? ($row['is_free'] || $row['complimentary'] ? '—' : 'Payment link') }}</td>
                            <td class="text-end">
                                @unless ($row['is_free'])
                                    <form action="{{ route('admin.billing.subscriptions.toggle-complimentary', $tenant->uuid) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-light-{{ $row['complimentary'] ? 'warning' : 'info' }}"
                                            onclick="return confirm('{{ $row['complimentary'] ? "Bill {$tenant->name} again? Their plan will renew normally." : "Make {$tenant->name}'s plan complimentary? It will never renew or lapse." }}')">
                                            {{ $row['complimentary'] ? 'Bill again' : 'Make complimentary' }}
                                        </button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center">No organizations found</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-end">
            {{ $tenants->links() }}
        </div>
    </div>
</div>
@endsection
