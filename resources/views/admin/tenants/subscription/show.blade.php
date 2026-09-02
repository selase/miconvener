@extends('layouts.admin.master')

@section('title', __('locale.labels.invoices'))

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            <div class="d-flex flex-column flex-lg-row">
                <div class="flex-lg-row-fluid me-lg-15 order-2 order-lg-1 mb-10 mb-lg-0">
                    <div class="card card-flush pt-3 mb-5 mb-xl-10">
                        <div class="card-header">
                            <div class="card-title">
                                <h2>{{ __('locale.labels.invoices') }}</h2>
                            </div>
                            <div class="card-toolbar">
                                @can('update tenant')
                                    <a href="javascript:void(0)" onclick="adminTopupTenantCredit({{ $subscription->tenant->id }})" class="btn btn-light-primary">{{ __('Add Credit') }}</a>
                                @endcan
                            </div>
                        </div>
                        <div class="card-body pt-2">
                            <table id="customer_invoices" class="table align-middle table-row-dashed fs-6 fw-bolder gs-0 gy-4 p-0 m-0">
                                <thead class="border-bottom border-gray-200 fs-7 text-uppercase fw-bolder">
                                    <tr class="text-start text-gray-400">
                                        <th class="min-w-100px">Order ID</th>
                                        <th class="min-w-100px">Amount</th>
                                        <th class="min-w-100px">Status</th>
                                        <th class="min-w-125px">Date</th>
                                        <th class="w-100px">Invoice</th>
                                    </tr>
                                </thead>
                                <tbody class="fs-6 fw-bold text-gray-600">
                                    @foreach ($subscription_logs as $subscription_log)
                                        <tr>
                                            <td>
                                                <a href="{{ route('tenants.subscriptions.invoice', $subscription_log->uuid) }}" class="text-gray-600 text-hover-primary">{{ $subscription_log->transaction_id }}</a>
                                            </td>
                                            <td class="text-success">{{ App\Libraries\Helper::formatAmountWithCurrencySymbol($subscription_log->amount) }}</td>
                                            <td>{!! $subscription_log->getStatusLabel() !!}</td>
                                            <td>{{ App\Libraries\Helper::getFormattedDateString($subscription_log->date) }}</td>
                                            <td class="">
                                                <button class="btn btn-sm btn-light btn-active-light-primary">Download</button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>


                <div class="flex-column flex-lg-row-auto w-lg-250px w-xl-300px mb-10 order-1 order-lg-2">
                    <div class="card card-flush mb-0" data-kt-sticky="true" data-kt-sticky-name="subscription-summary" data-kt-sticky-offset="{default: false, lg: '200px'}" data-kt-sticky-width="{lg: '250px', xl: '300px'}" data-kt-sticky-left="auto" data-kt-sticky-top="150px" data-kt-sticky-animation="false" data-kt-sticky-zindex="95">
                        <div class="card-header">
                            <div class="card-title">
                                <h2>Active Subscription</h2>
                            </div>
                            <div class="card-toolbar">
                                <a href="#" class="btn btn-sm btn-light btn-icon" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    <span class="svg-icon svg-icon-3">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                            <rect x="10" y="10" width="4" height="4" rx="2" fill="black" />
                                            <rect x="17" y="10" width="4" height="4" rx="2" fill="black" />
                                            <rect x="3" y="10" width="4" height="4" rx="2" fill="black" />
                                        </svg>
                                    </span>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-bold fs-6 w-200px py-4 d-none" data-kt-menu="true">
                                    <div class="menu-item px-3">
                                        <a href="#" class="menu-link px-3">Pause Subscription</a>
                                    </div>
                                    <div class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" data-kt-subscriptions-view-action="delete">Edit Subscription</a>
                                    </div>
                                    <div class="menu-item px-3">
                                        <a href="#" class="menu-link text-danger px-3" data-kt-subscriptions-view-action="edit">Cancel Subscription</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-body pt-0 fs-6">
                            <div class="mb-7">
                                <div class="d-flex align-items-center">
                                    <div class="symbol symbol-60px symbol-circle me-3">
                                        <img alt="Pic" src="{{ App\Libraries\Helper::generateRetroGravatar($subscription->tenant->email) }}" />
                                    </div>
                                    <div class="d-flex flex-column">
                                        <a href="#" class="fs-4 fw-bolder text-gray-900 text-hover-primary me-2">{{ $subscription->tenant->name }}</a>
                                        <a href="#" class="fw-bold text-gray-600 text-hover-primary">{{ $subscription->tenant->email }}</a>
                                    </div>
                                </div>
                            </div>

                            @if (!is_null($subscription))
                                <div class="separator separator-dashed mb-7"></div>
                                <div class="mb-10">
                                    <h5 class="mb-4">Payment Method</h5>
                                    <div class="mb-0">
                                        <div class="fw-bold text-gray-600 d-flex align-items-center">
                                            {{ ucfirst(str_replace('_', ' ', $subscription_transaction->payment_method ?? 'Cash')) }}
                                        <img src="{{ asset('assets/media/svg/card-logos/mastercard.svg') }}" class="w-35px ms-2" alt="" /></div>
                                    </div>
                                </div>
                                <div class="separator separator-dashed mb-7"></div>
                                <div class="mb-10">
                                    <h5 class="mb-4">Subscription Details</h5>
                                    <table class="table fs-6 fw-bold gs-0 gy-2 gx-2">
                                        <tr class="">
                                            <td class="text-gray-400">Amount:</td>
                                            <td class="text-gray-800">{{ App\Libraries\Helper::formatAmountWithCurrencySymbol($subscription->amount) }}</td>
                                        </tr>
                                        <tr class="">
                                            <td class="text-gray-400">Currency:</td>
                                            <td class="text-gray-800">{{ $subscription_transaction->currency }}</td>
                                        </tr>
                                        <tr class="">
                                            <td class="text-gray-400">Subscription ID:</td>
                                            <td class="text-gray-800">{{ $subscription->transaction_id }}</td>
                                        </tr>
                                        <tr class="">
                                            <td class="text-gray-400">Started:</td>
                                            <td class="text-gray-800">{{ App\Libraries\Helper::getFormattedDateString($subscription->date) }}</td>
                                        </tr>
                                        <tr class="">
                                            <td class="text-gray-400">Status:</td>
                                            <td>
                                                <span class="badge badge-light-success">Active</span>
                                            </td>
                                        </tr>
                                        <tr class="">
                                            <td class="text-gray-400">Credit Balance</td>
                                            <td class="text-gray-800">{{ $subscription->credit_balance }}</td>
                                        </tr>
                                    </table>
                                </div>
                            @endif
                            <div class="mb-0 d-none">
                                <a href="{{ route('subscription.topup') }}" class="btn btn-primary" id="kt_subscriptions_create_button">Top Up</a>
                            </div>
                        </div>
                    </div>
                    <!--end::Card-->
                </div>
                <!--end::Sidebar-->
            </div>
            <!--end::Layout-->
        </div>
        <!--end::Container-->

        @include('admin.tenants.subscription.modals.create')
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
