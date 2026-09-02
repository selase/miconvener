@extends('layouts.admin.master')

@section('title', 'Enterprise Leads')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    Enterprise Leads
                </div>
            </div>
            <div class="card-body py-4">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-5">
                        <thead>
                            <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                <th class="min-w-125px">Name</th>
                                <th class="min-w-125px">Email</th>
                                <th class="min-w-125px">Company</th>
                                <th class="min-w-125px">Status</th>
                                <th class="min-w-125px">Created At</th>
                                <th class="text-end min-w-100px">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-bold">
                            @foreach ($leads as $lead)
                                <tr>
                                    <td>{{ $lead->name }}</td>
                                    <td>{{ $lead->email }}</td>
                                    <td>{{ $lead->company ?? 'N/A' }}</td>
                                    <td>
                                        <span class="badge badge-light-primary">{{ strtoupper($lead->status) }}</span>
                                    </td>
                                    <td>{{ $lead->created_at->diffForHumans() }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.leads.show', $lead->id) }}" class="btn btn-light btn-active-light-primary btn-sm">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    {{ $leads->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
