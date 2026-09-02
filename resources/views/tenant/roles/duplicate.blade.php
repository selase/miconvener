@extends('layouts.admin.master')

@section('title', 'Duplicate Role')

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            <div class="card">
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h2>Duplicate Role: {{ $sourceRole->name }}</h2>
                    </div>
                    <div class="card-toolbar">
                        <span class="badge badge-light-info fs-7">Based on {{ $sourceRole->name }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <form action="{{ route('tenant.roles.duplicate', ['subdomain' => request()->route('subdomain'), 'role' => $sourceRole->id]) }}"
                        method="POST">
                        @csrf
                        <input type="hidden" name="source_role_id" value="{{ $sourceRole->id }}" />

                        <div class="fv-row mb-10">
                            <label class="fs-5 fw-bold mb-2">Role Name</label>
                            <input type="text" name="name" class="form-control form-control-solid border-0 fs-4"
                                placeholder="e.g. Senior Editor" value="{{ old('name', $sourceRole->name . ' (Custom)') }}" required />
                            @error('name')
                                <div class="text-danger fs-7 mt-2">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="fv-row">
                            <label class="fs-5 fw-bold mb-5">Role Permissions</label>
                            <div class="table-responsive">
                                <table class="table align-middle table-row-dashed fs-6 gy-5">
                                    <tbody class="text-gray-600 fw-semibold">
                                        <tr>
                                            <td class="text-gray-800">Administrator Access
                                            <span class="ms-2" data-bs-toggle="tooltip" title="Allows a full access to the system">
                                                <i class="fas fa-exclamation-circle fs-7"></i>
                                            </span></td>
                                            <td>
                                                <label class="form-check form-check-custom form-check-solid me-9">
                                                    <input class="form-check-input" type="checkbox" value="" id="kt_roles_select_all" />
                                                    <span class="form-check-label" for="kt_roles_select_all">Select all</span>
                                                </label>
                                            </td>
                                        </tr>

                                        @foreach($permissions->groupBy('category') as $category => $perms)
                                            <tr>
                                                <td class="text-gray-800 text-capitalize">{{ str_replace('-', ' ', $category) }}</td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-4">
                                                        @foreach($perms as $permission)
                                                            <label class="form-check form-check-sm form-check-custom form-check-solid me-5 me-lg-20">
                                                                <input class="form-check-input permission-checkbox" type="checkbox"
                                                                    value="{{ $permission->name }}" name="permissions[]"
                                                                    {{ in_array($permission->name, $sourcePermissionNames) ? 'checked' : '' }} />
                                                                <span class="form-check-label">{{ explode(' ', $permission->name)[0] }}</span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="text-center pt-15">
                            <a href="{{ route('tenant.roles.index', ['subdomain' => request()->route('subdomain')]) }}" class="btn btn-light me-3">Discard</a>
                            <button type="submit" class="btn btn-primary">
                                <span class="indicator-label">Create Duplicated Role</span>
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
