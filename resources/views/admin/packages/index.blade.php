@extends('layouts.admin.master')

@section('title', 'Subscription Plans')

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <!--begin::Container-->
        <div id="kt_content_container" class="container-xxl">
            <!--begin::Card-->
            <div class="card">
                <!--begin::Card header-->
                <div class="card-header border-0 pt-6">
                    <!--begin::Card title-->
                    <div class="card-title">
                        <h3 class="card-label">Subscription Packages</h3>
                    </div>
                    <!--end::Card title-->
                    <!--begin::Card toolbar-->
                    <div class="card-toolbar">
                        <div class="d-flex justify-content-end">
                            <a href="{{ route('packages.create') }}" class="btn btn-primary">
                                <span class="svg-icon svg-icon-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none">
                                        <rect opacity="0.5" x="11.364" y="20.364" width="16" height="2" rx="1"
                                            transform="rotate(-90 11.364 20.364)" fill="currentColor" />
                                        <rect x="4.36396" y="11.364" width="16" height="2" rx="1" fill="currentColor" />
                                    </svg>
                                </span>
                                Create Plan
                            </a>
                        </div>
                    </div>
                    <!--end::Card toolbar-->
                </div>
                <!--end::Card header-->
                <!--begin::Card body-->
                <div class="card-body py-4">
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed fs-6 gy-5" id="packages-table">
                            <thead>
                                <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                    <th class="min-w-125px">Name</th>
                                    <th class="min-w-125px">Price</th>
                                    <th class="min-w-125px">Interval</th>
                                    <th class="min-w-125px">Status</th>
                                    <th class="min-w-125px">Created At</th>
                                    <th class="text-end min-w-100px">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600 fw-bold"></tbody>
                        </table>
                    </div>
                </div>
                <!--end::Card body-->
            </div>
            <!--end::Card-->
        </div>
        <!--end::Container-->
    </div>
@endsection

@push('custom-scripts')
    <script>
        var table = $("#packages-table").DataTable({
            "processing": true,
            "serverSide": true,
            "searchDelay": 1500,
            "searching": true,
            "ajax": {
                "url": "{{ route('packages.index') }}",
                "type": "GET",
            },
            "columns": [
                { "data": "name" },
                { "data": "price" },
                { "data": "interval" },
                { "data": "is_active" },
                { "data": "created_at" },
                { "data": "action", orderable: false, searchable: false, className: "text-end" }
            ],
            "order": [[4, 'desc']],
        });

        function deletePackage(id) {
            if (confirm('Are you sure? This effectively removes this plan.')) {
                $.ajax({
                    url: '/admin/packages/' + id,
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    type: 'DELETE',
                    success: function (result) {
                        toastr.success(result.message);
                        table.ajax.reload();
                    },
                    error: function (err) {
                        toastr.error('Error deleting package');
                    }
                });
            }
        }
    </script>
@endpush