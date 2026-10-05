@extends('layouts.admin.master')

@section('title', 'Earnings')

@php($money = fn (int $pesewas): string => 'GHS '.number_format($pesewas / 100, 2))

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="d-flex flex-wrap flex-stack mb-6">
            <div>
                <h1 class="fw-bolder mb-1">What MiConvener earned</h1>
                <div class="text-muted fs-7">{{ $from->format('j M Y') }} to {{ $to->format('j M Y') }}</div>
            </div>
            <form method="GET" action="{{ route('admin.billing.earnings.index') }}">
                <select name="period" class="form-select form-select-solid w-200px" onchange="this.form.submit()" aria-label="Period">
                    @foreach ($periods as $value => $label)
                        <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        <div class="row g-5 mb-6">
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <div class="text-muted fw-bold fs-7 text-uppercase">Total</div>
                    <div class="fs-2hx fw-bolder text-gray-900" data-earnings-total>{{ $money($earnings['total']) }}</div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <div class="text-muted fw-bold fs-7 text-uppercase">Commission and fees</div>
                    <div class="fs-2 fw-bolder text-gray-900">{{ $money($earnings['commission_total']) }}</div>
                    <div class="text-muted fs-8 mt-1">Tickets, contributions and the marketplace, net of refunds</div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <div class="text-muted fw-bold fs-7 text-uppercase">Plans and add-ons</div>
                    <div class="fs-2 fw-bolder text-gray-900">{{ $money($earnings['billing_total']) }}</div>
                    <div class="text-muted fs-8 mt-1">What organisations paid MiConvener directly</div>
                </div></div>
            </div>
        </div>

        @if ($earnings['other_currencies'] > 0)
            <div class="alert alert-warning mb-6">
                {{ $earnings['other_currencies'] }} payment(s) in other currencies are not included in these cedi totals.
            </div>
        @endif

        <div class="row g-5">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header border-0 pt-6"><h3 class="card-title fw-bolder">Where it came from</h3></div>
                    <div class="card-body py-4">
                        <table class="table align-middle table-row-dashed fs-6 gy-4">
                            <thead>
                                <tr class="text-start text-muted fw-bolder fs-7 text-uppercase">
                                    <th>Source</th><th>Item</th><th class="text-end">Count</th><th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-700 fw-bold">
                                @forelse (collect($earnings['lines'])->sortByDesc('amount') as $line)
                                    <tr>
                                        <td><span class="badge badge-light{{ $line['source'] === 'Commission' ? '-primary' : '-success' }}">{{ $line['source'] }}</span></td>
                                        <td>{{ $line['label'] }}</td>
                                        <td class="text-end">{{ number_format($line['count']) }}</td>
                                        <td class="text-end {{ $line['amount'] < 0 ? 'text-danger' : '' }}">{{ $money($line['amount']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted py-10">Nothing earned in this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header border-0 pt-6"><h3 class="card-title fw-bolder">Top organisations</h3></div>
                    <div class="card-body py-4">
                        <table class="table align-middle table-row-dashed fs-6 gy-4">
                            <tbody class="text-gray-700 fw-bold">
                                @forelse ($earnings['by_tenant'] as $row)
                                    <tr>
                                        <td>{{ $row['name'] }}</td>
                                        <td class="text-end">{{ $money($row['amount']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted py-10">No organisation paid anything in this period.</td></tr>
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
