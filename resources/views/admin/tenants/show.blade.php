@extends('layouts.admin.master')

@section('title', $tenant->name)

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header border-0 pt-6">
                        <div class="card-title">
                            {{--  {{ __('Add Sending Gateway') }}  --}}
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-center flex-column py-5">
                            <div class="symbol symbol-100px symbol-circle mb-7">
                                @if ($tenant->logo)
                                    <img src="{{ Storage::url($tenant->logo) }}" alt="image" />
                                @else
                                    <img src="{{ $tenant->gravatar }}" alt="image" />
                                @endif
                            </div>
                            <a href="#" class="fs-3 text-gray-800 text-hover-primary fw-bolder mb-3">{{ $tenant->name }}</a>

                            {{--  <div class="fw-bolder mb-3">Campaign Markups
                            <i class="fas fa-exclamation-circle ms-2 fs-7" data-bs-toggle="popover" data-bs-trigger="hover" data-bs-html="true" data-bs-content="Campaign Markups for {{ $tenant->name }}"></i></div>
                            <div class="d-flex flex-wrap flex-center">
                                <div class="border border-gray-300 border-dashed rounded py-3 px-3 mb-3">
                                    <div class="fs-4 fw-bolder text-gray-700">
                                        <span class="w-75px">0</span>
                                        <span class="svg-icon svg-icon-3 svg-icon-success">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                                <rect opacity="0.5" x="13" y="6" width="13" height="2" rx="1" transform="rotate(90 13 6)" fill="currentColor" />
                                                <path d="M12.5657 8.56569L16.75 12.75C17.1642 13.1642 17.8358 13.1642 18.25 12.75C18.6642 12.3358 18.6642 11.6642 18.25 11.25L12.7071 5.70711C12.3166 5.31658 11.6834 5.31658 11.2929 5.70711L5.75 11.25C5.33579 11.6642 5.33579 12.3358 5.75 12.75C6.16421 13.1642 6.83579 13.1642 7.25 12.75L11.4343 8.56569C11.7467 8.25327 12.2533 8.25327 12.5657 8.56569Z" fill="currentColor" />
                                            </svg>
                                        </span>
                                    </div>
                                    <div class="fw-bold text-muted">SMS Markup</div>
                                </div>

                                <div class="border border-gray-300 border-dashed rounded py-3 px-3 mx-4 mb-3">
                                    <div class="fs-4 fw-bolder text-gray-700">
                                        <span class="w-50px">0</span>
                                        <span class="svg-icon svg-icon-3 svg-icon-danger">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                                <rect opacity="0.5" x="11" y="18" width="13" height="2" rx="1" transform="rotate(-90 11 18)" fill="currentColor" />
                                                <path d="M11.4343 15.4343L7.25 11.25C6.83579 10.8358 6.16421 10.8358 5.75 11.25C5.33579 11.6642 5.33579 12.3358 5.75 12.75L11.2929 18.2929C11.6834 18.6834 12.3166 18.6834 12.7071 18.2929L18.25 12.75C18.6642 12.3358 18.6642 11.6642 18.25 11.25C17.8358 10.8358 17.1642 10.8358 16.75 11.25L12.5657 15.4343C12.2533 15.7467 11.7467 15.7467 11.4343 15.4343Z" fill="currentColor" />
                                            </svg>
                                        </span>
                                    </div>
                                    <div class="fw-bold text-muted">WhatsApp Markup</div>
                                </div>
                            </div>  --}}
                        </div>

                        <div class="d-flex flex-stack fs-4 py-3">
                            <div class="fw-bolder rotate collapsible" data-bs-toggle="collapse" href="#kt_user_view_details" role="button" aria-expanded="false" aria-controls="kt_user_view_details">Details
                            <span class="ms-2 rotate-180">
                                <span class="svg-icon svg-icon-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                        <path d="M11.4343 12.7344L7.25 8.55005C6.83579 8.13583 6.16421 8.13584 5.75 8.55005C5.33579 8.96426 5.33579 9.63583 5.75 10.05L11.2929 15.5929C11.6834 15.9835 12.3166 15.9835 12.7071 15.5929L18.25 10.05C18.6642 9.63584 18.6642 8.96426 18.25 8.55005C17.8358 8.13584 17.1642 8.13584 16.75 8.55005L12.5657 12.7344C12.2533 13.0468 11.7467 13.0468 11.4343 12.7344Z" fill="currentColor" />
                                    </svg>
                                </span>
                            </span></div>
                            <span data-bs-toggle="tooltip" data-bs-trigger="hover" title="Edit tenant details">
                                <a href="{{ route('tenants.edit', $tenant->uuid) }}" class="btn btn-sm btn-light-primary">Edit</a>
                            </span>
                        </div>

                        <div class="separator"></div>

                        <div id="kt_user_view_details" class="collapse show">
                            <div class="pb-5 fs-6">
                                <div class="fw-bolder mt-5">{{ __('locale.labels.subdomain') }}</div>
                                <div class="text-gray-600">{{ $tenant->slug }}</div>

                                <div class="fw-bolder mt-5">{{ __('locale.labels.email') }}</div>
                                <div class="text-gray-600">
                                    <a href="#" class="text-gray-600 text-hover-primary">{{ $tenant->email }}</a>
                                </div>

                                <div class="fw-bolder mt-5">{{ __('locale.labels.phone_number') }}</div>
                                <div class="text-gray-600">{{ $tenant->phone_number }}</div>

                                <div class="fw-bolder mt-5">{{ __('locale.labels.address') }}</div>
                                <div class="text-gray-600">{{ $tenant->address }}</div>

                                <div class="fw-bolder mt-5">{{ __('locale.labels.date_joined') }}</div>
                                <div class="text-gray-600">{{ $tenant->created_at }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card mt-6">
                    <div class="card-header border-0 pt-6">
                        <div class="card-title">
                            {{ __('Usage Analytics') }}
                        </div>
                        <div class="card-toolbar">
                            <form action="{{ route('tenants.show', $tenant->uuid) }}" method="GET" class="d-flex align-items-center gap-3">
                                <div class="d-flex align-items-center gap-2">
                                    <input type="date" name="start_date" class="form-control form-control-sm form-control-solid" value="{{ $start_date }}" onchange="this.form.submit()">
                                    <span class="text-gray-500">to</span>
                                    <input type="date" name="end_date" class="form-control form-control-sm form-control-solid" value="{{ $end_date }}" onchange="this.form.submit()">
                                </div>
                                <select name="days" class="form-select form-select-sm form-select-solid w-125px" onchange="this.form.submit()">
                                    <option value="1" {{ $days == 1 ? 'selected' : '' }}>Last 24 Hours</option>
                                    <option value="7" {{ $days == 7 ? 'selected' : '' }}>Last 7 Days</option>
                                    <option value="30" {{ $days == 30 ? 'selected' : '' }}>Last 30 Days</option>
                                    <option value="90" {{ $days == 90 ? 'selected' : '' }}>Last 90 Days</option>
                                </select>
                            </form>
                        </div>
                    </div>
                    <div class="card-body">
                        <x-chart id="tenant-specific-usage-chart" type="line" :data="[
                            'labels' => $usageTrend['labels'],
                            'datasets' => [
                                [
                                    'label' => 'Hourly Requests',
                                    'data' => $usageTrend['data'],
                                    'borderColor' => '#009EF7',
                                    'backgroundColor' => 'rgba(0, 158, 247, 0.1)',
                                    'fill' => true,
                                    'tension' => 0.4,
                                ],
                            ],
                        ]" />
                    </div>
                </div>

                <div class="row g-5 g-xl-10 mt-1">
                    <div class="col-xl-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-header border-0 pt-6">
                                <div class="card-title flex-column">
                                    <span class="card-label fw-bolder text-dark">Storage (MB)</span>
                                    <span class="text-muted mt-1 fw-bold fs-7">S3/Object usage</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <x-chart id="tenant-storage-chart" type="line" :data="[
                                    'labels' => $storageTrend['labels'],
                                    'datasets' => [
                                        [
                                            'label' => 'Total MB',
                                            'data' => $storageTrend['data'],
                                            'borderColor' => '#7239EA',
                                            'backgroundColor' => 'rgba(114, 57, 234, 0.1)',
                                            'fill' => true,
                                        ],
                                    ],
                                ]" />
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-header border-0 pt-6">
                                <div class="card-title flex-column">
                                    <span class="card-label fw-bolder text-dark">Database (MB)</span>
                                    <span class="text-muted mt-1 fw-bold fs-7">DB Footprint</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <x-chart id="tenant-db-chart" type="line" :data="[
                                    'labels' => $dbTrend['labels'],
                                    'datasets' => [
                                        [
                                            'label' => 'Total MB',
                                            'data' => $dbTrend['data'],
                                            'borderColor' => '#FFC700',
                                            'backgroundColor' => 'rgba(255, 199, 0, 0.1)',
                                            'fill' => true,
                                        ],
                                    ],
                                ]" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-6">
                    <div class="card-header border-0 pt-6">
                        <div class="card-title">
                            {{ __('Team Members') }}
                        </div>
                        <div class="card-toolbar">
                            <div class="d-flex justify-content-end" data-kt-user-table-toolbar="base">
                                <a href="{{ route('tenants.team.create', $tenant->uuid) }}" class="btn btn-light-primary me-3" >
                                    <span class="svg-icon svg-icon-2">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <rect opacity="0.5" x="11" y="18" width="12" height="2" rx="1" transform="rotate(-90 11 18)" fill="currentColor"/>
                                            <rect x="6" y="11" width="12" height="2" rx="1" fill="currentColor"/>
                                        </svg>
                                    </span>
                                    Add Team Member
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body py-4">
                       <div class="table-responsive">
                            <table class="table align-middle table-row-dashed fs-6 gy-5" id="team-members-table">
                                <thead>
                                    <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                        <th class="w-10px pe-2">
                                            <div class="form-check form-check-sm form-check-custom form-check-solid me-3">
                                                <input class="form-check-input" type="checkbox" data-kt-check="true" data-kt-check-target="#kt_table_users .form-check-input" value="1" />
                                            </div>
                                        </th>
                                        <th class="min-w-125px">User</th>
                                        <th class="min-w-125px">Role</th>
                                        <th class="min-w-125px">Joined Date</th>
                                        <th class="text-end min-w-100px">Actions</th>
                                    </tr>
                                    <!--end::Table row-->
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <livewire:admin.tenant-health-check :tenant="$tenant" />
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('custom-scripts')
    <script src="{{ asset('js/scripts.js') }}"></script>
    <script>
        $("#team-members-table").DataTable({
            "processing": true,
            "serverSide": true,
            "searchDelay": 1500,
            "searching": true,
            "ajax": {
                "url": "{{ route('tenants.team.all', $tenant->uuid) }}",
                "dataType": "json",
                "type": "POST",
                "data": {_token: "{{csrf_token()}}"}
            },
            "lengthMenu": [
                [25, 50, 100, 250, 500, -1], [25, 50, 100, 250, 500, "All"]
            ],
            "columns": [
                {"data": 'uuid', orderable: false, searchable: false},
                {"data": "client", className: "d-flex align-items-center"},
                {"data": "role", orderable: false},
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
            "order": [3, 'desc'],
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
