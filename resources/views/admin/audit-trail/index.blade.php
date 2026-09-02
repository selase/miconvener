@extends('layouts.admin.master')

@section('title', __('locale.menu.activity_logs'))

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    {{ __('Activity Logs') }}
                </div>
                <div class="card-toolbar">
                    <a href="{{ route('audit-trail.activity-logs.export') }}" class="btn btn-light-primary btn-sm">
                        <!--begin::Svg Icon | path: icons/duotune/arrows/arr078.svg-->
                        <span class="svg-icon svg-icon-3">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                <rect opacity="0.3" x="12.75" y="4.25" width="12" height="2" rx="1" transform="rotate(90 12.75 4.25)" fill="currentColor" />
                                <path d="M12.0573 6.11875L13.5203 7.87435C13.9121 8.34457 14.6232 8.37683 15.056 7.94401C15.4457 7.5543 15.4641 6.92836 15.0979 6.51543L13.2485 4.43478C12.9005 4.04321 12.307 4.04321 11.959 4.43478L10.1096 6.51543C9.74338 6.92836 9.76184 7.5543 10.1515 7.94401C10.5843 8.37683 11.2954 8.34457 11.6872 7.87435L13.1502 6.11875C13.1502 6.11875 13.0456 6.11875 12.0573 6.11875Z" fill="currentColor" />
                                <path d="M18.75 8.25H17.75C17.1977 8.25 16.75 8.69772 16.75 9.25C16.75 9.80228 17.1977 10.25 17.75 10.25C18.3023 10.25 18.75 10.6977 18.75 11.25V18.25C18.75 18.8023 18.3023 19.25 17.75 19.25H5.75C5.19772 19.25 4.75 18.8023 4.75 18.25V11.25C4.75 10.6977 5.19772 10.25 5.75 10.25C6.30228 10.25 6.75 9.80228 6.75 9.25C6.75 8.69772 6.30228 8.25 5.75 8.25H4.75C3.64543 8.25 2.75 9.14543 2.75 10.25V19.25C2.75 20.3546 3.64543 21.25 4.75 21.25H18.75C19.8546 21.25 20.75 20.3546 20.75 19.25V10.25C20.75 9.14543 19.8546 8.25 18.75 8.25Z" fill="currentColor" />
                            </svg>
                        </span>
                        <!--end::Svg Icon-->Export CSV</a>
                </div>
            </div>
            <div class="card-body py-4">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-5" id="activity-log-table">
                        <thead>
                            <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                <th class="min-w-125px">{{ __('locale.labels.log_id') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.location') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.event') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.subject_type') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.causer') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.created_at') }}</th>
                                <th class="text-end min-w-100px">{{ __('locale.labels.properties') }}</th>
                            </tr>
                        </thead>
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
        $("#activity-log-table").DataTable({
            "processing": true,
            "serverSide": true,
            "searchDelay": 1500,
            "searching": true,
            "ajax": {
                "url": "{{ route('audit-trail.activity-logs.all') }}",
                "dataType": "json",
                "type": "POST",
                "data": {_token: "{{csrf_token()}}"}
            },
            "lengthMenu": [
                [25, 50, 100, 250, 500, -1], [25, 50, 100, 250, 500, "All"]
            ],
            "columns": [
                {"data": 'id'},
                {"data": "location"},
                {"data": "event"},
                {"data": "subject_type"},
                {"data": "causer"},
                {"data": "created_at"},
                {"data": "properties", orderable: false, searchable: false, className: "text-end"}
            ],
            responsive: true,
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
             "order": [5, 'desc'],
           });
    </script>
@endpush
