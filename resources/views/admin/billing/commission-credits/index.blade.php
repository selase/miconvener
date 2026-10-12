@extends('layouts.admin.master')

@section('title', 'Commission credits')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        @if (session('success'))
            <div class="alert alert-success p-5 mb-5">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger p-5 mb-5">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger p-5 mb-5">{{ $errors->first() }}</div>
        @endif

        <div class="card mb-6">
            <div class="card-header border-0 pt-6">
                <div class="card-title flex-column">
                    <h3 class="fw-bolder mb-1">Commission credits</h3>
                    <div class="text-muted fs-7">
                        When an organiser on their own Paystack account refunds a ticket, our commission is not returned with it, so we owe it back as credit. Their next sales use it up automatically.
                        Whatever is left, for instance after a cancelled event, is paid out here: <strong>send the money first</strong> (bank or mobile-money transfer), then record it so the credit and the books match.
                    </div>
                </div>
            </div>
            <div class="card-body py-4">
                <table class="table align-middle table-row-dashed fs-7 gy-4">
                    <thead>
                        <tr class="text-muted fw-bolder text-uppercase">
                            <th>Organisation</th><th>Currency</th><th class="text-end">Owed</th><th>Updated</th><th class="text-end">Record a payout</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700 fw-bold">
                        @forelse ($credits as $credit)
                            @php($tenant = $tenants[$credit->tenant_id])
                            <tr>
                                <td><a href="{{ route('health.tenants.show', $tenant->uuid) }}">{{ $tenant->name }}</a></td>
                                <td>{{ $credit->currency }}</td>
                                <td class="text-end">{{ number_format($credit->amount / 100, 2) }}</td>
                                <td class="text-nowrap">{{ $credit->updated_at?->diffForHumans() }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('admin.billing.commission-credits.settle', $tenant->uuid) }}" class="d-flex gap-2 justify-content-end">
                                        @csrf
                                        <input type="hidden" name="currency" value="{{ $credit->currency }}">
                                        <input type="number" name="amount" step="0.01" min="0.01" max="{{ number_format($credit->amount / 100, 2, '.', '') }}" value="{{ number_format($credit->amount / 100, 2, '.', '') }}" class="form-control form-control-sm form-control-solid w-125px" aria-label="Amount paid to {{ $tenant->name }} in {{ $credit->currency }}">
                                        <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Record this as paid to {{ e($tenant->name) }}? Only do this after the money has been sent.')">Record payout</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted text-center py-10">No organiser is owed commission credit.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
