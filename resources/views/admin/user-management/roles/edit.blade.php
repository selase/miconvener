@extends('layouts.admin.master')

@section('title', __('locale.labels.roles'))

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <!--begin::Container-->
    <div id="kt_content_container" class="container-xxl">
        <!--begin::Row-->
        <div class="col-md-12">
            <div class="card card-flush h-md-100">
                <div class="card-header">
                    <div class="card-title">
                        <h2>{{ __('locale.labels.update_role') }}</h2>
                    </div>
                </div>
                <div class="pt-1 card-body">
                    <form id="kt_modal_update_role_form" class="form" action="{{ route('roles.update', $role->id) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <div class="d-flex flex-column scroll-y me-n7 pe-7" id="kt_modal_update_role_scroll" data-kt-scroll="true" data-kt-scroll-activate="{default: false, lg: true}" data-kt-scroll-max-height="auto" data-kt-scroll-dependencies="#kt_modal_update_role_header" data-kt-scroll-wrappers="#kt_modal_update_role_scroll" data-kt-scroll-offset="300px">
                            <div class="mb-10 fv-row">
                                <label class="mb-2 fs-5 fw-bolder form-label">
                                    <span class="required">Role name</span>
                                </label>
                                <input class="form-control form-control-solid @error('name')
                                    is-invalid
                                @enderror"  placeholder="Enter a role name" name="name" value="{{ old('name',  isset($role->name) ? $role->name : null) }}" />
                                @error('name')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                            <!--begin::Permissions-->
                            <div class="fv-row">
                                <label class="mb-2 fs-5 fw-bolder form-label">Role Permissions</label>
                                <div class="table-responsive">
                                    <table class="table align-middle table-row-dashed fs-6 gy-5">
                                        <tbody class="text-gray-600 fw-bold">
                                            <tr>
                                                <td class="text-gray-800 text-uppercase">Administrator Access
                                                <i class="fas fa-exclamation-circle ms-1 fs-7" data-bs-toggle="tooltip" title="Allows a full access to the system"></i></td>
                                                <td>
                                                    <label class="form-check form-check-sm form-check-custom form-check-solid me-9">
                                                        <input class="form-check-input" type="checkbox" value="" id="kt_roles_select_all" />
                                                        <span class="form-check-label" for="kt_roles_select_all">Select all</span>
                                                    </label>
                                                </td>
                                            </tr>
                                            @foreach ($permissions as $key => $category)
                                            <tr>
                                                <td class="text-gray-800 text-uppercase">{{ ucwords($key) }}</td>
                                                <td>
                                                    @foreach ($category as $permission)
                                                        <div class="d-flex">
                                                            <label class="form-check form-check-sm form-check-custom form-check-solid me-5 me-lg-20">
                                                            <input class="form-check-input" type="checkbox"
                                                                value="{{ $permission->id }}"
                                                                @foreach ($existing_permissions as $role_permission)
                                                                    @if ($role_permission->id == $permission->id)
                                                                        checked
                                                                    @endif
                                                                @endforeach
                                                                name="permissions[]" />
                                                            <span class="form-check-label">{{ $permission->name }}</span>
                                                            </label>
                                                        </div>
                                                    @endforeach
                                                </td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="text-center pt-15">
                            <button type="reset" class="btn btn-light me-3">Discard</button>
                            <button type="submit" id="updateRoleButton" class="btn btn-primary">
                                <span class="indicator-label">Submit</span>
                                <span class="indicator-progress">Please wait...
                                <span class="align-middle spinner-border spinner-border-sm ms-2"></span></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
@push('custom-scripts')
    <script>
        $(document).ready(function() {
            let allPermissions = $('#kt_roles_select_all'),
                checkbox = $('[type="checkbox"]');
                allPermissions.on('change', function() {
                    if(this.checked) {
                        checkbox.each(function() {
                            this.checked = true;
                        });
                    } else {
                        checkbox.each(function() {
                            this.checked = false;
                        });
                    }
                });
        })

    </script>
@endpush
