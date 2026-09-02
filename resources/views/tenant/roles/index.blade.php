@extends('layouts.admin.master')

@section('title', 'Roles & Permissions')

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">

            @if(session('success'))
                <div class="alert alert-success d-flex align-items-center p-5 mb-5">
                    <i class="fas fa-check-circle fs-2hx text-success me-4"></i>
                    <div>{{ session('success') }}</div>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger d-flex align-items-center p-5 mb-5">
                    <i class="fas fa-exclamation-triangle fs-2hx text-danger me-4"></i>
                    <div>{{ session('error') }}</div>
                </div>
            @endif

            {{-- Platform Roles Section --}}
            <div class="card mb-6">
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h2><i class="fas fa-shield-alt text-warning me-2"></i>Platform Roles</h2>
                    </div>
                    <div class="card-toolbar">
                        <span class="badge badge-light-warning fs-7">Read-only</span>
                    </div>
                </div>
                <div class="card-body py-4">
                    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-5 g-xl-9">
                        @foreach($systemRoles as $role)
                            <div class="col-md-4">
                                <div class="card card-flush h-md-100 border border-dashed border-gray-300">
                                    <div class="card-header">
                                        <div class="card-title">
                                            <h2>{{ $role->name }}</h2>
                                            <i class="fas fa-lock text-gray-400 ms-2" data-bs-toggle="tooltip" title="Platform-managed role"></i>
                                        </div>
                                    </div>
                                    <div class="card-body pt-1">
                                        <div class="d-flex flex-column text-gray-600">
                                            @foreach($role->permissions->take(5) as $permission)
                                                <div class="d-flex align-items-center py-2">
                                                    <span class="bullet bg-warning me-3"></span>{{ $permission->name }}
                                                </div>
                                            @endforeach
                                            @if($role->permissions->count() > 5)
                                                <div class='d-flex align-items-center py-2'>
                                                    <span class='bullet bg-warning me-3'></span>
                                                    <em>and {{ $role->permissions->count() - 5 }} more...</em>
                                                </div>
                                            @endif
                                            @if($role->permissions->isEmpty())
                                                <div class="text-muted fs-7">No specific permissions assigned.</div>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="card-footer flex-wrap pt-0">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="badge badge-light-warning fw-bold">System Role</span>
                                            <div class="d-flex">
                                                <a href="{{ route('tenant.roles.show', ['subdomain' => request()->route('subdomain'), 'role' => $role->id]) }}"
                                                    class="btn btn-sm btn-light btn-active-primary me-2">View</a>
                                                <a href="{{ route('tenant.roles.duplicate.form', ['subdomain' => request()->route('subdomain'), 'role' => $role->id]) }}"
                                                    class="btn btn-sm btn-light-primary">
                                                    <i class="fas fa-copy me-1"></i>Duplicate
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Custom Roles Section --}}
            <div class="card">
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h2>Custom Roles</h2>
                    </div>
                    <div class="card-toolbar">
                        <a href="{{ route('tenant.roles.create', ['subdomain' => request()->route('subdomain')]) }}"
                            class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i>Add Custom Role
                        </a>
                    </div>
                </div>
                <div class="card-body py-4">
                    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-5 g-xl-9">
                        @foreach($customRoles as $role)
                            <div class="col-md-4">
                                <div class="card card-flush h-md-100">
                                    <div class="card-header">
                                        <div class="card-title">
                                            <h2>{{ $role->name }}</h2>
                                        </div>
                                        @if($role->cloned_from_role_id && $role->clonedFromRole)
                                            <div class="card-toolbar">
                                                <span class="badge badge-light-info fs-8">Based on {{ $role->clonedFromRole->name }}</span>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="card-body pt-1">
                                        <div class="fw-bold text-gray-600 mb-5">Total users with this role:
                                            {{ $role->users_count ?? 0 }}</div>
                                        <div class="d-flex flex-column text-gray-600">
                                            @foreach($role->permissions->take(5) as $permission)
                                                <div class="d-flex align-items-center py-2">
                                                    <span class="bullet bg-primary me-3"></span>{{ $permission->name }}
                                                </div>
                                            @endforeach
                                            @if($role->permissions->count() > 5)
                                                <div class='d-flex align-items-center py-2'>
                                                    <span class='bullet bg-primary me-3'></span>
                                                    <em>and {{ $role->permissions->count() - 5 }} more...</em>
                                                </div>
                                            @endif
                                            @if($role->permissions->isEmpty())
                                                <div class="text-muted fs-7">No specific permissions assigned.</div>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="card-footer flex-wrap pt-0">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="badge badge-light-primary fw-bold">Custom Role</span>
                                            <div class="d-flex">
                                                <a href="{{ route('tenant.roles.show', ['subdomain' => request()->route('subdomain'), 'role' => $role->id]) }}"
                                                    class="btn btn-sm btn-light btn-active-primary me-2">View</a>
                                                <a href="{{ route('tenant.roles.edit', ['subdomain' => request()->route('subdomain'), 'role' => $role->id]) }}"
                                                    class="btn btn-sm btn-light btn-active-light-primary me-2">Edit</a>
                                                @if(($role->users_count ?? 0) > 0)
                                                    <button type="button" class="btn btn-sm btn-light btn-active-light-danger" disabled
                                                        data-bs-toggle="tooltip" title="{{ $role->users_count }} user(s) assigned — reassign before deleting">Delete</button>
                                                @else
                                                    <form action="{{ route('tenant.roles.destroy', ['subdomain' => request()->route('subdomain'), 'role' => $role->id]) }}"
                                                        method="POST" onsubmit="return confirm('Delete this role? This cannot be undone.');">
                                                        @csrf @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-light btn-active-light-danger">Delete</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        {{-- Add new card --}}
                        <div class="col-md-4">
                            <div class="card h-md-100">
                                <div class="card-body d-flex flex-center">
                                    <a href="{{ route('tenant.roles.create', ['subdomain' => request()->route('subdomain')]) }}"
                                        class="btn btn-clear d-flex flex-column flex-center">
                                        <img src="{{ asset('assets/media/illustrations/sketchy-1/4.png') }}" alt=""
                                            class="mw-100 mh-100px mb-7" />
                                        <div class="fw-bold fs-3 text-gray-600 text-hover-primary">Add New Role</div>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
