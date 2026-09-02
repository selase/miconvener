@extends('layouts.admin.master')

@section('title', 'Create Role')

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <!--begin::Container-->
        <div id="kt_content_container" class="container-xxl">
            <!--begin::Card-->
            <div class="card">
                <!--begin::Card header-->
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h3 class="card-label">Create Global Role</h3>
                    </div>
                </div>
                <!--end::Card header-->
                <!--begin::Card body-->
                <div class="card-body">
                    <form id="createRoleForm" class="form" action="{{ route('roles.store') }}" method="POST">
                        @csrf

                        <div class="fv-row mb-7">
                            <label class="required fw-bold fs-6 mb-2">Role Name</label>
                            <input type="text" name="name" class="form-control form-control-solid mb-3 mb-lg-0"
                                placeholder="e.g. Audit Manager" value="{{ old('name') }}" required />
                            <div class="form-text text-muted">This name will be visible globally or within the selected
                                tenant.</div>
                        </div>

                        <div class="fv-row mb-7">
                            <label class="fw-bold fs-6 mb-2">Assign to Tenant (Optional)</label>
                            <select name="tenant_id" class="form-select form-select-solid" data-control="select2"
                                data-placeholder="Select a Tenant (Leave empty for Global)">
                                <option value=""></option>
                                @foreach($tenants as $tenant)
                                    <option value="{{ $tenant->id }}" {{ old('tenant_id') == $tenant->id ? 'selected' : '' }}>
                                        {{ $tenant->name }} ({{ $tenant->slug }})
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text text-muted">If selected, this role will only be available to the specific
                                organization.</div>
                        </div>

                        <div class="fv-row mb-10">
                            <label class="required fw-bold fs-6 mb-5">Permissions</label>

                            <div class="table-responsive">
                                <table class="table align-middle table-row-dashed fs-6 gy-5">
                                    <thead>
                                        <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                            <th class="min-w-150px">Resource</th>
                                            <th class="min-w-300px">Permissions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-gray-600 fw-bold">
                                        {{-- Group permissions by prefix or model if possible, for now we list all --}}
                                        @php
                                            // Simple grouping logic for display (can be moved to controller later)
                                            $groupedPermissions = $permissions->groupBy(function ($perm) {
                                                $parts = explode(' ', $perm->name);
                                                return count($parts) > 1 ? end($parts) : 'Other'; // Group by last word (e.g. 'user', 'role')
                                            });
                                        @endphp

                                        @foreach($groupedPermissions as $group => $perms)
                                            <tr>
                                                <td>{{ ucfirst($group) }}</td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-3">
                                                        @foreach($perms as $permission)
                                                            <div class="form-check form-check-custom form-check-solid">
                                                                <input class="form-check-input" type="checkbox" name="permissions[]"
                                                                    value="{{ $permission->name }}"
                                                                    id="perm_{{ $permission->id }}" />
                                                                <label class="form-check-label" for="perm_{{ $permission->id }}">
                                                                    {{ $permission->name }}
                                                                </label>
                                                            </div>
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
                            <a href="{{ route('roles.index') }}" class="btn btn-light me-3">Discard</a>
                            <button type="submit" class="btn btn-primary">
                                <span class="indicator-label">Submit</span>
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