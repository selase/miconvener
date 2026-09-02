@extends('layouts.admin.master')

@section('title', 'Create Feature')

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <!--begin::Container-->
        <div id="kt_content_container" class="container-xxl">
            <!--begin::Card-->
            <div class="card">
                <!--begin::Card header-->
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h3 class="card-label">Define New System Feature</h3>
                    </div>
                </div>
                <!--end::Card header-->
                <!--begin::Card body-->
                <div class="card-body">
                    <form id="createFeatureForm" class="form" action="{{ route('features.store') }}" method="POST">
                        @csrf

                        <div class="fv-row mb-7">
                            <label class="required fw-bold fs-6 mb-2">Feature Name</label>
                            <input type="text" name="name" class="form-control form-control-solid mb-3 mb-lg-0"
                                placeholder="e.g. AI Content Generation" value="{{ old('name') }}" required />
                        </div>

                        <div class="fv-row mb-7">
                            <label class="required fw-bold fs-6 mb-2">Slug</label>
                            <input type="text" name="slug" class="form-control form-control-solid mb-3 mb-lg-0"
                                placeholder="e.g. ai-content-generation" value="{{ old('slug') }}" required />
                            <div class="form-text text-muted">Unique identifier for this feature in code.</div>
                        </div>

                        <div class="fv-row mb-7">
                            <label class="required fw-bold fs-6 mb-2">Type</label>
                            <select class="form-select form-select-solid" name="type" required>
                                <option value="boolean" selected>Boolean (Yes/No)</option>
                                <option value="limit">Limit (Count/Quota)</option>
                                <option value="metered">Metered (Usage Tracking)</option>
                            </select>
                            <div class="form-text text-muted">
                                <strong>Boolean:</strong> Enabled or Disabled.<br>
                                <strong>Limit:</strong> A numeric limit max (e.g. 10 Users).<br>
                                <strong>Metered:</strong> Pay-as-you-go usage.
                            </div>
                        </div>

                        <div class="fv-row mb-10">
                            <label class="fw-bold fs-6 mb-2">Description</label>
                            <textarea name="description" class="form-control form-control-solid"
                                rows="3">{{ old('description') }}</textarea>
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
                                                    {{ in_array($permission->id, old('permissions', [])) ? 'checked' : '' }} />
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
                                <span class="indicator-label">Create Feature</span>
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