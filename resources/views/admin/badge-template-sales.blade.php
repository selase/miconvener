@extends('layouts.admin.master')
@section('title', 'Badge template sales')
@section('content')
<div class="container-xxl">
    <h1 class="mb-4">Badge template sales</h1>
    <p class="text-muted">GH₵50 event licences. Refunds use the protected platform reserve. Verify uncertain payments before acting.</p>
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    @forelse($purchases as $purchase)
    @php
        $currentLicence = $purchase->licence->current_purchase_id === $purchase->id;
        $licenceState = $currentLicence ? $purchase->licence->state : ($purchase->state === 'paid' ? 'superseded' : $purchase->state);
        $used = $purchase->uses_exists || ($currentLicence && $purchase->licence->first_used_at !== null);
    @endphp
    <section class="card mb-5"><div class="card-body">
        <h2 class="fs-4">{{ $purchase->event->name }}</h2>
        <p>{{ $purchase->terms_snapshot['design_name'] ?? 'Badge template' }} · {{ $purchase->state }} · Licence {{ $licenceState }}</p>
        <p class="text-muted">{{ $used ? 'Clean output requested' : 'No clean output requested' }} · Purchase {{ $purchase->id }}</p>
        @foreach($purchase->order->attempts as $attempt)
        <div class="border-top pt-4 mt-4">
            <p>Payment {{ $attempt->reference }} · {{ $attempt->status }}</p>
            <form method="POST" action="{{ route('admin.badge-template-sales.update', $purchase->id) }}" class="mb-4">
                @csrf<input type="hidden" name="action" value="verify"><input type="hidden" name="attempt_id" value="{{ $attempt->id }}"><input type="hidden" name="operation_key" value="{{ Str::uuid() }}"><input type="hidden" name="reason" value="Finance verification">
                <button class="btn btn-sm btn-light-primary">Verify original payment</button>
            </form>
            @foreach($attempt->observations as $capture)
            <p>{{ $capture->processing_state }} · {{ $capture->currency }} {{ number_format(($capture->amount_pesewas ?? 0) / 100, 2) }} · {{ $capture->review_reason }}</p>
            @if($capture->ledger_transaction_id && !$purchase->order->refunds->contains('attempt_id', $attempt->id))
            <form method="POST" action="{{ route('admin.badge-template-sales.update', $purchase->id) }}" class="d-grid gap-3 mb-4">
                @csrf<input type="hidden" name="action" value="refund"><input type="hidden" name="capture_id" value="{{ $capture->id }}"><input type="hidden" name="operation_key" value="{{ Str::uuid() }}">
                <label>Reason for full refund<input class="form-control" name="reason" required maxlength="500"></label>
                <label class="form-check"><input class="form-check-input" type="checkbox" name="exception" value="1"><span class="form-check-label">Approve a support exception for a used or older licence. This is audited.</span></label>
                <button class="btn btn-sm btn-light-danger">Approve full refund</button>
            </form>
            @endif
            @endforeach
        </div>
        @endforeach
        @foreach($purchase->order->refunds as $refund)
        <div class="border-top pt-4"><p>Refund {{ $refund->status }} · {{ $refund->provider_status }} · {{ $refund->review_reason }}</p>
            @if($refund->status !== 'confirmed')
            <form method="POST" action="{{ route('admin.badge-template-sales.update', $purchase->id) }}" class="d-grid gap-3">
                @csrf<input type="hidden" name="refund_id" value="{{ $refund->id }}"><input type="hidden" name="operation_key" value="{{ Str::uuid() }}">
                <label>Review reason<input class="form-control" name="reason" required maxlength="500"></label>
                <button name="action" value="refresh" class="btn btn-sm btn-light-primary">Refresh refund status</button>
                @if($refund->provider_status === 'failed' && $purchase->licence->state === 'suspended')
                <button name="action" value="restore" class="btn btn-sm btn-light-warning">Verify failure and restore licence</button>
                @endif
            </form>
            @endif
        </div>
        @endforeach
    </div></section>
    @empty<p>No paid template purchases.</p>@endforelse
    {{ $purchases->links() }}
</div>
@endsection
