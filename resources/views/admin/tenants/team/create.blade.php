@extends('layouts.admin.master')

@section('title', __('locale.buttons.create'))

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header border-0 pt-6">
                        <div class="card-title">
                            {{ __('Add Team Member') }}
                        </div>
                    </div>
                    <div class="card-body py-4">
                       <form action="{{ route('tenants.team.store', $tenant->uuid) }}" enctype="multipart/form-data" method="post">
                            @csrf
                            <div class="fv-row mb-7 mt-5">
                                <label for="first_name" class="required fw-bold fs-6 mb-2">{{ __('locale.labels.first_name') }}</label>
                                <input
                                    type="text"
                                    name="first_name"
                                    class="form-control form-control-solid mb-3 mb-lg-0 @error('first_name') is-invalid @enderror"
                                    value="{{ old('first_name') }}"
                                />
                                @error('first_name')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <div class="fv-row mb-7 mt-5">
                                <label for="last_name" class="required fw-bold fs-6 mb-2">{{ __('locale.labels.last_name') }}</label>
                                <input
                                    type="text"
                                    name="last_name"
                                    class="form-control form-control-solid mb-3 mb-lg-0 @error('last_name') is-invalid @enderror"
                                    value="{{ old('last_name') }}"
                                />
                                @error('last_name')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <div class="fv-row mb-7 mt-5">
                                <label for="email" class="required fw-bold fs-6 mb-2">{{ __('locale.labels.email') }}</label>
                                <input
                                    type="text"
                                    name="email"
                                    class="form-control form-control-solid mb-3 mb-lg-0 @error('email') is-invalid @enderror"
                                    value="{{ old('email') }}"
                                />
                                @error('email')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <div class="fv-row mb-7 mt-5">
                                <label for="phone_number" class="fw-bold fs-6 mb-2">{{ __('locale.labels.phone_number') }}</label>
                                <input
                                    type="text"
                                    name="phone_number"
                                    class="form-control form-control-solid mb-3 mb-lg-0 @error('phone_number') is-invalid @enderror"
                                    value="{{ old('phone_number') }}"
                                />
                                @error('phone_number')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <div class="fv-row mb-7 mt-5">
                                <label for="roles" class="required fw-bold fs-6 mb-2">{{ __('Roles') }}</label>
                                <select
                                    name="roles[]"
                                    id="roles"
                                    class="form-select form-select-solid mb-3 mb-lg-0 @error('roles') is-invalid @enderror"
                                    data-control="select2"
                                    data-placeholder="Select options"
                                    multiple="multiple"
                                >
                                    <option></option>
                                    @foreach ($roles as $role)
                                        @if ($role->name !== 'Superadmin')
                                            <option value="{{ $role->id }}" {{ in_array($role->id, old('roles', [])) ? 'selected' : '' }}>{{ $role->name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                @error('roles')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <div class="fv-row mb-7 mt-5">
                                <label for="status" class="required fw-bold fs-6 mb-2">{{ __('locale.labels.status') }}</label>
                                <select
                                    name="status"
                                    id="status"
                                    class="form-select form-select-solid mb-3 mb-lg-0 @error('status') is-invalid @enderror"
                                    data-control="select2"
                                    data-placeholder="Select an option"
                                >
                                    <option></option>
                                    <option value="active">{{ __('locale.labels.active') }}</option>
                                    <option value="inactive">{{ __('locale.labels.inactive') }}</option>
                                </select>
                                @error('status')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <div class="fv-row mb-7 mt-5">
                                <label for="photo" class="fw-bold fs-6 mb-2">{{ __('locale.labels.profile_photo') }}</label>
                                <input
                                    type="file"
                                    name="photo"
                                    class="form-control form-control-solid mb-3 mb-lg-0 @error('photo') is-invalid @enderror"
                                    value="{{ old('photo') }}"
                                />
                                @error('photo')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <input type="hidden" name="tenant_id" value="{{ $tenant->id }}">


                            <div class="fv-row mb-7 mt-5">
                                <button type="submit" class="btn btn-primary">{{ __('locale.buttons.create_team') }}</button>
                            </div>
                       </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('custom-scripts')
    <script src="{{ asset('js/scripts.js') }}"></script>
@endpush
