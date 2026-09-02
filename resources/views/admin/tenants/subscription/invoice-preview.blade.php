@extends('layouts.admin.master')

@section('title', __('locale.labels.invoices'))

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            <div class="card">
                <div class="card-body p-lg-20">
                    <!--begin::Layout-->
                    <div class="d-flex flex-column flex-xl-row">
                        <!--begin::Content-->
                        <div class="flex-lg-row-fluid me-xl-18 mb-10 mb-xl-0">
                            <!--begin::Invoice 2 content-->
                            <div class="mt-n1">
                                <!--begin::Top-->
                                <div class="d-flex flex-stack pb-10">
                                    <!--begin::Logo-->
                                    <a href="#">
                                        <img alt="Logo" src="{{ asset('assets/media/logos/omnichannel_logo@2x.png') }}" style="height: 45px" />
                                    </a>
                                    <a href="#" class="btn btn-sm btn-success">Print</a>
                                </div>
                                <div class="m-0">
                                    <div class="fw-bolder fs-3 text-gray-800 mb-8">Invoice #000{{ $subscriptionInvoice->id }}</div>
                                    <div class="row g-5 mb-11">
                                        <div class="col-sm-6">
                                            <div class="fw-bold fs-7 text-gray-600 mb-1">Issue Date:</div>
                                            <div class="fw-bolder fs-6 text-gray-800">{{ App\Libraries\Helper::getFormattedDateString($subscriptionInvoice->created_at) }}</div>
                                        </div>
                                        <!--end::Col-->
                                        <!--end::Col-->
                                        <div class="col-sm-6">
                                            <!--end::Label-->
                                            <div class="fw-bold fs-7 text-gray-600 mb-1">Paid Date:</div>
                                            <div class="fw-bolder fs-6 text-gray-800 d-flex align-items-center flex-wrap">
                                                <span class="pe-2">
                                                    {{ App\Libraries\Helper::getFormattedDateString($subscriptionInvoice->created_at) }}
                                                </span>
                                                {{--  <span class="fs-7 text-danger d-flex align-items-center">
                                                <span class="bullet bullet-dot bg-danger me-2"></span>Due in 7 days</span>  --}}
                                            </div>
                                            <!--end::Info-->
                                        </div>
                                        <!--end::Col-->
                                    </div>
                                    <!--end::Row-->
                                    <!--begin::Row-->
                                    <div class="row g-5 mb-12">
                                        <!--end::Col-->
                                        <div class="col-sm-6">
                                            <div class="fw-bold fs-7 text-gray-600 mb-1">Issue For:</div>
                                            <div class="fw-bolder fs-6 text-gray-800">{{ $subscriptionInvoice->tenant->name }}.</div>
                                            <div class="fw-bold fs-7 text-gray-600">{{ $subscriptionInvoice->tenant->address }}</div>
                                        </div>

                                        <div class="col-sm-6">
                                            <div class="fw-bold fs-7 text-gray-600 mb-1">Issued By:</div>
                                            <div class="fw-bolder fs-6 text-gray-800">{{ config('app.system_setting.provider.name') }}.</div>
                                            <div class="fw-bold fs-7 text-gray-600">{{ config('app.system_setting.provider.address') }}</div>
                                        </div>
                                    </div>
                                    <!--end::Row-->
                                    <!--begin::Content-->
                                    <div class="flex-grow-1">
                                        <!--begin::Table-->
                                        <div class="table-responsive border-bottom mb-9">
                                            <table class="table mb-3">
                                                <thead>
                                                    <tr class="border-bottom fs-6 fw-bolder text-muted">
                                                        <th class="min-w-175px pb-2">Description</th>
                                                        <th class="min-w-70px text-end pb-2">Qty</th>
                                                        <th class="min-w-70px text-end pb-2">Total Credit</th>
                                                        <th class="min-w-100px text-end pb-2">Amount</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr class="fw-bolder text-gray-700 fs-5 text-end">
                                                        <td class="d-flex align-items-center pt-6">
                                                        <i class="fa fa-genderless text-danger fs-2 me-2"></i>{{ $subscriptionInvoice->description ?? 'Payment for subscription' }}</td>
                                                        <td class="pt-6">1</td>
                                                        <td class="pt-6">{{ $subscriptionInvoice->credit_balance }}</td>
                                                        <td class="pt-6 text-dark fw-boldest">{{ App\Libraries\Helper::formatAmountWithCurrencySymbol($subscriptionInvoice->amount) }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="d-flex justify-content-end">
                                            <div class="mw-300px">
                                                <div class="d-flex flex-stack mb-3">
                                                    <div class="fw-bold pe-10 text-gray-600 fs-7">Subtotal:</div>
                                                    <div class="text-end fw-bolder fs-6 text-gray-800">
                                                        {{ App\Libraries\Helper::formatAmountWithCurrencySymbol($subscriptionInvoice->amount) }}
                                                    </div>
                                                </div>
                                                <div class="d-flex flex-stack mb-3">
                                                    <div class="fw-bold pe-10 text-gray-600 fs-7">VAT 0%</div>
                                                    <div class="text-end fw-bolder fs-6 text-gray-800">0.00</div>
                                                </div>
                                                <div class="d-flex flex-stack mb-3">
                                                    <div class="fw-bold pe-10 text-gray-600 fs-7">Subtotal + VAT</div>
                                                    <div class="text-end fw-bolder fs-6 text-gray-800">
                                                        {{ App\Libraries\Helper::formatAmountWithCurrencySymbol($subscriptionInvoice->amount) }}
                                                    </div>
                                                </div>
                                                <div class="d-flex flex-stack">
                                                    <div class="fw-bold pe-10 text-gray-600 fs-7">Total</div>
                                                    <div class="text-end fw-bolder fs-6 text-gray-800">
                                                        {{ App\Libraries\Helper::formatAmountWithCurrencySymbol($subscriptionInvoice->amount) }}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="m-0">
                            <div class="d-print-none border border-dashed border-gray-300 card-rounded h-lg-100 min-w-md-350px p-9 bg-lighten">
                                <div class="mb-8">
                                    <span class="badge badge-light-success me-2">{!! $subscriptionInvoice->getStatusLabel() !!}</span>
                                </div>

                                <h6 class="mb-8 fw-boldest text-gray-600 text-hover-primary">PAYMENT DETAILS</h6>
                                <div class="mb-6">
                                    <div class="fw-bold text-gray-600 fs-7">Payment Method:</div>
                                    <div class="fw-bolder text-gray-800 fs-6">
                                        {{ ucfirst(str_replace('_', ' ', $subscription_transaction->payment_method)) }}
                                    </div>
                                </div>


                                <h6 class="mb-8 fw-boldest text-gray-600 text-hover-primary">PROJECT OVERVIEW</h6>
                                <div class="mb-6">
                                    <div class="fw-bold text-gray-600 fs-7">Project Name</div>
                                    <div class="fw-bolder fs-6 text-gray-800">SaaS App Quickstarter
                                    <a href="#" class="link-primary ps-1">View Project</a></div>
                                </div>
                            </div>
                            <!--end::Invoice 2 sidebar-->
                        </div>
                        <!--end::Sidebar-->
                    </div>
                    <!--end::Layout-->
                </div>
                <!--end::Body-->
            </div>
            <!--end::Invoice 2 main-->
        </div>
    </div>
@endsection

@push('custom-scripts')
    <script src="{{ asset('js/scripts.js') }}"></script>
@endpush
