<div>
    @if(session('message'))
        <div class="alert alert-success d-flex align-items-center p-5 mb-10">
            <i class="fas fa-check-circle text-success me-4 fs-2"></i>
            <span>{{ session('message') }}</span>
        </div>
    @endif

    <div class="card card-flush mb-10">
        <div class="card-header pt-7">
            <h3 class="card-title align-items-start flex-column">
                <span class="card-label fw-bolder text-dark">White-Label Branding</span>
                <span class="text-muted mt-1 fw-bold fs-7">Customize how your organization appears in emails and the application</span>
            </h3>
        </div>
        <div class="card-body pt-5">
            <form wire:submit="save">
                <div class="row mb-5">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Email Sender Name</label>
                        <input type="text" wire:model="emailSenderName" class="form-control form-control-solid" placeholder="e.g. Acme Corp" />
                        <div class="form-text">The name that appears in the "From" field of emails sent to participants.</div>
                        @error('emailSenderName') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Email Sender Address</label>
                        <input type="email" wire:model="emailSenderAddress" class="form-control form-control-solid" placeholder="e.g. notifications@acme.com" />
                        <div class="form-text">The email address used as the sender for queue, billing, and account emails.</div>
                        @error('emailSenderAddress') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="row mb-5">
                    <div class="col-md-6">
                        <label class="form-check form-switch form-check-custom form-check-solid">
                            <input class="form-check-input" type="checkbox" wire:model="poweredByHidden" />
                            <span class="form-check-label fw-bold">Hide product branding</span>
                        </label>
                        <div class="form-text mt-2">When enabled, the application footer will display your organization name instead of the platform name.</div>
                    </div>
                </div>

                <div class="separator my-7"></div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-flush">
        <div class="card-header pt-7">
            <h3 class="card-title align-items-start flex-column">
                <span class="card-label fw-bolder text-dark">Already Configured</span>
                <span class="text-muted mt-1 fw-bold fs-7">These branding options are managed in Organization Settings</span>
            </h3>
        </div>
        <div class="card-body pt-5">
            <div class="d-flex flex-stack py-3 border-bottom border-gray-300 border-bottom-dashed">
                <span class="text-gray-700 fw-bold">Logo</span>
                <span class="text-muted">Configured in <a href="{{ route('tenant.settings.index') }}">Org Settings</a></span>
            </div>
            <div class="d-flex flex-stack py-3 border-bottom border-gray-300 border-bottom-dashed">
                <span class="text-gray-700 fw-bold">Primary Color</span>
                <span class="text-muted">Configured in <a href="{{ route('tenant.settings.index') }}">Org Settings</a></span>
            </div>
            <div class="d-flex flex-stack py-3">
                <span class="text-gray-700 fw-bold">Custom Domain</span>
                <span class="text-muted">Configured in <a href="{{ route('tenant.settings.index') }}">Org Settings</a></span>
            </div>
        </div>
    </div>
</div>
