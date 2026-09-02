@extends('layouts.admin.master')

@section('title', __('locale.menu.tenants'))

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    Tenants
                </div>

                <div class="card-toolbar">
                    <div class="d-flex justify-content-end" data-kt-user-table-toolbar="base">
                        @can('create tenant')
                            <a href="{{ route('tenants.create') }}" class="btn btn-light-primary me-3" >
                                <span class="svg-icon svg-icon-2">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <rect opacity="0.5" x="11" y="18" width="12" height="2" rx="1" transform="rotate(-90 11 18)" fill="currentColor"/>
                                        <rect x="6" y="11" width="12" height="2" rx="1" fill="currentColor"/>
                                    </svg>
                                </span>
                                Add Tenant
                            </a>
                        @endcan
                    </div>
                    <div class="d-flex justify-content-end align-items-center d-none" data-kt-user-table-toolbar="selected">
                        <div class="fw-bolder me-5">
                        <span class="me-2" data-kt-user-table-select="selected_count"></span>Selected</div>
                        <button type="button" class="btn btn-danger" data-kt-user-table-select="delete_selected">Delete Selected</button>
                    </div>
                </div>
                <!--end::Card toolbar-->
            </div>
            <div class="card-body py-4">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-5" id="tenants-table">
                        <thead>
                            <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                <th class="w-10px pe-2">
                                    <div class="form-check form-check-sm form-check-custom form-check-solid me-3">
                                        <input class="form-check-input" type="checkbox" data-kt-check="true" data-kt-check-target="#kt_table_users .form-check-input" value="1" />
                                    </div>
                                </th>
                                <th class="min-w-125px">{{ __('locale.labels.name') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.phone_number') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.subdomain') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.status') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.created_at') }}</th>
                                <th class="text-end min-w-100px">{{ __('locale.labels.actions') }}</th>
                            </tr>
                        </thead>
                        {{--  <tbody class="text-gray-600 fw-bold">
                            @foreach ($tenants as $tenant)
                                <tr>
                                    <td>
                                        <div class="form-check form-check-sm form-check-custom form-check-solid">
                                            <input class="form-check-input" type="checkbox" value="1" />
                                        </div>
                                    </td>
                                    <td>
                                        <a href="{{ route('tenants.show', $tenant->uuid) }}">{{ $tenant->name }}</a>
                                    </td>
                                    <td>{{ $tenant->email }}</td>
                                    <td>{{ $tenant->phone_number }}</td>
                                    <td>{{ $tenant->subdomain }}</td>
                                    <td>{!! $tenant->status ? $tenant->status->label() : 'N/A' !!}</td>
                                    <td>{{ $tenant->created_at->diffForHumans() }}</td>
                                    <td class="text-end">
                                        <a href="#" class="btn btn-light btn-active-light-primary btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                            Actions
                                            <span class="svg-icon svg-icon-5 m-0">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M11.4343 12.7344L7.25 8.55005C6.83579 8.13583 6.16421 8.13584 5.75 8.55005C5.33579 8.96426 5.33579 9.63583 5.75 10.05L11.2929 15.5929C11.6834 15.9835 12.3166 15.9835 12.7071 15.5929L18.25 10.05C18.6642 9.63584 18.6642 8.96426 18.25 8.55005C17.8358 8.13584 17.1642 8.13584 16.75 8.55005L12.5657 12.7344C12.2533 13.0468 11.7467 13.0468 11.4343 12.7344Z" fill="currentColor"></path>
                                                </svg>
                                            </span>
                                        </a>
                                        <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-125px py-4" data-kt-menu="true" style="">
                                            @can('read tenant')
                                                <div class="menu-item px-3">
                                                    <a href="{{ route('tenants.show', $tenant->uuid) }}" class="menu-link px-3">View</a>
                                                </div>
                                            @endcan
                                            @can('update tenant')
                                                <div class="menu-item px-3">
                                                    <a href="{{ route('tenants.edit', $tenant->uuid) }}" class="menu-link px-3">Edit</a>
                                                </div>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>  --}}
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>
@endsection

@push('custom-scripts')
    <script src="{{ asset('js/scripts.js') }}"></script>
    <script>
        $("#tenants-table").DataTable({
            "processing": true,
            "serverSide": true,
            "searchDelay": 1500,
            "searching": true,
            "ajax": {
                "url": "{{ route('tenants.all') }}",
                "dataType": "json",
                "type": "POST",
                "data": {_token: "{{csrf_token()}}"}
            },
            "lengthMenu": [
                [25, 50, 100, 250, 500, -1], [25, 50, 100, 250, 500, "All"]
            ],
            "columns": [
                {"data": 'uuid', orderable: false, searchable: false},
                {"data": "name", className: "d-flex align-items-center"},
                {"data": "phone"},
                {"data": "subdomain"},
                {"data": "status"},
                {"data": "created_at"},
                {"data": "action", orderable: false, searchable: false, className: "text-end"}
            ],
            "columnDefs": [
                {
                    // For Responsive
                    className: 'control',
                    orderable: false,
                    responsivePriority: 2,
                    targets: 0
                },
                {
                    // For Checkboxes
                    targets: 0,
                    orderable: false,
                    responsivePriority: 3,
                    render: function (data) {
                        return (
                            `
                            <div class="form-check form-check-sm form-check-custom form-check-solid">
                                <input class="form-check-input" type="checkbox" value="${data}" />
                            </div>`
                        );
                    },
                    checkboxes: {
                        selectAllRender:
                            '<div class="form-check"> <input class="form-check-input" type="checkbox" value="" id="checkboxSelectAll" /><label class="form-check-label" for="checkboxSelectAll"></label></div>',
                        selectRow: true
                    }
                },
            ],
            "order": [5, 'desc'],
            "dom":
             "<'row'" +
             "<'col-sm-6 d-flex align-items-center justify-conten-start'l>" +
             "<'col-sm-6 d-flex align-items-center justify-content-end'f>" +
             ">" +

             "<'table-responsive'tr>" +

             "<'row'" +
             "<'col-sm-12 col-md-5 d-flex align-items-center justify-content-center justify-content-md-start'i>" +
             "<'col-sm-12 col-md-7 d-flex align-items-center justify-content-center justify-content-md-end'p>" +
             ">",
           });
    </script>
@endpush
