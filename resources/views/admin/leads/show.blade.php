@extends('layouts.admin.master')

@section('title', 'View Lead - ' . $lead->name)

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="card mb-5 mb-xl-10">
            <div class="card-header border-0">
                <div class="card-title m-0">
                    <h3 class="fw-bolder m-0">Lead Details</h3>
                </div>
                <div class="card-toolbar">
                    <a href="{{ route('admin.leads.index') }}" class="btn btn-light-primary btn-sm">Back to List</a>
                </div>
            </div>
            <div class="card-body border-top p-9">
                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Full Name</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-800">{{ $lead->name }}</span>
                    </div>
                </div>
                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Email Address</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-800">{{ $lead->email }}</span>
                    </div>
                </div>
                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Company</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-800">{{ $lead->company ?? 'N/A' }}</span>
                    </div>
                </div>
                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">IP Address</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-800">{{ $lead->ip_address }}</span>
                    </div>
                </div>
                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Status</label>
                    <div class="col-lg-8">
                        <span class="badge badge-light-primary">{{ strtoupper($lead->status) }}</span>
                    </div>
                </div>
                 <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Created At</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-800">{{ $lead->created_at->format('M d, Y H:i:s') }} ({{ $lead->created_at->diffForHumans() }})</span>
                    </div>
                </div>
                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Message</label>
                    <div class="col-lg-8">
                        <div class="p-5 bg-light rounded text-gray-800 fs-6">
                            {{ $lead->message }}
                        </div>
                    </div>
                </div>
            </div>
            @can('follow up signups')
                <form action="{{ route('admin.leads.update', $lead) }}" method="POST" class="card-body border-top p-9" data-lead-follow-up>
                    @csrf
                    @method('PATCH')
                    @if (session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif
                    <h3 class="fw-bolder fs-5 mb-5">Follow-up</h3>
                    <div class="row mb-5">
                        <label class="col-lg-4 fw-bold text-muted" for="lead-status">Status</label>
                        <div class="col-lg-8">
                            <select id="lead-status" name="status" class="form-select form-select-solid mw-250px">
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}" @selected(old('status', $lead->status) === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                            @if ($lead->status_changed_at)
                                <div class="text-muted fs-7 mt-2">Changed {{ $lead->status_changed_at->diffForHumans() }}</div>
                            @endif
                            @error('status')<div class="text-danger fs-7 mt-2">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="row mb-5">
                        <label class="col-lg-4 fw-bold text-muted" for="lead-notes">Notes</label>
                        <div class="col-lg-8">
                            <textarea id="lead-notes" name="notes" rows="5" class="form-control form-control-solid" placeholder="Calls, proposals sent, what they need">{{ old('notes', $lead->notes) }}</textarea>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-3">
                        <a href="mailto:{{ $lead->email }}" class="btn btn-light btn-sm">Email {{ $lead->name }}</a>
                        <button type="submit" class="btn btn-primary btn-sm">Save follow-up</button>
                    </div>
                </form>
            @endcan
            <div class="card-footer d-flex justify-content-end py-6 px-9">
                <form action="{{ route('admin.leads.destroy', $lead->id) }}" method="POST">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-light-danger btn-sm" onclick="return confirm('Are you sure you want to delete this lead?')">Delete Lead</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
