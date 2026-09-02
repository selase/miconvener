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
