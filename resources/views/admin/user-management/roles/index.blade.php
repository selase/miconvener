@extends('layouts.admin.master')

@section('title', __('locale.labels.roles'))

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <!--begin::Container-->
        <div id="kt_content_container" class="container-xxl">
            <!--begin::Row-->
            <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-5 g-xl-9">
                @forelse ($roles as $role)
                    <div class="col-md-4">
                        <!--begin::Card-->
                        <div class="card card-flush h-md-100">
                            <div class="card-header">
                                <div class="card-title">
                                    <h2>{{ $role->name }}</h2>
                                </div>
                            </div>
                            <div class="card-body pt-1">
                                <div class="fw-bolder text-gray-600 mb-5">Total users with this role: {{ $role->users_count }}
                                </div>
                                <div class="d-flex flex-column text-gray-600">
                                    @foreach ($role->permissions->slice(0, 5) as $permission)
                                        <div class="d-flex align-items-center py-2">
                                            <span class="bullet bg-primary me-3"></span>{{ $permission->name }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="card-footer flex-wrap pt-0">
                                <a href="#" class="btn btn-light btn-active-primary my-1 me-2">View Role</a>
                                <a href="{{ route('roles.edit', $role->id) }}"
                                    class="btn btn-light btn-active-primary my-1">Edit Role</a>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-12">
                        <div class="card card-flush h-md-100">
                            <div class="card-body d-flex flex-column flex-center py-20">
                                <img src="{{ asset('assets/media/illustrations/sketchy-1/17.png') }}" class="mw-350px mb-7" />
                                <h3 class="fw-bolder text-dark">No Roles Defined</h3>
                                <p class="text-gray-400">Create roles to manage permissions for your team members.</p>
                            </div>
                        </div>
                    </div>
                @endforelse

                <div class="col-md-4">
                    <div class="card h-md-100">
                        <div class="card-body d-flex flex-center">
                            <button type="button" class="btn btn-clear d-flex flex-column flex-center"
                                data-bs-toggle="modal" data-bs-target="#kt_modal_add_role">
                                <img src="{{ asset('assets/media/illustrations/sketchy-1/4.png') }}" alt=""
                                    class="mw-100 mh-150px mb-7">
                                <div class="fw-bolder fs-3 text-gray-600 text-hover-primary">Add New Role</div>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!--end::Container-->
    </div>
@endsection