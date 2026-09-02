@extends('layouts.admin.master')

@section('title', __('locale.menu.subscription'))

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            <div class="d-flex flex-column flex-lg-row">
                <div class="flex-lg-row-fluid me-lg-15 order-2 order-lg-1 mb-10 mb-lg-0">
                    <div class="card card-flush pt-3 mb-5 mb-xl-10">
                        <div class="card-header">
                            <div class="card-title">
                                <h2>{{ __('locale.labels.tenant_subscriptions') }}</h2>
                            </div>
                            <div class="card-toolbar">
                                {{--  <a href="#" class="btn btn-light-primary">{{ __('Add Credit') }}</a>  --}}
                            </div>
                        </div>
                        <div class="card-body pt-2">
                            <table id="customer_invoices" class="table align-middle table-row-dashed fs-6 fw-bolder gs-0 gy-4 p-0 m-0">
                                <thead class="border-bottom border-gray-200 fs-7 text-uppercase fw-bolder">
                                    <tr class="text-start text-gray-400">
                                        <th class="min-w-100px">Tenant</th>
                                        <th class="min-w-100px">Order ID</th>
                                        <th class="min-w-100px">Amount</th>
                                        <th class="min-w-100px">Status</th>
                                        <th class="min-w-125px">Date</th>
                                        <th class="w-100px">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="fs-6 fw-bold text-gray-600">
                                    @foreach ($subscriptions as $subscription)
                                        <tr>
                                            <td class="d-flex align-items-center">
                                                <div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
                                                    <a href="{{ route('tenants.show', $subscription->tenant->uuid) }}">
                                                        <div class="symbol-label">
                                                            <img src="{{ App\Libraries\Helper::generateRetroGravatar($subcription->tenant->email ?? 'hello-tenant@example.com') }}" alt="{{ $subscription->tenant->name }}" class="w-100" />
                                                        </div>
                                                    </a>
                                                </div>
                                                <div class="d-flex flex-column">
                                                    <a href="{{ route('tenants.show', $subscription->tenant->uuid) }}" class="text-gray-800 text-hover-primary mb-1">{{ $subscription->tenant->name }}</a>
                                                    <span>{{ $subscription->tenant->email }}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <a href="#" class="text-gray-600 text-hover-primary">{{ $subscription->transaction_id }}</a>
                                            </td>
                                            <td class="text-success">{{ App\Libraries\Helper::formatAmountWithCurrencySymbol($subscription->amount) }}</td>
                                            <td>{!! $subscription->getStatusLabel() !!}</td>
                                            <td>{{ App\Libraries\Helper::getFormattedDateString($subscription->date) }}</td>
                                            <td class="">
                                                <a href="{{ route('tenants.subscriptions.show', $subscription->uuid) }}" class="btn btn-sm btn-light btn-active-light-primary">View</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('custom-scripts')
    <script src="{{ asset('js/scripts.js') }}"></script>
    <script>
        $("#customer_invoices").DataTable({
            "language": {
             "lengthMenu": "Show _MENU_",
            },
            "lengthMenu": [[25, 50, 100, 250, 500, 1000, -1], [25, 50, 100, 250, 500, 1000, "All"]],
            "responsive": true,
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
