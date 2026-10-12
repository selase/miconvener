@extends('layouts.admin.master')

@section('title', 'Finance overview')

@php($money = fn (int $minor, string $currency = 'GHS'): string => $currency.' '.number_format($minor / 100, 2))

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="row g-5 mb-6">
            @can('view platform finance')
                <div class="col-md-3"><div class="card h-100"><div class="card-body">
                    <div class="text-muted fw-bold fs-7 text-uppercase">Earned this month</div>
                    <div class="fs-2 fw-bolder">{{ $money((int) $earnings['total']) }}</div>
                    <div class="text-muted fs-8">Commission {{ $money((int) $earnings['commission_total']) }} · plans and add-ons {{ $money((int) $earnings['billing_total']) }}</div>
                    <a class="fs-8" href="{{ route('admin.billing.earnings.index') }}">Open earnings</a>
                </div></div></div>
                <div class="col-md-3"><div class="card h-100"><div class="card-body">
                    <div class="text-muted fw-bold fs-7 text-uppercase">Payouts waiting</div>
                    <div class="fs-2hx fw-bolder {{ ($payoutsAwaitingCode + $payoutsFailed) > 0 ? 'text-danger' : '' }}">{{ $payoutsAwaitingCode + $payoutsFailed }}</div>
                    <div class="text-muted fs-8">{{ $payoutsAwaitingCode }} need a one-time code · {{ $payoutsFailed }} failed · paid out in 30 days {{ $money($paid30) }}</div>
                    <a class="fs-8" href="{{ route('admin.billing.payouts.index') }}">Open payouts &amp; refunds</a>
                </div></div></div>
            @endcan
            @can('manage commission credits')
                <div class="col-md-3"><div class="card h-100"><div class="card-body">
                    <div class="text-muted fw-bold fs-7 text-uppercase">Commission credit owed</div>
                    @forelse ($creditsOwed as $row)
                        <div class="fs-2 fw-bolder">{{ $money((int) $row->total, $row->currency) }}</div>
                        <div class="text-muted fs-8">{{ $row->organisations }} organiser(s)</div>
                    @empty
                        <div class="fs-2 fw-bolder">None</div>
                    @endforelse
                    <a class="fs-8" href="{{ route('admin.billing.commission-credits.index') }}">Open commission credits</a>
                </div></div></div>
            @endcan
            @can('manage commission destinations')
                <div class="col-md-3"><div class="card h-100"><div class="card-body">
                    <div class="text-muted fw-bold fs-7 text-uppercase">Commission accounts</div>
                    <div class="fs-2hx fw-bolder {{ $commissionAccounts === 0 ? 'text-warning' : '' }}">{{ $commissionAccounts }}</div>
                    <div class="text-muted fs-8">{{ $organisersOnSplit }} organiser(s) on a verified split{{ $commissionAccounts === 0 ? ' · none set, so the split is not enforced' : '' }}</div>
                    <a class="fs-8" href="{{ route('admin.billing.commission-destinations.index') }}">Open commission destinations</a>
                </div></div></div>
            @endcan
        </div>
    </div>
</div>
@endsection
