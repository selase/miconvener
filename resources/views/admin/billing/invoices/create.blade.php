@extends('layouts.admin.master')

@section('title', 'Create New Invoice')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="card">
            <div class="card-header">
                <div class="card-title fs-3 fw-bolder">Create Ad-Hoc Invoice</div>
            </div>
            <form action="{{ route('admin.billing.invoices.store') }}" method="POST" class="form">
                @csrf
                <div class="card-body p-9">
                    <div class="row mb-8">
                        <div class="col-xl-3">
                            <div class="fs-6 fw-bold mt-2 mb-3">Tenant</div>
                        </div>
                        <div class="col-xl-9 fv-row">
                            <select name="tenant_id" class="form-select form-select-solid" data-control="select2" data-placeholder="Select a Tenant" required>
                                <option value=""></option>
                                @foreach($tenants as $tenant)
                                    <option value="{{ $tenant->id }}" {{ old('tenant_id') == $tenant->id ? 'selected' : '' }}>
                                        {{ $tenant->name }} ({{ $tenant->slug }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="row mb-8">
                        <div class="col-xl-3">
                            <div class="fs-6 fw-bold mt-2 mb-3">Currency</div>
                        </div>
                        <div class="col-xl-9 fv-row">
                            <select name="currency" class="form-select form-select-solid" required>
                                <option value="USD" {{ old('currency') == 'USD' ? 'selected' : '' }}>USD - US Dollar</option>
                                <option value="GHS" {{ old('currency') == 'GHS' ? 'selected' : '' }}>GHS - Ghanaian Cedi</option>
                                <option value="EUR" {{ old('currency') == 'EUR' ? 'selected' : '' }}>EUR - Euro</option>
                                <option value="GBP" {{ old('currency') == 'GBP' ? 'selected' : '' }}>GBP - British Pound</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-8">
                        <div class="col-xl-3">
                            <div class="fs-6 fw-bold mt-2 mb-3">Due Date</div>
                        </div>
                        <div class="col-xl-9 fv-row">
                            <input type="date" name="due_date" class="form-control form-control-solid" value="{{ old('due_date', now()->addDays(7)->format('Y-m-d')) }}" required />
                            <div class="form-text">Default is 7 days from now.</div>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end py-6 px-9">
                    <a href="{{ route('admin.billing.invoices.index') }}" class="btn btn-light btn-active-light-primary me-2">Discard</a>
                    <button type="submit" class="btn btn-primary">Create Draft</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
