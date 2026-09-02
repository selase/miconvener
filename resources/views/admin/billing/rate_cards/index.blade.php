@extends('layouts.admin.master')

@section('title', 'Global Rate Cards & Taxes')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        
        <div class="row g-5 g-xl-10">
            <!-- Global Unit Prices -->
            <div class="col-xl-8">
                <div class="card card-flush shadow-sm">
                    <div class="card-header pt-7">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-bolder text-dark">Default Rate Card</span>
                            <span class="text-muted mt-1 fw-bold fs-7">Global system-wide unit prices for metered usage</span>
                        </h3>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('admin.billing.rate-cards.prices.update') }}" method="POST">
                            @csrf
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
                                                $price = $globalPrices->where('metric', $metric)->first();
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
                            <div class="d-flex justify-content-end mt-5">
                                <button type="submit" class="btn btn-primary">Update Global Rates</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Tax Rules -->
            <div class="col-xl-4">
                <div class="card card-flush shadow-sm">
                    <div class="card-header pt-7">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-bolder text-dark">Tax Configuration</span>
                            <span class="text-muted mt-1 fw-bold fs-7">Applied to subtotal sequentially</span>
                        </h3>
                        <div class="card-toolbar">
                            <button type="button" class="btn btn-sm btn-light-primary" data-bs-toggle="modal" data-bs-target="#addTaxModal">
                                Add Tax Rule
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        @foreach($taxes as $tax)
                            <div class="d-flex flex-stack border border-dashed border-gray-300 rounded p-4 mb-3">
                                <div class="d-flex flex-column">
                                    <span class="fs-6 fw-bolder text-gray-800">{{ $tax->name }} ({{ $tax->rate }}%)</span>
                                    <span class="text-muted fs-7">
                                        {{ $tax->is_compound ? 'Compound' : 'Flat' }} | Priority: {{ $tax->priority }}
                                        @if(!$tax->is_active)
                                            <span class="badge badge-light-danger ms-2">Disabled</span>
                                        @endif
                                    </span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <button class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm"
                                            data-bs-toggle="modal" data-bs-target="#editTaxModal{{ $tax->id }}">
                                        <i class="fas fa-edit fs-5"></i>
                                    </button>
                                    <form action="{{ route('admin.billing.rate-cards.taxes.destroy', $tax) }}" method="POST" onsubmit="return confirm('Delete this tax rule?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-icon btn-bg-light btn-active-color-danger btn-sm">
                                            <i class="fas fa-trash fs-5"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Edit Tax Modal -->
                            <div class="modal fade" id="editTaxModal{{ $tax->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered mw-450px">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h2 class="fw-bolder">Edit Tax: {{ $tax->name }}</h2>
                                            <div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
                                                <i class="fas fa-times"></i>
                                            </div>
                                        </div>
                                        <form action="{{ route('admin.billing.rate-cards.taxes.update', $tax) }}" method="POST">
                                            @csrf
                                            @method('PUT')
                                            <div class="modal-body py-10 px-lg-17">
                                                <div class="fv-row mb-7">
                                                    <label class="required fw-bold fs-6 mb-2">Rule Name</label>
                                                    <input type="text" name="name" class="form-control form-control-solid" value="{{ $tax->name }}" required>
                                                </div>
                                                <div class="row g-9 mb-7">
                                                    <div class="col-md-6 fv-row">
                                                        <label class="required fw-bold fs-6 mb-2">Rate (%)</label>
                                                        <input type="number" step="0.01" name="rate" class="form-control form-control-solid" value="{{ $tax->rate }}" required>
                                                    </div>
                                                    <div class="col-md-6 fv-row">
                                                        <label class="required fw-bold fs-6 mb-2">Priority</label>
                                                        <input type="number" name="priority" class="form-control form-control-solid" value="{{ $tax->priority }}" required>
                                                    </div>
                                                </div>
                                                <div class="fv-row mb-5">
                                                    <div class="form-check form-switch form-check-custom form-check-solid">
                                                        <input class="form-check-input" type="checkbox" name="is_compound" value="1" {{ $tax->is_compound ? 'checked' : '' }}>
                                                        <label class="form-check-label fw-bold">Compound Tax?</label>
                                                    </div>
                                                </div>
                                                <div class="fv-row">
                                                    <div class="form-check form-switch form-check-custom form-check-solid">
                                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" {{ $tax->is_active ? 'checked' : '' }}>
                                                        <label class="form-check-label fw-bold">Is Active?</label>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer flex-center">
                                                <button type="reset" class="btn btn-light me-3" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Save Rule</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Add Tax Modal -->
<div class="modal fade" id="addTaxModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mw-450px">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="fw-bolder">Add New Tax Rule</h2>
                <div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i>
                </div>
            </div>
            <form action="{{ route('admin.billing.rate-cards.taxes.store') }}" method="POST">
                @csrf
                <div class="modal-body py-10 px-lg-17">
                    <div class="fv-row mb-7">
                        <label class="required fw-bold fs-6 mb-2">Rule Name</label>
                        <input type="text" name="name" class="form-control form-control-solid" placeholder="e.g. VAT" required>
                    </div>
                    <div class="row g-9 mb-7">
                        <div class="col-md-6 fv-row">
                            <label class="required fw-bold fs-6 mb-2">Rate (%)</label>
                            <input type="number" step="0.01" name="rate" class="form-control form-control-solid" placeholder="15.00" required>
                        </div>
                        <div class="col-md-6 fv-row">
                            <label class="required fw-bold fs-6 mb-2">Priority</label>
                            <input type="number" name="priority" class="form-control form-control-solid" value="1" required>
                        </div>
                    </div>
                    <div class="fv-row mb-5">
                        <div class="form-check form-switch form-check-custom form-check-solid">
                            <input class="form-check-input" type="checkbox" name="is_compound" value="1">
                            <label class="form-check-label fw-bold">Compound Tax?</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer flex-center">
                    <button type="reset" class="btn btn-light me-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Rule</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
