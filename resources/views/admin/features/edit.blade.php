@extends('layouts.admin.master')

@section('title', 'Edit Feature')

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <!--begin::Container-->
        <div id="kt_content_container" class="container-xxl">
            <!--begin::Card-->
            <div class="card">
                <!--begin::Card header-->
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h3 class="card-label">Edit Feature: {{ $feature->name }}</h3>
                    </div>
                </div>
                <!--end::Card header-->
                <!--begin::Card body-->
                <div class="card-body">
                    <form id="editFeatureForm" class="form" action="{{ route('features.update', $feature->id) }}"
                        method="POST">
                        @csrf
                        @method('PUT')

                        <div class="fv-row mb-7">
                            <label class="required fw-bold fs-6 mb-2">Feature Name</label>
                            <input type="text" name="name" class="form-control form-control-solid mb-3 mb-lg-0"
                                value="{{ old('name', $feature->name) }}" required />
                        </div>

                        <div class="fv-row mb-7">
                            <label class="required fw-bold fs-6 mb-2">Slug</label>
                            <input type="text" name="slug" class="form-control form-control-solid mb-3 mb-lg-0"
                                value="{{ old('slug', $feature->slug) }}" required />
                            <div class="form-text text-muted">Changing this may break existing integration checks. Be
                                careful correctly.</div>
                        </div>

                        <div class="fv-row mb-7">
                            <label class="required fw-bold fs-6 mb-2">Type</label>
                            <select class="form-select form-select-solid" name="type" required>
                                <option value="boolean" {{ $feature->type == 'boolean' ? 'selected' : '' }}>Boolean (Yes/No)
                                </option>
                                <option value="limit" {{ $feature->type == 'limit' ? 'selected' : '' }}>Limit (Count/Quota)
                                </option>
                                <option value="metered" {{ $feature->type == 'metered' ? 'selected' : '' }}>Metered (Usage
                                    Tracking)</option>
                            </select>
                        </div>

                        <div class="fv-row mb-10">
                            <label class="fw-bold fs-6 mb-2">Description</label>
                            <textarea name="description" class="form-control form-control-solid"
                                rows="3">{{ old('description', $feature->description) }}</textarea>
                        </div>

                        <div class="fv-row mb-7">
                            <label class="fw-bolder fs-6 mb-5">Associated Permissions</label>
                            <div class="row">
                                @foreach($permissions->groupBy('category') as $category => $perms)
                                    <div class="col-md-4 mb-5">
                                        <h5 class="text-gray-800 fw-bold underline mb-3">{{ ucfirst($category) }}</h5>
                                        @foreach($perms as $permission)
                                            <div class="form-check form-check-custom form-check-solid mb-2">
                                                <input class="form-check-input" type="checkbox" name="permissions[]"
                                                    value="{{ $permission->id }}" id="perm_{{ $permission->id }}"
                                                    {{ in_array($permission->id, old('permissions', $feature->permissions->pluck('id')->toArray())) ? 'checked' : '' }} />
                                                <label class="form-check-label text-gray-600" for="perm_{{ $permission->id }}">
                                                    {{ $permission->name }}
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                            <div class="form-text text-muted">Selecting permissions here will "lock" them behind this feature entitlement.</div>
                        </div>

                        <div class="text-center pt-15">
                            <a href="{{ route('features.index') }}" class="btn btn-light me-3">Discard</a>
                            <button type="submit" class="btn btn-primary">
                                <span class="indicator-label">Update Feature</span>
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