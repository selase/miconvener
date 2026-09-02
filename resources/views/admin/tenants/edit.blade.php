@extends('layouts.admin.master')

@section('title', __('locale.labels.edit_tenant'))

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header border-0 pt-6">
                            <div class="card-title">
                                {{ __('Edit Tenant') }}
                            </div>
                        </div>
                        <div class="card-body py-4">
                            <form action="{{ route('tenants.update', $tenant->uuid) }}" method="post"
                                enctype="multipart/form-data">
                                @csrf
                                @method('PUT')
                                <div class="fv-row mb-7 mt-5">
                                    <label for="name"
                                        class="required fw-bold fs-6 mb-2">{{ __('locale.labels.name') }}</label>
                                    <input type="text" name="name"
                                        class="form-control form-control-solid mb-3 mb-lg-0 @error('name') is-invalid @enderror"
                                        value="{{ old('name', $tenant->name) }}" />
                                    @error('name')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div class="fv-row mb-7 mt-5">
                                    <label for="email"
                                        class="required fw-bold fs-6 mb-2">{{ __('locale.labels.email') }}</label>
                                    <input type="text" name="email"
                                        class="form-control form-control-solid mb-3 mb-lg-0 @error('email') is-invalid @enderror"
                                        value="{{ old('email', $tenant->email) }}" />
                                    @error('email')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div class="fv-row mb-7 mt-5">
                                    <label for="phone_number"
                                        class="required fw-bold fs-6 mb-2">{{ __('locale.labels.phone_number') }}</label>
                                    <input type="text" name="phone_number"
                                        class="form-control form-control-solid mb-3 mb-lg-0 @error('phone_number') is-invalid @enderror"
                                        value="{{ old('phone_number', $tenant->phone_number) }}" />
                                    @error('phone_number')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div class="fv-row mb-7 mt-5">
                                    <label for="address"
                                        class="required fw-bold fs-6 mb-2">{{ __('locale.labels.address') }}</label>
                                    <input type="text" name="address"
                                        class="form-control form-control-solid mb-3 mb-lg-0 @error('address') is-invalid @enderror"
                                        value="{{ old('address', $tenant->address) }}" />
                                    @error('address')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div class="fv-row mb-7 mt-5">
                                    <label for="country"
                                        class="required fw-bold fs-6 mb-2">{{ __('locale.labels.country') }}</label>
                                    <input type="text" name="country"
                                        class="form-control form-control-solid mb-3 mb-lg-0 @error('country') is-invalid @enderror"
                                        value="{{ old('country', $tenant->country) }}" />
                                    @error('country')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div class="fv-row mb-7 mt-5">
                                    <label for="city" class="fw-bold fs-6 mb-2">{{ __('locale.labels.city') }}</label>
                                    <input type="text" name="city"
                                        class="form-control form-control-solid mb-3 mb-lg-0 @error('city') is-invalid @enderror"
                                        value="{{ old('city', $tenant->city) }}" />
                                    @error('city')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div class="fv-row mb-7 mt-5">
                                    <label for="state_or_region"
                                        class="fw-bold fs-6 mb-2">{{ __('locale.labels.state_or_region') }}</label>
                                    <input type="text" name="state_or_region"
                                        class="form-control form-control-solid mb-3 mb-lg-0 @error('state_or_region') is-invalid @enderror"
                                        value="{{ old('state_or_region', $tenant->state) }}" />
                                    @error('state_or_region')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div class="fv-row mb-7 mt-5">
                                    <label for="zipcode" class="fw-bold fs-6 mb-2">{{ __('locale.labels.zipcode') }}</label>
                                    <input type="text" name="zipcode"
                                        class="form-control form-control-solid mb-3 mb-lg-0 @error('zipcode') is-invalid @enderror"
                                        value="{{ old('zipcode', $tenant->zipcode) }}" />
                                    @error('zipcode')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>


                                <div class="fv-row mb-7 mt-5">
                                    <label for="status"
                                        class="required fw-bold fs-6 mb-2">{{ __('locale.labels.status') }}</label>
                                    <select name="status" id="status"
                                        class="form-select form-select-solid mb-3 mb-lg-0 @error('status') is-invalid @enderror">
                                        <option selected>{{ __('locale.labels.select_an_option') }}</option>
                                        @foreach ($statuses as $status)
                                            <option value="{{ $status->value }}" {{ old('status', $tenant->status?->value) == $tenant->status?->value ? 'selected' : '' }}>
                                                {{ ucfirst($status->value) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('status')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div class="fv-row mb-7 mt-5">
                                    <label for="logo" class="fw-bold fs-6 mb-2">{{ __('locale.labels.logo') }}</label>
                                    <input type="file" name="logo"
                                        class="form-control form-control-solid mb-3 mb-lg-0 @error('logo') is-invalid @enderror"
                                        value="{{ old('logo') }}" />
                                    @error('logo')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="fv-row mb-7 mt-5">
                                    <label for="subdomain"
                                        class="required fw-bold fs-6 mb-2">{{ __('locale.labels.subdomain') }}</label>
                                    <input type="text" name="subdomain"
                                        class="form-control form-control-solid mb-3 mb-lg-0 @error('subdomain') is-invalid @enderror"
                                        value="{{ old('subdomain', $tenant->slug) }}" />
                                    @error('subdomain')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div class="fv-row mb-7 mt-5">
                                    <label for="package_id" class="fw-bold fs-6 mb-2">Subscription Plan</label>
                                    <select name="package_id" id="package_id"
                                        class="form-select form-select-solid mb-3 mb-lg-0 @error('package_id') is-invalid @enderror">
                                        <option value="">Select a Plan (Optional)</option>
                                        @foreach ($packages as $pkg)
                                            <option value="{{ $pkg->id }}" {{ old('package_id', $tenant->package_id) == $pkg->id ? 'selected' : '' }}>
                                                {{ $pkg->name }} ({{ $pkg->interval }} - ${{ $pkg->price }})
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('package_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="fv-row mb-7 mt-5">
                                    <div class="form-check form-switch form-check-custom form-check-solid">
                                        <input class="form-check-input" type="checkbox" 
                                               name="llm_byok" 
                                               value="1" 
                                               {{ $tenant->featureEnabled('llm_byok') ? 'checked' : '' }} />
                                        <label class="form-check-label px-3">
                                            {{ __('Enable Bring Your Own Key (BYOK)') }}
                                        </label>
                                    </div>
                                    <div class="text-muted fs-7 mt-2">Allows the tenant to configure their own LLM API keys.</div>
                                </div>

                                <div class="separator separator-dashed my-8"></div>
                                <h4 class="mb-5">LLM Restrictions & Rate Limiting</h4>

                                <div class="fv-row mb-7">
                                    <label class="fw-bold fs-6 mb-2">Allowed LLM Models</label>
                                    <select name="llm_models_whitelist[]" class="form-select form-select-solid" data-control="select2" data-placeholder="Select allowed models" data-allow-clear="true" multiple="multiple">
                                        @foreach($availableModels as $model)
                                            <option value="{{ $model }}" {{ in_array($model, $tenant->llm_models_whitelist ?? []) ? 'selected' : '' }}>{{ $model }}</option>
                                        @endforeach
                                    </select>
                                    <div class="text-muted fs-7 mt-2">Leave empty to allow all system-wide models.</div>
                                </div>

                                <div class="fv-row mb-7">
                                    <label class="fw-bold fs-6 mb-2">Allowed IP Addresses</label>
                                    <textarea name="allowed_ips" class="form-control form-control-solid" rows="2" placeholder="e.g. 192.168.1.1, 10.0.0.1">{{ is_array($tenant->allowed_ips) ? implode(', ', $tenant->allowed_ips) : $tenant->allowed_ips }}</textarea>
                                    <div class="text-muted fs-7 mt-2">Comma-separated list of IPs allowed to use the API. Leave empty for no restriction.</div>
                                </div>

                                <div class="fv-row mb-7">
                                    <label class="fw-bold fs-6 mb-2">Custom LLM Rate Limit (RPM)</label>
                                    <input type="number" name="custom_llm_limit" class="form-control form-control-solid" value="{{ old('custom_llm_limit', $tenant->custom_llm_limit) }}" placeholder="60" min="1" />
                                    <div class="text-muted fs-7 mt-2">Requests Per Minute. Default is 60 RPM if not set.</div>
                                </div>

                                <div class="separator separator-dashed my-8"></div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="fv-row mb-7 mt-5">
                                            <label for="isolation_mode" class="required fw-bold fs-6 mb-2">Isolation
                                                Mode</label>
                                            <select name="isolation_mode" id="isolation_mode"
                                                class="form-select form-select-solid mb-3 mb-lg-0 @error('isolation_mode') is-invalid @enderror">
                                                <option value="shared" {{ old('isolation_mode', $tenant->isolation_mode) == 'shared' ? 'selected' : '' }}>Shared Database
                                                </option>
                                                <option value="db_per_tenant" {{ old('isolation_mode', $tenant->isolation_mode) == 'db_per_tenant' ? 'selected' : '' }}>Dedicated
                                                    Database</option>
                                                <option value="byo" {{ old('isolation_mode', $tenant->isolation_mode) == 'byo' ? 'selected' : '' }}>BYO Strategy</option>
                                            </select>
                                            @error('isolation_mode')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="fv-row mb-7 mt-5">
                                            <label for="db_driver" class="required fw-bold fs-6 mb-2">Database
                                                Driver</label>
                                            <select name="db_driver" id="db_driver"
                                                class="form-select form-select-solid mb-3 mb-lg-0 @error('db_driver') is-invalid @enderror">
                                                <option value="mysql" {{ old('db_driver', $tenant->db_driver) == 'mysql' ? 'selected' : '' }}>MySQL</option>
                                                <option value="pgsql" {{ old('db_driver', $tenant->db_driver) == 'pgsql' ? 'selected' : '' }}>PostgreSQL</option>
                                            </select>
                                            @error('db_driver')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>

                                <div class="fv-row mb-7 mt-5" id="db_secret_field"
                                    style="{{ old('isolation_mode', $tenant->isolation_mode) === 'shared' ? 'display: none;' : '' }}">
                                    <label for="db_secret_ref" class="fw-bold fs-6 mb-2">DB Secret Reference
                                        (Optional)</label>
                                    <input type="text" name="db_secret_ref"
                                        class="form-control form-control-solid mb-3 mb-lg-0 @error('db_secret_ref') is-invalid @enderror"
                                        value="{{ old('db_secret_ref', $tenant->db_secret_ref) }}"
                                        placeholder="e.g. AWS Secret Manager ARN or path" />
                                    <div class="text-muted fs-7">If Dedicated or BYO is selected, leave blank to use default
                                        isolation settings.</div>
                                    @error('db_secret_ref')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header border-0 pt-6">
                                <div class="card-title">
                                    {{ __('Billing Strategy & Custom Rates') }}
                                </div>
                            </div>
                            <div class="card-body py-4">
                                <div class="fv-row mb-7 mt-5">
                                    <label class="fw-bold fs-6 mb-2">Tenant Specific Markup (%)</label>
                                    <input type="number" step="0.01" name="markup_percentage" class="form-control form-control-solid"
                                        value="{{ old('markup_percentage', $tenant->markup_percentage) }}" />
                                    <div class="text-muted fs-7 mt-2">Added cumulatively to Global + Package markups.</div>
                                </div>

                                <div class="separator separator-dashed my-8"></div>

                                <h4 class="mb-5">Negotiated Unit Rates (Overrides)</h4>
                                <div class="text-muted mb-5 fs-7">Set specific rates for this organization. If blank, the system will use Package or Global defaults.</div>
                                
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
                                                    $price = $tenant->usagePrices->where('metric', $metric)->first();
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
                                                               value="{{ $price?->unit_price }}" placeholder="Inherit">
                                                    </td>
                                                    <td>
                                                        <input type="number" step="1" name="usage_prices[{{ $metric->value }}][unit_quantity]" 
                                                               class="form-control form-control-sm form-control-solid" 
                                                               value="{{ $price?->unit_quantity }}" placeholder="Inherit">
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <div class="fv-row mb-7 mt-10">
                                    <button type="submit"
                                        class="btn btn-primary">{{ __('locale.buttons.save_changes') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
        </div>
    </div>
@endsection

@push('custom-scripts')
    <script>
        document.getElementById('isolation_mode').addEventListener('change', function () {
            const secretField = document.getElementById('db_secret_field');
            if (this.value === 'shared') {
                secretField.style.display = 'none';
            } else {
                secretField.style.display = 'block';
            }
        });
    </script>
    <script src="{{ asset('js/scripts.js') }}"></script>
@endpush