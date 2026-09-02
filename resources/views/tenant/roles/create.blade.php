@extends('layouts.admin.master')

@section('title', 'Create Custom Role')

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            <div class="card">
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h2>Create Custom Role</h2>
                    </div>
                </div>
                <div class="card-body">
                    <form action="{{ route('tenant.roles.store', ['subdomain' => request()->route('subdomain')]) }}"
                        method="POST">
                        @csrf
                        <div class="fv-row mb-10">
                            <label class="fs-5 fw-bold mb-2">Role Name</label>
                            <input type="text" name="name" class="form-control form-control-solid border-0 fs-4" placeholder="e.g. Hospital Intern" value="{{ old('name') }}" required />
                        </div>

                        <div class="fv-row">
                            <label class="fs-5 fw-bold mb-5">Role Permissions</label>
                            <!--begin::Table wrapper-->
                            <div class="table-responsive">
                                <!--begin::Table-->
                                <table class="table align-middle table-row-dashed fs-6 gy-5">
                                    <tbody class="text-gray-600 fw-semibold">
                                        <!--begin::Table row-->
                                        <tr>
                                            <td class="text-gray-800">Administrator Access 
                                            <span class="ms-2" data-bs-toggle="tooltip" title="Allows a full access to the system">
                                                <i class="fas fa-exclamation-circle fs-7"></i>
                                            </span></td>
                                            <td>
                                                <!--begin::Checkbox-->
                                                <label class="form-check form-check-custom form-check-solid me-9">
                                                    <input class="form-check-input" type="checkbox" value="" id="kt_roles_select_all" />
                                                    <span class="form-check-label" for="kt_roles_select_all">Select all</span>
                                                </label>
                                                <!--end::Checkbox-->
                                            </td>
                                        </tr>
                                        <!--end::Table row-->

                                        @foreach($permissions->groupBy('category') as $category => $perms)
                                            <!--begin::Table row-->
                                            <tr>
                                                <td class="text-gray-800 text-capitalize">{{ str_replace('-', ' ', $category) }}</td>
                                                <td>
                                                    <!--begin::Wrapper-->
                                                    <div class="d-flex flex-wrap gap-4">
                                                        @foreach($perms as $permission)
                                                            <!--begin::Checkbox-->
                                                            <label class="form-check form-check-sm form-check-custom form-check-solid me-5 me-lg-20">
                                                                <input class="form-check-input permission-checkbox" type="checkbox" value="{{ $permission->name }}" name="permissions[]" />
                                                                <span class="form-check-label">{{ explode(' ', $permission->name)[0] }}</span>
                                                            </label>
                                                            <!--end::Checkbox-->
                                                        @endforeach
                                                    </div>
                                                    <!--end::Wrapper-->
                                                </td>
                                            </tr>
                                            <!--end::Table row-->
                                        @endforeach
                                    </tbody>
                                </table>
                                <!--end::Table-->
                            </div>
                            <!--end::Table wrapper-->
                        </div>

                        <div class="text-center pt-15">
                            <a href="{{ route('tenant.roles.index', ['subdomain' => request()->route('subdomain')]) }}" class="btn btn-light me-3">Discard</a>
                            <button type="submit" class="btn btn-primary">
                                <span class="indicator-label">Create Role</span>
                                <span class="indicator-progress">Please wait... 
                                <span class="spinner-border spinner-border-sm align-middle ms-2"></span></span>
                            </button>
                        </div>

                        @push('custom-scripts')
                        <script>
                            document.getElementById('kt_roles_select_all').addEventListener('change', function(e) {
                                document.querySelectorAll('.permission-checkbox').forEach(cb => {
                                    cb.checked = e.target.checked;
                                });
                            });
                        </script>
                        @endpush
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection