@extends('layouts.admin.master')

@section('title', 'Edit Subscription Plan')

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <!--begin::Container-->
        <div id="kt_content_container" class="container-xxl">
            <!--begin::Card-->
            <div class="card">
                <!--begin::Card header-->
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h3 class="card-label">Edit Plan: {{ $package->name }}</h3>
                    </div>
                </div>
                <!--end::Card header-->
                <!--begin::Card body-->
                <div class="card-body">
                    <form id="editPackageForm" class="form" action="{{ route('packages.update', $package->id) }}"
                        method="POST">
                        @csrf
                        @method('PUT')

                        <div class="row mb-7">
                            <div class="col-md-6">
                                <label class="required fw-bold fs-6 mb-2">Plan Name</label>
                                <input type="text" name="name" class="form-control form-control-solid mb-3 mb-lg-0"
                                    value="{{ old('name', $package->name) }}" required />
                            </div>
                            <div class="col-md-6">
                                <label class="required fw-bold fs-6 mb-2">Slug</label>
                                <input type="text" name="slug" class="form-control form-control-solid mb-3 mb-lg-0"
                                    value="{{ old('slug', $package->slug) }}" required />
                            </div>
                        </div>

                        <div class="row mb-7">
                            <div class="col-md-4">
                                <label class="required fw-bold fs-6 mb-2">Price</label>
                                <input type="number" step="0.01" name="price" class="form-control form-control-solid"
                                    value="{{ old('price', $package->price) }}" required />
                            </div>
                            <div class="col-md-4">
                                <label class="required fw-bold fs-6 mb-2">Interval</label>
                                <select class="form-select form-select-solid" name="interval" required>
                                    <option value="month" {{ $package->interval === 'month' ? 'selected' : '' }}>Monthly
                                    </option>
                                    <option value="year" {{ $package->interval === 'year' ? 'selected' : '' }}>Yearly</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="required fw-bold fs-6 mb-2">Billing Model</label>
                                <select class="form-select form-select-solid" name="billing_model" required>
                                    <option value="flat_rate" {{ $package->billing_model === 'flat_rate' ? 'selected' : '' }}>
                                        Flat Rate (Fixed Price)</option>
                                    <option value="per_seat" {{ $package->billing_model === 'per_seat' ? 'selected' : '' }}>
                                        Per Seat (Per User)</option>
                                </select>
                            </div>
                        </div>

                        <div class="fv-row mb-7">
                            <label class="fw-bold fs-6 mb-2">Description</label>
                            <textarea name="description" class="form-control form-control-solid"
                                rows="3">{{ old('description', $package->description) }}</textarea>
                        </div>

                        <div class="row mb-7">
                            <div class="col-md-4">
                                <label class="fw-bold fs-6 mb-2">Package Markup (%)</label>
                                <input type="number" step="0.01" name="markup_percentage" class="form-control form-control-solid"
                                    value="{{ old('markup_percentage', $package->markup_percentage) }}" />
                                <div class="text-muted fs-7 mt-2">Added to global markup for all metered usage.</div>
                            </div>
                            <div class="col-md-8 d-flex align-items-center mt-8">
                                <div class="form-check form-switch form-check-custom form-check-solid">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" {{ $package->is_active ? 'checked' : '' }} />
                                    <label class="form-check-label" for="isActive">
                                        Active (Visible for subscription)
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="separator my-10"></div>

                        <div class="mb-10">
                            <h3 class="fw-bolder mb-5">Metered Pricing (Rate Card)</h3>
                            <div class="text-muted mb-5">Define unit prices for metered metrics. If left blank, global defaults or free tier will apply.</div>
                            <div class="table-responsive">
                                <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                                    <thead>
                                        <tr class="fw-bolder text-muted">
                                            <th class="min-w-150px">Metric</th>
                                            <th class="min-w-100px">Unit Price ($)</th>
                                            <th class="min-w-100px">Per X Units</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($metrics as $metric)
                                            @php
                                                $price = $package->usagePrices->where('metric', $metric)->first();
                                            @endphp
                                            <tr>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <span class="fs-6 fw-bolder text-gray-800">{{ $metric->name }}</span>
                                                        <span class="text-muted fs-7">Unit: {{ $metric->unit() }}</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <input type="number" step="0.000001" name="usage_prices[{{ $metric->value }}][unit_price]" 
                                                           class="form-control form-control-sm form-control-solid" 
                                                           value="{{ $price?->unit_price }}" placeholder="0.00">
                                                </td>
                                                <td>
                                                    <input type="number" step="1" name="usage_prices[{{ $metric->value }}][unit_quantity]" 
                                                           class="form-control form-control-sm form-control-solid" 
                                                           value="{{ $price?->unit_quantity ?? 1 }}" placeholder="1">
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="separator my-10"></div>

                        <div class="mb-10">
                            <h3 class="fw-bolder mb-5">Feature Matrix</h3>
                            <div class="table-responsive">
                                <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                                    <thead>
                                        <tr class="fw-bolder text-muted">
                                            <th class="min-w-150px">Feature</th>
                                            <th class="min-w-100px">Include?</th>
                                            <th class="min-w-150px">Limit / Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($features as $feature)
                                            @php
                                                // Check if feature is attached to this package
                                                $pivot = $package->features->find($feature->id)?->pivot;
                                                $isEnabled = !is_null($pivot);
                                                $value = $pivot?->value;
                                            @endphp
                                            <tr>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <span class="fs-6 fw-bolder text-gray-800">{{ $feature->name }}</span>
                                                        <span class="text-muted fs-7">{{ $feature->description }}</span>
                                                        <span class="badge badge-light badge-sm mt-1 w-auto"
                                                            style="width: fit-content;">{{ ucfirst($feature->type) }}</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="form-check form-check-custom form-check-solid">
                                                        <input class="form-check-input" type="checkbox"
                                                            name="features[{{ $feature->id }}][enabled]" value="1"
                                                            id="feat_check_{{ $feature->id }}" {{ $isEnabled ? 'checked' : '' }}>
                                                    </div>
                                                </td>
                                                <td>
                                                    @if($feature->type === 'boolean')
                                                        <span class="text-muted">Enabled if checked</span>
                                                        <input type="hidden" name="features[{{ $feature->id }}][value]"
                                                            value="true">
                                                    @else
                                                        <input type="text" name="features[{{ $feature->id }}][value]"
                                                            class="form-control form-control-sm form-control-solid"
                                                            placeholder="e.g. 10 or Unlimited" value="{{ $value }}">
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="text-center pt-15">
                            <a href="{{ route('packages.index') }}" class="btn btn-light me-3">Discard</a>
                            <button type="submit" class="btn btn-primary">
                                <span class="indicator-label">Update Plan</span>
                            </button>
                        </div>
                    </form>
                </div>
                <!--end::Card body-->
            </div>
            <!--end::Card-->
        </div>
        <!--end::Container-->
    </div>
@endsection