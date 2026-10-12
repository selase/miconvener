@extends('layouts.admin.master')

@section('title', $tenant->name)

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        @if (session('success'))
            <div class="alert alert-success p-5 mb-5">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger p-5 mb-5">{{ $errors->first() }}</div>
        @endif

        <div class="row g-5">
            <div class="col-lg-5">
                <div class="card mb-6"><div class="card-body">
                    <h3 class="fw-bolder mb-1">{{ $tenant->name }}</h3>
                    <div class="mb-4">
                        @if ($tenant->onboarding_completed_at)
                            <span class="badge badge-light-success">Onboarded {{ $tenant->onboarding_completed_at->format('j M Y') }}</span>
                        @else
                            <span class="badge badge-light-warning">Signed up, not onboarded</span>
                        @endif
                    </div>
                    <dl class="row fs-7 mb-0">
                        <dt class="col-5 text-muted">Email</dt><dd class="col-7">{{ $tenant->email ?: '—' }}</dd>
                        <dt class="col-5 text-muted">Phone</dt><dd class="col-7">{{ $tenant->phone_number ?: '—' }}</dd>
                        <dt class="col-5 text-muted">Plan</dt><dd class="col-7">{{ $tenant->package?->name ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Country</dt><dd class="col-7">{{ $tenant->country ?: '—' }}</dd>
                        <dt class="col-5 text-muted">Signed up</dt><dd class="col-7">{{ $tenant->created_at?->format('j M Y, H:i') }}</dd>
                    </dl>
                    @can('access-superadmin-dashboard')
                        <a class="btn btn-sm btn-light mt-4" href="{{ route('health.tenants.show', $tenant->uuid) }}">Customer view</a>
                    @endcan
                </div></div>

                @can('follow up signups')
                    <div class="card mb-6"><div class="card-body">
                        <h4 class="fw-bolder mb-3">Log a follow-up</h4>
                        <form method="POST" action="{{ route('admin.signups.follow-up', $tenant->uuid) }}" class="d-flex flex-column gap-3">
                            @csrf
                            <select name="status" class="form-select form-select-solid" aria-label="Outcome" required>
                                @foreach ($statuses as $key => $label)
                                    <option value="{{ $key }}" @selected(old('status') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <textarea name="note" rows="3" maxlength="1000" class="form-control form-control-solid" placeholder="What was said, what happens next" aria-label="Note">{{ old('note') }}</textarea>
                            <button type="submit" class="btn btn-primary align-self-end">Log follow-up</button>
                        </form>
                    </div></div>
                @endcan
            </div>

            <div class="col-lg-7">
                <div class="card mb-6">
                    <div class="card-header border-0 pt-6"><div class="card-title"><h3 class="fw-bolder">Follow-up history</h3></div></div>
                    <div class="card-body py-4">
                        @forelse ($followUps as $followUp)
                            <div class="border-bottom pb-4 mb-4">
                                <div class="d-flex justify-content-between">
                                    <span class="badge badge-light">{{ $followUp->label() }}</span>
                                    <span class="text-muted fs-8">{{ $followUp->author?->first_name ?? 'Someone' }} · {{ $followUp->created_at?->format('j M Y, H:i') }}</span>
                                </div>
                                @if ($followUp->note)
                                    <div class="mt-2 fs-7">{{ $followUp->note }}</div>
                                @endif
                            </div>
                        @empty
                            <div class="text-muted text-center py-10">Nobody has followed up with this organisation yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
