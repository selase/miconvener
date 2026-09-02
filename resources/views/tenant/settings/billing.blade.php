@extends('layouts.admin.master')

@section('title', 'Billing Settings')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="card mb-5 mb-xl-10">
            <div class="card-header border-0 cursor-pointer">
                <div class="card-title m-0">
                    <h3 class="fw-bolder m-0">Invoice Details</h3>
                </div>
            </div>
            <div id="kt_account_settings_profile_details" class="collapse show">
                <form action="{{ route('tenant.settings.billing.update') }}" method="POST" class="form">
                    @csrf
                    <div class="card-body border-top p-9">
                        
                        <div class="row mb-6">
                            <label class="col-lg-4 col-form-label required fw-bold fs-6">Billing Email</label>
                            <div class="col-lg-8 fv-row">
                                <input type="email" name="billing_email" class="form-control form-control-lg form-control-solid" placeholder="billing@company.com" value="{{ old('billing_email', $billingEmail) }}" />
                                <div class="form-text">Invoices will be sent to this address.</div>
                            </div>
                        </div>

                        <div class="row mb-6">
                            <label class="col-lg-4 col-form-label fw-bold fs-6">Tax ID / VAT Number</label>
                            <div class="col-lg-8 fv-row">
                                <input type="text" name="tax_id" class="form-control form-control-lg form-control-solid" placeholder="e.g. GB123456789" value="{{ old('tax_id', $taxId) }}" />
                                <div class="form-text">This will appear on your invoices.</div>
                            </div>
                        </div>

                        <div class="row mb-6">
                            <label class="col-lg-4 col-form-label fw-bold fs-6">Billing Address</label>
                            <div class="col-lg-8 fv-row">
                                <textarea name="billing_address" class="form-control form-control-lg form-control-solid" rows="3" placeholder="Street Address, City, Country">{{ old('billing_address', $billingAddress) }}</textarea>
                                <div class="form-text">Override your organization address for billing purposes.</div>
                            </div>
                        </div>

                    </div>
                    <div class="card-footer d-flex justify-content-end py-6 px-9">
                        <button type="reset" class="btn btn-light btn-active-light-primary me-2">Discard</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
