@extends('layouts.admin.master')
@section('title', 'Commerce agreement')
@section('content')
<div class="container-xxl">
    <h1 class="mb-4">Commerce agreement · {{ $seller->name }}</h1>
    <p class="text-muted">GHS · Platform-default Paystack. Merchandise and venue bookings each have their own terms: approving one never changes the other. Approving terms does not enable checkout or venue payments. Existing quotes keep their approved version.</p>
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    <div class="card mb-6"><div class="card-body">
        <h2 class="mb-5">Approve a new version</h2>
        <form method="POST" action="{{ route('admin.store-agreements.store', $seller) }}">
            @csrf
            <div class="row g-5">
                <div class="col-md-12"><label class="form-label" for="offering_scope">These terms are for</label><select class="form-select" id="offering_scope" name="offering_scope" required><option value="purchase" @selected(old('offering_scope', 'purchase') === 'purchase')>Merchandise (store checkout)</option><option value="reservation" @selected(old('offering_scope') === 'reservation')>Venue bookings (deposit, balance and security payments; the commission is always taken from the venue's side)</option></select></div>
                @foreach(['percentage' => 'Commission percentage (0 is an explicit waiver)', 'cap_pesewas' => 'Commission cap (minor units; blank for uncapped)', 'gateway_estimate_rate' => 'Gateway estimate percentage'] as $field => $label)
                <div class="col-md-4"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-control" id="{{ $field }}" name="{{ $field }}" type="number" min="0" step="{{ $field === 'cap_pesewas' ? '1' : '0.01' }}" value="{{ old($field) }}" @required($field !== 'cap_pesewas')></div>
                @endforeach
                <div class="col-md-6"><label class="form-label" for="fee_bearer">Commission bearer</label><select class="form-select" id="fee_bearer" name="fee_bearer" required><option value="organizer" @selected(old('fee_bearer') === 'organizer')>Seller</option><option value="attendee" @selected(old('fee_bearer') === 'attendee')>Buyer</option></select></div>
                <div class="col-md-6"><label class="form-label" for="refund_gateway_fee_bearer">Who funds processing fees on refunds?</label><select class="form-select" id="refund_gateway_fee_bearer" name="refund_gateway_fee_bearer" required><option value="">Choose explicitly</option><option value="seller" @selected(old('refund_gateway_fee_bearer') === 'seller')>Seller</option><option value="platform" @selected(old('refund_gateway_fee_bearer') === 'platform')>Platform</option></select></div>
                @foreach(['collection_terms' => 'Collection terms', 'refund_terms' => 'Refund and cancellation terms', 'support_contact' => 'Support contact and responsibilities', 'receipt_terms' => 'Receipt and tax presentation'] as $field => $label)
                <div class="col-md-6"><label class="form-label" for="{{ $field }}">{{ $label }}</label><textarea class="form-control" rows="3" id="{{ $field }}" name="{{ $field }}" required>{{ old($field) }}</textarea></div>
                @endforeach
                <div class="col-md-6"><label class="form-label" for="holdback">Required seller reserve (minor units)</label><input id="holdback" class="form-control" name="payout_policy[holdback_pesewas]" type="number" min="0" step="1" required value="{{ old('payout_policy.holdback_pesewas') }}"></div>
                <div class="col-md-6"><label class="form-check mt-8"><input class="form-check-input" type="checkbox" name="payout_policy[manual_release]" value="1" required @checked(old('payout_policy.manual_release'))><span class="form-check-label">I approve manual payout release and the responsibilities above.</span></label></div>
            </div>
            <button class="btn btn-primary mt-6" type="submit">Approve new agreement version</button>
        </form>
    </div></div>
    <h2>Approved versions: merchandise</h2>
    @forelse($agreements as $agreement)
    <article class="card mb-4"><div class="card-body"><h3>Version {{ $agreement->version }}</h3><p>Approved {{ $agreement->approved_at->format('j M Y H:i') }} UTC · {{ $agreement->percentage }}% · {{ $agreement->fee_bearer === 'organizer' ? 'Seller' : 'Buyer' }} commission · Refund fees: {{ $agreement->refund_gateway_fee_bearer }}</p><p>{{ $agreement->collection_terms }}</p><p>{{ $agreement->refund_terms }}</p><p>{{ $agreement->support_contact }}</p><p>{{ $agreement->receipt_terms }}</p></div></article>
    @empty<p class="text-muted">No approved agreement. Checkout remains unavailable.</p>@endforelse
    <h2 class="mt-8">Approved versions: venue bookings</h2>
    @forelse($reservationAgreements as $agreement)
    <article class="card mb-4"><div class="card-body"><h3>Version {{ $agreement->version }}</h3><p>Approved {{ $agreement->approved_at->format('j M Y H:i') }} UTC · {{ $agreement->percentage }}% · Refund fees: {{ $agreement->refund_gateway_fee_bearer }} · Reserve: GHS {{ number_format(($agreement->payout_policy['holdback_pesewas'] ?? 0) / 100, 2) }}</p><p>{{ $agreement->collection_terms }}</p><p>{{ $agreement->refund_terms }}</p><p>{{ $agreement->support_contact }}</p><p>{{ $agreement->receipt_terms }}</p></div></article>
    @empty<p class="text-muted">No approved venue agreement. Venue payments cannot be enabled for this organisation.</p>@endforelse
</div>
@endsection
