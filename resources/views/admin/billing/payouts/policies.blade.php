@extends('layouts.admin.master')
@section('title', 'Payout policy approvals')
@section('content')
<div class="container-xxl">
    <div class="alert alert-info">Approval permits scheduled proposals after a 24-hour cooling period. Every payout still needs human submission and the Paystack owner code. Approval never changes commission or enables unattended transfers.</div>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="card card-flush shadow-sm mb-6"><div class="card-body">
        <h2 class="fs-4">Provider and ledger reconciliation</h2>
        <p>The provider account may also contain platform billing or other applications’ funds. Identify these separately using its statement; they never become an organisation’s entitlement. A discrepancy or unresolved transfer prevents certification.</p>
        <form method="POST" action="{{ route('admin.payout-policies.audit') }}">@csrf
            <label for="external-cash" class="form-label">Separately reconciled external cash (pesewas; enter 0 if none)</label><input id="external-cash" name="external_cash" type="number" min="0" required class="form-control mb-3">
            <label for="statement" class="form-label">Provider statement reference (needed to certify)</label><input id="statement" name="statement" maxlength="200" class="form-control mb-3">
            <label for="review-note" class="form-label">Reconciliation explanation (needed to certify)</label><textarea id="review-note" name="note" maxlength="1000" class="form-control mb-3"></textarea>
            <div class="d-flex flex-wrap gap-3"><button name="action" value="preview" class="btn btn-light" type="submit">Read-only check</button><button name="action" value="record" class="btn btn-primary" type="submit">Recheck and certify matched evidence</button></div>
        </form>
    </div></div>
    <div class="row g-5">
        @forelse($policies as $policy)
        <div class="col-12 col-xl-6"><div class="card card-flush shadow-sm"><div class="card-body">
            <h2 class="fs-4">{{ $tenants[$policy->tenant_id] ?? 'Organisation' }} · {{ ucfirst($policy->category) }}</h2>
            <p>Version {{ $policy->version }} · {{ $policy->status }} · {{ $policy->cadence }} {{ $policy->local_time }} {{ $policy->timezone }}</p>
            @foreach(['minimum_amount'=>'Minimum', 'maximum_amount'=>'Maximum per transfer', 'daily_limit'=>'Daily limit', 'rolling_limit'=>'Rolling 24-hour limit', 'reserve_amount'=>'Reserve'] as $field=>$label)
                <p>{{ $label }}: GHS {{ number_format($policy->$field / 100, 2) }}</p>
            @endforeach
            <p>Verified destination: {{ $policy->destination_display }}</p>
            <p>Requester #{{ $policy->requested_by }} · approver {{ $policy->approved_by ?? 'pending' }}</p>
            @if($policy->status === 'pending' && (int)$policy->requested_by !== (int)auth()->id())
                <form method="POST" action="{{ route('admin.payout-policies.approve', $policy->id) }}">@csrf<button class="btn btn-primary" type="submit">Approve this exact policy</button></form>
            @endif
            @if($policy->status !== 'paused')<form class="mt-3" method="POST" action="{{ route('admin.payout-policies.pause', $policy->id) }}">@csrf<button class="btn btn-light" type="submit">Pause policy</button></form>@endif
        </div></div></div>
        @empty<div class="col-12"><div class="card card-flush shadow-sm"><div class="card-body">No organisation has requested a payout schedule. Existing payouts remain manual.</div></div></div>
        @endforelse
    </div>
    <div class="mt-5">{{ $policies->links() }}</div>
</div>
@endsection
