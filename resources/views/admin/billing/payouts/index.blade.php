@extends('layouts.admin.master')

@section('title', 'Payouts & refunds')

@php($money = fn (int $pesewas): string => 'GHS '.number_format($pesewas / 100, 2))

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

        <div class="row g-5 mb-6">
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <div class="text-muted fw-bold fs-7 text-uppercase">Need attention</div>
                <div class="fs-2hx fw-bolder {{ $attention->isNotEmpty() ? 'text-danger' : '' }}">{{ $attention->count() }}</div>
                <div class="text-muted fs-8">Waiting for a code, failed, or processing for over {{ $stuckAfterHours }} hours</div>
            </div></div></div>
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <div class="text-muted fw-bold fs-7 text-uppercase">Paid out, last 30 days</div>
                <div class="fs-2 fw-bolder">{{ $money($paid30) }}</div>
            </div></div></div>
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <div class="text-muted fw-bold fs-7 text-uppercase">Refunded, last 30 days</div>
                <div class="fs-2 fw-bolder">{{ $money($refunded30) }}</div>
            </div></div></div>
        </div>

        <div class="card mb-6">
            <div class="card-header border-0 pt-6">
                <div class="card-title flex-column">
                    <h3 class="fw-bolder mb-1">Need attention</h3>
                    <div class="text-muted fs-7">Payouts go through MiConvener's Paystack account, so Paystack sends the one-time code to us. Enter it here to release the payout.</div>
                </div>
            </div>
            <div class="card-body py-4">
                <table class="table align-middle table-row-dashed fs-7 gy-4">
                    <thead>
                        <tr class="text-muted fw-bolder text-uppercase">
                            <th>Organisation</th><th>Event</th><th class="text-end">Amount</th><th>Status</th><th>Since</th><th></th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700 fw-bold">
                        @forelse ($attention as $payout)
                            <tr>
                                <td>
                                    @if ($payout->tenant)
                                        <a href="{{ route('health.tenants.show', $payout->tenant->uuid) }}">{{ $payout->tenant->name }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $payout->event->name ?? '—' }}</td>
                                <td class="text-end">{{ $money((int) $payout->amount) }}</td>
                                <td>
                                    <span class="badge {{ $payout->status === 'failed' ? 'badge-light-danger' : 'badge-light-warning' }}">{{ str_replace('_', ' ', $payout->status) }}</span>
                                    @if ($payout->failure_reason)
                                        <div class="text-danger fs-8 mt-1">{{ \Illuminate\Support\Str::limit($payout->failure_reason, 140) }}</div>
                                    @endif
                                </td>
                                <td class="text-nowrap">{{ $payout->updated_at?->diffForHumans() }}</td>
                                <td class="text-end">
                                    @if ($payout->status === \App\Models\EventPayout::STATUS_AWAITING_OTP)
                                        <form method="POST" action="{{ route('admin.billing.payouts.release', $payout->id) }}" class="d-flex gap-2 justify-content-end">
                                            @csrf
                                            <input type="text" name="otp" inputmode="numeric" autocomplete="one-time-code" class="form-control form-control-sm form-control-solid w-100px" placeholder="Code" aria-label="One-time code for {{ $payout->event->name ?? 'this payout' }}">
                                            <button type="submit" class="btn btn-sm btn-primary">Release</button>
                                        </form>
                                    @elseif ($payout->status === \App\Models\EventPayout::STATUS_FAILED)
                                        <span class="text-muted fs-8">The organiser can send it again</span>
                                    @else
                                        <span class="text-muted fs-8">Check with Paystack</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-10">Nothing needs attention.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-5">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header border-0 pt-6"><h3 class="card-title fw-bolder">Recent payouts</h3></div>
                    <div class="card-body py-4">
                        <table class="table align-middle table-row-dashed fs-7 gy-3">
                            <tbody class="text-gray-700 fw-bold">
                                @forelse ($recent as $payout)
                                    <tr>
                                        <td>{{ $payout->tenant->name ?? '—' }}<div class="text-muted fs-8">{{ $payout->event->name ?? '' }}</div></td>
                                        <td class="text-end">{{ $money((int) $payout->amount) }}</td>
                                        <td>{{ str_replace('_', ' ', $payout->status) }}</td>
                                        <td class="text-nowrap text-muted">{{ ($payout->paid_at ?? $payout->created_at)?->format('j M Y') }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted py-10">No payouts yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header border-0 pt-6"><h3 class="card-title fw-bolder">Recent refunds</h3></div>
                    <div class="card-body py-4">
                        <table class="table align-middle table-row-dashed fs-7 gy-3">
                            <tbody class="text-gray-700 fw-bold">
                                @forelse ($refunds as $refund)
                                    <tr>
                                        <td>{{ $refund->event->name ?? '—' }}</td>
                                        <td class="text-end">{{ $money(abs((int) $refund->gross_amount)) }}</td>
                                        <td class="text-nowrap text-muted">{{ $refund->created_at?->format('j M Y') }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted py-10">No refunds yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
