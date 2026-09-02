@extends('layouts.admin.master')

@section('title', 'View Role Details')

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            <div class="d-flex flex-column flex-lg-row">
                <!--begin::Aside-->
                <div class="flex-column flex-lg-row-auto w-100 w-lg-300px mb-10">
                    <!--begin::Card-->
                    <div class="card card-flush">
                        <!--begin::Card header-->
                        <div class="card-header">
                            <!--begin::Card title-->
                            <div class="card-title">
                                <h2 class="mb-0">{{ $role->name }}</h2>
                            </div>
                            <!--end::Card title-->
                        </div>
                        <!--end::Card header-->
                        <!--begin::Card body-->
                        <div class="card-body pt-0">
                            <!--begin::Details-->
                            <div class="d-flex flex-column text-gray-600">
                                <div class="d-flex align-items-center py-2">
                                    <span class="bullet bg-primary me-3"></span>
                                    Scope: {{ $role->tenant_id ? 'Custom (Organization)' : 'System' }}
                                </div>
                                @if($role->cloned_from_role_id)
                                    <div class="d-flex align-items-center py-2">
                                        <span class="bullet bg-info me-3"></span>
                                        Based on: {{ $role->clonedFromRole?->name ?? '[Deleted platform role]' }}
                                    </div>
                                @endif
                                <div class="d-flex align-items-center py-2">
                                    <span class="bullet bg-primary me-3"></span>
                                    Guard: {{ $role->guard_name }}
                                </div>
                                <div class="d-flex align-items-center py-2">
                                    <span class="bullet bg-primary me-3"></span>
                                    Created: {{ $role->created_at->format('M d, Y') }}
                                </div>
                            </div>
                            <!--end::Details-->
                        </div>
                        <!--end::Card body-->
                        <!--begin::Card footer-->
                        <div class="card-footer pt-0">
                            @if($role->tenant_id && !$role->isSystemRole())
                                <a href="{{ route('tenant.roles.edit', ['subdomain' => request()->route('subdomain'), 'role' => $role->id]) }}"
                                    class="btn btn-light btn-active-primary">Edit Role</a>
                            @else
                                <div class="alert alert-light-warning d-flex align-items-center p-5">
                                    <i class="fas fa-shield-alt fs-2hx text-warning me-4"></i>
                                    <div class="d-flex flex-column">
                                        <h4 class="mb-1 text-warning">System Role</h4>
                                        <span class="fs-7">This role is protected and cannot be modified.</span>
                                    </div>
                                </div>
                            @endif
                        </div>
                        <!--end::Card footer-->
                    </div>
                    <!--end::Card-->
                </div>
                <!--end::Aside-->
                <!--begin::Content-->
                <div class="flex-lg-row-fluid ms-lg-10">
                    <!--begin::Card-->
                    <div class="card card-flush mb-6 mb-xl-9">
                        <!--begin::Card header-->
                        <div class="card-header pt-5">
                            <div class="card-title">
                                <h2 class="d-flex align-items-center">Role Permissions
                                    <span class="text-gray-400 fs-7 ms-2">({{ $role->permissions->count() }})</span>
                                </h2>
                            </div>
                        </div>
                        <!--end::Card header-->
                        <!--begin::Card body-->
                        <div class="card-body pt-0">
                            <div class="table-responsive">
                                <table class="table align-middle table-row-dashed fs-6 gy-5">
                                    <thead>
                                        <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                            <th class="min-w-150px">Resource</th>
                                            <th class="min-w-300px">Assigned Permissions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-gray-600 fw-bold">
                                        @foreach($role->permissions->groupBy('category') as $category => $perms)
                                            <tr>
                                                <td class="text-gray-800 text-capitalize">{{ str_replace('-', ' ', $category) }}
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        @foreach($perms as $permission)
                                                            <span class="badge badge-light-primary">{{ $permission->name }}</span>
                                                        @endforeach
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                        @if($role->permissions->isEmpty())
                                            <tr>
                                                <td colspan="2" class="text-center text-muted py-10">
                                                    No permissions assigned to this role.
                                                </td>
                                            </tr>
                                        @endif
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <!--end::Card body-->
                    </div>
                    <!--end::Card-->
                </div>
                <!--end::Content-->
            </div>
        </div>
    </div>
@endsection