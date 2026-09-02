@extends('layouts.admin.master')

@section('title', __('locale.menu.roles_and_permissions'))

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
                        <h3 class="card-label">Roles & Permissions</h3>
                    </div>
                    <!--end::Card title-->
                    <!--begin::Card toolbar-->
                    <div class="d-flex justify-content-end" data-kt-user-table-toolbar="base">
                        <!--begin::Tenant Filter-->
                        <div class="me-3">
                            <select id="tenant_filter" class="form-select form-select-solid w-250px" data-control="select2"
                                data-placeholder="Filter by Tenant">
                                <option value=""></option>
                                <option value="all">All (Global + Tenants)</option>
                                <option value="global">Global Only</option>
                                @foreach($tenants as $tenant)
                                    <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <!--end::Tenant Filter-->

                        <a href="{{ route('roles.create') }}" class="btn btn-primary">
                            <span class="svg-icon svg-icon-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none">
                                    <rect opacity="0.5" x="11.364" y="20.364" width="16" height="2" rx="1"
                                        transform="rotate(-90 11.364 20.364)" fill="currentColor" />
                                    <rect x="4.36396" y="11.364" width="16" height="2" rx="1" fill="currentColor" />
                                </svg>
                            </span>
                            Add New Role
                        </a>
                    </div>
                    <!--end::Card toolbar-->
                </div>
                <!--end::Card header-->
                <!--begin::Card body-->
                <div class="card-body py-4">
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed fs-6 gy-5" id="roles-table">
                            <thead>
                                <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                    <th class="w-10px pe-2">
                                        <div class="form-check form-check-sm form-check-custom form-check-solid me-3">
                                            <input class="form-check-input" type="checkbox" data-kt-check="true"
                                                data-kt-check-target="#roles-table .form-check-input" value="1" />
                                        </div>
                                    </th>
                                    <th class="min-w-125px">Role Name</th>
                                    <th class="min-w-125px">Guard</th>
                                    <th class="min-w-125px">Created At</th>
                                    <th class="text-end min-w-100px">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600 fw-bold">
                                {{-- Content will be loaded via AJAX Datatable --}}
                            </tbody>
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
        const table = $("#roles-table").DataTable({
            "processing": true,
            "serverSide": true,
            "searchDelay": 1500,
            "searching": true,
            "ajax": {
                "url": "{{ route('roles.index') }}",
                "type": "GET",
                "data": function (d) {
                    d.tenant_id = $('#tenant_filter').val();
                }
            },
            "columns": [
                { "data": 'id', orderable: false, searchable: false },
                { "data": "name" },
                { "data": "guard_name" },
                { "data": "created_at" },
                { "data": "action", orderable: false, searchable: false, className: "text-end" }
            ],
            "columnDefs": [
                {
                    targets: 0,
                    orderable: false,
                    render: function (data) {
                        return `
                                <div class="form-check form-check-sm form-check-custom form-check-solid">
                                    <input class="form-check-input" type="checkbox" value="${data}" />
                                </div>`;
                    }
                }
            ],
            "order": [[3, 'desc']],
        });

        $('#tenant_filter').on('change', function() {
            table.draw();
        });
    </script>
@endpush