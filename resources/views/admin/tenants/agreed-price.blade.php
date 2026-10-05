@extends('layouts.admin.master')

@section('title', 'Agreed price — ' . $tenant->name)

@section('content')
<div class="row g-5">
    <div class="col-lg-8">
        @if (session('success'))
            <div class="alert alert-success p-5 mb-5">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger p-5 mb-5">{{ session('error') }}</div>
        @endif

        <div class="card mb-5">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="fw-bolder m-0">{{ $tenant->name }}: agreed Enterprise price</h3>
                </div>
                <div class="card-toolbar">
                    <a href="{{ route('tenants.show', $tenant->uuid) }}" class="btn btn-sm btn-light">Back to organisation</a>
                </div>
            </div>

            <div class="card-body py-4">
                <p class="text-muted fs-7 mb-7">
                    Enterprise has no list price. The price you set here is what checkout and every renewal charge,
                    it shows on the organisation's Billing page, and the organisation is emailed when you set or change it.
                    Complimentary organisations are never charged, whatever the price.
                </p>

                @unless ($isEnterprise)
                    <div class="alert alert-warning mb-7">
                        {{ $tenant->name }} is on {{ $tenant->package?->name ?? 'no plan' }}. Move it to Enterprise (Edit) before setting a price.
                    </div>
                @endunless

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Now</label>
                    <div class="col-lg-8">
                        @if ($tenant->agreed_price_pesewas)
                            <span class="fw-bolder fs-6">GHS {{ number_format($tenant->agreed_price_pesewas / 100, 2) }} {{ $tenant->agreed_price_interval === 'year' ? 'a year' : 'a month' }}</span>
                        @else
                            <span class="text-muted">No price set. The organisation cannot pay for Enterprise yet.</span>
                        @endif
                        @if ($tenant->billing_complimentary)
                            <div class="fs-8 text-muted mt-1">Complimentary: not charged.</div>
                        @elseif ($subscription)
                            <div class="fs-8 text-muted mt-1">Paying. Next renewal {{ $subscription->current_period_end?->format('j F Y') ?? 'date not set' }}, charged at the agreed price.</div>
                        @elseif ($tenant->agreed_price_pesewas)
                            <div class="fs-8 text-muted mt-1">Not paid yet. Their Billing page offers to pay the first period.</div>
                        @endif
                    </div>
                </div>

                @if ($isEnterprise)
                    <form method="POST" action="{{ route('tenants.agreed-price.update', $tenant->uuid) }}">
                        @csrf
                        @method('PUT')
                        <div class="row mb-5">
                            <label class="col-lg-4 fw-bold text-muted" for="price">Price (GHS)</label>
                            <div class="col-lg-8">
                                <input id="price" name="price" type="number" step="0.01" min="1" class="form-control form-control-solid" placeholder="2500"
                                       value="{{ old('price', $tenant->agreed_price_pesewas ? $tenant->agreed_price_pesewas / 100 : '') }}">
                                <div class="fs-8 text-muted mt-1">Leave empty to remove the price.</div>
                                @error('price') <div class="text-danger fs-7 mt-1">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="row mb-5">
                            <label class="col-lg-4 fw-bold text-muted" for="interval">Billed</label>
                            <div class="col-lg-8">
                                <select id="interval" name="interval" class="form-select form-select-solid">
                                    <option value="month" @selected(old('interval', $tenant->agreed_price_interval) !== 'year')>Monthly</option>
                                    <option value="year" @selected(old('interval', $tenant->agreed_price_interval) === 'year')>Yearly</option>
                                </select>
                                @error('interval') <div class="text-danger fs-7 mt-1">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Save and email the organisation</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
