@extends('layouts.admin.master')

@section('title', __('locale.menu.login_history'))

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="row mb-10">
            <div class="col-md-6 mb-5">
                <div class="card">
                    <div class="card-header border-0 pt-6">
                        <div class="card-titile">
                            <h3>{{ strtoupper(__('locale.labels.browsers')) }}</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="browser-session" style="width: 100%;height:300px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-5">
                <div class="card">
                    <div class="card-header border-0 pt-6">
                        <div class="card-titile">
                            <h3>{{ strtoupper(__('locale.labels.locations')) }}</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="location-session" style="width: 100%;height:300px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-5">
                <div class="card">
                    <div class="card-header border-0 pt-6">
                        <div class="card-titile">
                            <h3>{{ strtoupper(__('locale.labels.platform_or_os')) }}</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="platform-session" style="width: 100%;height:300px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-5">
                <div class="card">
                    <div class="card-header border-0 pt-6">
                        <div class="card-titile">
                            <h3>{{ strtoupper(__('locale.labels.devices')) }}</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="client-device-session" style="width: 100%;height:300px;"></div>
                    </div>
                </div>
            </div>
        </div>


        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title">

                </div>

                <div class="card-toolbar">

                </div>
            </div>
            <div class="card-body py-4">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-5" id="login-history-table">
                        <thead>
                            <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                <th class="w-10px pe-2">{{ __('locale.labels.sl') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.user') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.location') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.device') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.platform') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.ip_address') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.last_login') }}</th>
                                <th class="min-w-125px">{{ __('locale.labels.last_logout') }}</th>
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
        $("#login-history-table").DataTable({
             "processing": true,
            "serverSide": true,
            "searchDelay": 1500,
            "searching": true,
            "ajax": {
                "url": "{{ route('audit-trail.login-history.all') }}",
                "dataType": "json",
                "type": "POST",
                "data": {_token: "{{csrf_token()}}"}
            },
            "lengthMenu": [
                [25, 50, 100, 250, 500, -1], [25, 50, 100, 250, 500, "All"]
            ],
            "columns": [
                {"data": 'id'},
                {"data": "user", className: "d-flex align-items-center"},
                {"data": "location"},
                {"data": "client_device"},
                {"data": "platform"},
                {"data": "ip_address"},
                {"data": "login_at"},
                {"data": "logout_at"},
            ],
            "order": [6, 'desc'],
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

    @include('admin.audit-trail.partials.login-history-chartsjs')
@endpush
