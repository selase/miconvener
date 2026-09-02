<div class="modal fade" id="createUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mw-650px">
        <div class="modal-content">
            <div class="modal-header" id="kt_modal_add_user_header">
                <h2 class="fw-bolder">Add User</h2>
                <div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal" aria-label="Close">
                    <span class="svg-icon svg-icon-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <rect opacity="0.5" x="6" y="17.3137" width="16" height="2" rx="1" transform="rotate(-45 6 17.3137)" fill="currentColor" />
                            <rect x="7.41422" y="6" width="16" height="2" rx="1" transform="rotate(45 7.41422 6)" fill="currentColor" />
                        </svg>
                    </span>
                </div>
            </div>
            <div class="modal-body scroll-y mx-5 mx-xl-15 my-7">
                <form id="createUserForm" class="form" action="{{ request()->route('subdomain') ? route('tenant.users.store', ['subdomain' => request()->route('subdomain')]) : route('users.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="d-flex flex-column scroll-y me-n7 pe-7" id="kt_modal_add_user_scroll" data-kt-scroll="true" data-kt-scroll-activate="{default: false, lg: true}" data-kt-scroll-max-height="auto" data-kt-scroll-dependencies="#kt_modal_add_user_header" data-kt-scroll-wrappers="#kt_modal_add_user_scroll" data-kt-scroll-offset="300px">
                        <div class="fv-row mb-7">
                            <label class="d-block fw-bold fs-6 mb-5">Avatar</label>
                            <div class="image-input image-input-outline" data-kt-image-input="true" style="background-image: url('assets/media/svg/avatars/blank.svg')">
                                <div class="image-input-wrapper w-125px h-125px" style="background-image: url({{ asset('assets/media/avatars/300-6.jpg') }});"></div>
                                <label class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-kt-image-input-action="change" data-bs-toggle="tooltip" title="Change avatar">
                                    <i class="bi bi-pencil-fill fs-7"></i>
                                    <input type="file" name="photo" id="photo" accept="image/*" />
                                    <input type="hidden" name="avatar_remove" />
                                </label>
                                <span class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-kt-image-input-action="cancel" data-bs-toggle="tooltip" title="Cancel avatar">
                                    <i class="bi bi-x fs-2"></i>
                                </span>
                                <span class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-kt-image-input-action="remove" data-bs-toggle="tooltip" title="Remove avatar">
                                    <i class="bi bi-x fs-2"></i>
                                </span>
                            </div>
                            <div class="form-text">Allowed file types: png, jpg, jpeg.</div>
                        </div>

                        <div class="fv-row mb-7">
                            <label class="required fw-bold fs-6 mb-2">First Name</label>
                            <input type="text" name="first_name" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="first name" value="{{ old('first_name') }}" />
                        </div>

                        <div class="fv-row mb-7">
                            <label class="required fw-bold fs-6 mb-2">Last Name</label>
                            <input type="text" name="last_name" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="last name" value="{{ old('last_name') }}" />
                        </div>

                        <div class="fv-row mb-7">
                            <label class="required fw-bold fs-6 mb-2">Phone No.</label>
                            <input type="text" name="phone_no" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="e.g. 0540000000 or +233540000000" value="{{ old('phone_no') }}" />
                        </div>


                        <div class="fv-row mb-7">
                            <label class="required fw-bold fs-6 mb-2">Email</label>
                            <input type="email" name="email" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="example@domain.com" />
                        </div>

                        <div class="mb-7">
                            <label class="required fw-bold fs-6 mb-5">Roles</label>
                            @foreach ($roles as $role)
                                <div class="d-flex fv-row">
                                    <div class="form-check form-check-custom form-check-solid">
                                        <input class="form-check-input me-3" name="roles[]" type="checkbox" value="{{ $role->id }}" id="role_{{ $role->id }}" />
                                        <label class="form-check-label" for="role_{{ $role->id }}">
                                            <div class="fw-bolder text-gray-800">{{ $role->name }}</div>
                                            <div class="text-gray-600">Permissions: {{ $role->permissions->pluck('name')->implode(', ') ?: 'None' }}</div>
                                        </label>
                                    </div>
                                </div>
                            <div class='separator separator-dashed my-5'></div>
                            @endforeach
                        </div>
                        
                        @hasrole('Superadmin')
                            <div class="mb-5">
                                <label class="fw-bold fs-6 mb-5">Tenant</label>
                                <select class="form-select form-select-solid" name="tenant_id">
                                    <option value="">Select an Option</option>
                                    @foreach ($tenants as  $tenant)
                                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                                    @endforeach

                                </select>
                            </div>
                        @else
                            <input type="hidden" name="tenant_id" value="{{ session()->get('tenant_id') }}">
                        @endhasrole

                        <div class="mb-17">
                            <label class="required fw-bold fs-6 mb-5">Status</label>
                            <select class="form-select form-select-solid" name="status">
                                <option value="">Select an Option</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}" {{ $status === \App\Models\User::STATUS_ACTIVE ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
                                @endforeach

                            </select>
                        </div>
                    </div>

                    <div class="text-center pt-15">
                        <button type="reset" class="btn btn-light me-3" data-bs-dismiss="modal" aria-label="Close">Discard</button>
                        <button type="submit" class="btn btn-primary" id="createUserButton">
                            <span class="indicator-label">Submit</span>
                            <span class="indicator-progress">Please wait...
                            <span class="spinner-border spinner-border-sm align-middle ms-2"></span></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
