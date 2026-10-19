@extends('layouts.admin.master')

@section('title', 'Enterprise Leads')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        <div class="card mb-5" data-lead-recipients>
            <div class="card-header border-0 pt-6">
                <div class="card-title flex-column">
                    <h3 class="fw-bolder m-0">Who receives enquiries</h3>
                    <span class="text-muted fs-7 mt-1">Every address here is emailed when someone fills in the Enterprise form. Replying to that email answers the enquirer.</span>
                </div>
            </div>
            <div class="card-body py-4">
                @forelse ($recipients as $recipient)
                    <div class="d-flex align-items-center justify-content-between border-bottom py-3">
                        <span class="fw-bold text-gray-800">{{ $recipient->email }}</span>
                        @can('update setting')
                            <form action="{{ route('admin.lead-recipients.destroy', $recipient) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-light-danger btn-sm" onclick="return confirm('Stop emailing {{ $recipient->email }} about enquiries?')">Remove</button>
                            </form>
                        @endcan
                    </div>
                @empty
                    <p class="text-muted">Nobody is set. Enquiries go to staff who follow up signups.</p>
                @endforelse
                @can('update setting')
                    <form action="{{ route('admin.lead-recipients.store') }}" method="POST" class="d-flex gap-3 mt-4">
                        @csrf
                        <input type="email" name="email" required class="form-control form-control-solid mw-400px" placeholder="name@example.com" value="{{ old('email') }}">
                        <button type="submit" class="btn btn-primary btn-sm">Add</button>
                    </form>
                    @error('email')<div class="text-danger fs-7 mt-2">{{ $message }}</div>@enderror
                @endcan
            </div>
        </div>
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
