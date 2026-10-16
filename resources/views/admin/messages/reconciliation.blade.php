@extends('layouts.admin.master')
@section('title', 'Uncertain message outcomes')
@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
<div id="kt_content_container" class="container-xxl">
    <div class="card card-flush shadow-sm mb-7">
        <div class="card-header pt-6 border-0">
            <div class="card-title flex-column align-items-start">
                <h1 class="fw-boldest mb-2">Uncertain message outcomes</h1>
                <p class="text-muted fs-7 mb-0">Review held reservations against the provider account. Recording an outcome never resends a message.</p>
            </div>
            <div class="card-toolbar"><a href="{{ route('admin.messages.index') }}" class="btn btn-light">Message delivery</a></div>
        </div>
        <div class="card-body">
            <div class="alert alert-warning d-flex align-items-center p-5">
                <div>Keep credits held until the outcome is known. <strong>Undelivered does not mean unbilled.</strong> Refund only with evidence that submission did not occur. Do not include API keys, passwords or full private message content in evidence.</div>
            </div>
            @if($errors->any())<div role="alert" class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @if(session('success'))<p role="status" class="alert alert-success">{{ session('success') }}</p>@endif
            <form method="get" class="row g-4 align-items-end mb-7">
                <div class="col-md-4"><label for="source-kind" class="form-label fw-bold">Source</label><select name="source_kind" id="source-kind" class="form-select form-select-solid">
                    @foreach(['sms_reservation'=>'SMS reservations','community_email'=>'Community email','event_email'=>'Event announcement email','legacy_reminder'=>'Earlier dues SMS reminders'] as $value=>$label)
                    <option value="{{ $value }}" @selected($kind===$value)>{{ $label }}</option>
                    @endforeach
                </select></div>
                <div class="col-md-5"><label for="organisation-filter" class="form-label fw-bold">Organisation</label><select name="tenant_id" id="organisation-filter" class="form-select form-select-solid"><option value="">All organisations</option>@foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected($tenantId===$tenant->id)>{{ $tenant->name }}</option>@endforeach</select></div>
                <div class="col-md-3"><button class="btn btn-primary" type="submit">Apply filters</button></div>
            </form>
            @forelse($rows as $row)
                <div class="border rounded p-5 mb-5">
                    <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
                        <div><strong class="d-block text-break">{{ $row->id }}</strong><span class="text-muted fs-7">{{ $tenants->firstWhere('id',$row->tenant_id)?->name ?? 'Organisation unavailable' }}</span></div>
                        <div class="text-muted fs-7">{{ $row->created_at?->utc()->format('d M Y H:i') }} UTC · {{ $row->status }}</div>
                    </div>
                    <p class="fs-7 mb-1 text-break">{{ $row->review_context['source'] }} · {{ $row->review_context['destination'] }}</p>
                    <p class="text-muted fs-7">Provider reference: {{ $row->getAttribute('provider_reference') ?? 'Not recorded' }} · {{ str_replace('_',' ',$row->reason ?? 'Interrupted submission') }}</p>
                    <details><summary class="fw-bold mb-4">Review outcome</summary>
                        <form method="post" action="{{ route('admin.messages.reconciliation.resolve') }}" class="row g-4">
                            @csrf<input type="hidden" name="tenant_id" value="{{ $row->tenant_id }}"><input type="hidden" name="source_kind" value="{{ $kind }}"><input type="hidden" name="source_id" value="{{ $row->id }}">
                            <div class="col-12"><label for="outcome-{{ $row->id }}" class="form-label fw-bold required">Confirmed outcome</label><select id="outcome-{{ $row->id }}" name="outcome" required class="form-select form-select-solid"><option value="">Choose from verified evidence</option><option value="confirmed_accepted">Provider accepted it — keep credits spent</option><option value="confirmed_not_submitted">Submission did not occur — release reserved credits</option></select></div>
                            <div class="col-md-6"><label for="evidence-{{ $row->id }}" class="form-label fw-bold required">Provider evidence</label><textarea id="evidence-{{ $row->id }}" name="evidence" minlength="10" maxlength="2000" rows="3" required class="form-control form-control-solid" placeholder="Provider reference, support ticket or verified submission record"></textarea></div>
                            <div class="col-md-6"><label for="reason-{{ $row->id }}" class="form-label fw-bold required">Reason for this decision</label><textarea id="reason-{{ $row->id }}" name="reason" minlength="10" maxlength="1000" rows="3" required class="form-control form-control-solid"></textarea></div>
                            <div class="col-12"><label class="form-check form-check-solid"><input class="form-check-input" type="checkbox" name="confirm" value="1" required><span class="form-check-label">I checked the evidence against this submission and understand that no resend occurs.</span></label></div>
                            <div class="col-12"><button type="submit" class="btn btn-primary">Record verified outcome</button></div>
                        </form>
                    </details>
                </div>
            @empty
                <div class="text-muted py-8">No uncertain outcomes match these filters. Choose another source or organisation to review other reservations.</div>
            @endforelse
            {{ $rows->links() }}
        </div>
    </div>
    <div class="card card-flush shadow-sm"><div class="card-header pt-5"><h2 class="card-title fw-bold">Recent decisions</h2></div><div class="card-body">
        @forelse($recent as $audit)
            <div class="border-bottom py-4"><strong>{{ str_replace('_',' ',$audit->outcome) }}</strong> · Operator {{ $audit->operator_reference }} · {{ $audit->created_at->utc()->format('d M Y H:i') }} UTC<p class="fs-7 text-muted text-break mb-1">{{ $audit->source_kind }} / {{ $audit->source_id }}</p><p class="mb-1">{{ $audit->reason }}</p><p class="fs-7 mb-0">Evidence: {{ $audit->evidence }}</p></div>
        @empty<p class="text-muted mb-0">No recorded decisions yet.</p>@endforelse
    </div></div>
</div>
</div>
@endsection
