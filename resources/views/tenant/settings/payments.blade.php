@extends('layouts.admin.master')

@section('title', __('Merchant Payment Settings'))

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            
            @if(session('status') === 'success')
                <div class="alert alert-success d-flex align-items-center p-5 mb-10">
                    <span class="svg-icon svg-icon-2hx svg-icon-success me-4">
                        <i class="fas fa-check-circle"></i>
                    </span>
                    <div class="d-flex flex-column">
                        <h4 class="mb-1 text-success">Success</h4>
                        <span>{{ session('message') }}</span>
                    </div>
                </div>
            @endif

            <div class="row g-5 g-xl-10">
                <!-- Stripe Configuration -->
                <div class="col-xl-6">
                    <div class="card card-flush h-xl-100">
                        <div class="card-header pt-7">
                            <h3 class="card-title align-items-start flex-column">
                                <span class="card-label fw-bolder text-dark">Stripe Integration</span>
                                <span class="text-muted mt-1 fw-bold fs-7">Connect your Stripe account to receive payments</span>
                            </h3>
                            <div class="card-toolbar">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/b/ba/Stripe_Logo%2C_revised_2016.svg" class="h-20px" alt="Stripe" />
                            </div>
                        </div>
                        <div class="card-body pt-5">
                            <form action="{{ route('tenant.settings.payments.update') }}" method="POST">
                                @csrf
                                <input type="hidden" name="provider" value="stripe">
                                
                                <div class="fv-row mb-7">
                                    <label class="required fw-bold fs-6 mb-2">Secret Key</label>
                                    <input type="password" name="api_key" class="form-control form-control-solid @error('api_key') is-invalid @enderror" placeholder="sk_live_..." value="{{ $stripe ? '********' : '' }}" />
                                    <div class="text-muted fs-7 mt-2">Your Stripe Secret Key (sk_live_... or sk_test_...)</div>
                                    @error('api_key') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="fv-row mb-7">
                                    <label class="fw-bold fs-6 mb-2">Publishable Key</label>
                                    <input type="text" name="public_key" class="form-control form-control-solid" placeholder="pk_live_..." value="{{ $stripe->public_key_encrypted ?? '' }}" />
                                    <div class="text-muted fs-7 mt-2">Used for client-side checkout integrations</div>
                                </div>

                                <div class="fv-row mb-7">
                                    <label class="fw-bold fs-6 mb-2">Webhook Signing Secret</label>
                                    <input type="password" name="webhook_secret" class="form-control form-control-solid" placeholder="whsec_..." value="{{ $stripe ? '********' : '' }}" />
                                    <div class="text-muted fs-7 mt-2">Used to verify that events are sent by Stripe</div>
                                </div>

                                <div class="d-flex flex-stack mb-7">
                                    <div class="me-5">
                                        <label class="fs-6 fw-bold">Active Status</label>
                                        <div class="fs-7 fw-bold text-muted">Allow customers to pay via Stripe</div>
                                    </div>
                                    <label class="form-check form-switch form-check-custom form-check-solid">
                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" {{ $stripe?->is_active ? 'checked' : '' }} />
                                    </label>
                                </div>

                                <div class="separator separator-dashed my-5"></div>

                                <div class="mb-7">
                                    <label class="fw-bold fs-6 mb-2">Webhook Endpoint URL</label>
                                    <div class="position-relative">
                                        <input type="text" class="form-control form-control-solid bg-light" readonly value="{{ config('app.url') }}/webhooks/merchant/stripe/{{ $tenant->id }}" />
                                        <div class="position-absolute top-50 end-0 translate-middle-y me-3">
                                            <button type="button" class="btn btn-sm btn-icon btn-active-color-primary" onclick="navigator.clipboard.writeText(this.parentElement.previousElementSibling.value)">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="text-muted fs-7 mt-2">Copy this URL to your Stripe Dashboard > Developers > Webhooks</div>
                                </div>

                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">Save Stripe Settings</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Paystack Configuration -->
                <div class="col-xl-6">
                    <div class="card card-flush h-xl-100">
                        <div class="card-header pt-7">
                            <h3 class="card-title align-items-start flex-column">
                                <span class="card-label fw-bolder text-dark">Paystack Integration</span>
                                <span class="text-muted mt-1 fw-bold fs-7">Enable Paystack for local and international payments</span>
                            </h3>
                            <div class="card-toolbar">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/0/0b/Paystack_Logo.png" class="h-20px" alt="Paystack" />
                            </div>
                        </div>
                        <div class="card-body pt-5">
                            <form action="{{ route('tenant.settings.payments.update') }}" method="POST">
                                @csrf
                                <input type="hidden" name="provider" value="paystack">
                                
                                <div class="fv-row mb-7">
                                    <label class="required fw-bold fs-6 mb-2">Secret Key</label>
                                    <input type="password" name="api_key" class="form-control form-control-solid @error('api_key') is-invalid @enderror" placeholder="sk_live_..." value="{{ $paystack ? '********' : '' }}" />
                                    <div class="text-muted fs-7 mt-2">Your Paystack Secret Key</div>
                                    @error('api_key') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="fv-row mb-7">
                                    <label class="fw-bold fs-6 mb-2">Public Key</label>
                                    <input type="text" name="public_key" class="form-control form-control-solid" placeholder="pk_live_..." value="{{ $paystack->public_key_encrypted ?? '' }}" />
                                    <div class="text-muted fs-7 mt-2">Used for Inline or Popup checkout</div>
                                </div>

                                <div class="d-flex flex-stack mb-7">
                                    <div class="me-5">
                                        <label class="fs-6 fw-bold">Active Status</label>
                                        <div class="fs-7 fw-bold text-muted">Allow customers to pay via Paystack</div>
                                    </div>
                                    <label class="form-check form-switch form-check-custom form-check-solid">
                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" {{ $paystack?->is_active ? 'checked' : '' }} />
                                    </label>
                                </div>

                                <div class="separator separator-dashed my-5"></div>

                                <div class="mb-7">
                                    <label class="fw-bold fs-6 mb-2">Webhook Endpoint URL</label>
                                    <div class="position-relative">
                                        <input type="text" class="form-control form-control-solid bg-light" readonly value="{{ config('app.url') }}/webhooks/merchant/paystack/{{ $tenant->id }}" />
                                        <div class="position-absolute top-50 end-0 translate-middle-y me-3">
                                            <button type="button" class="btn btn-sm btn-icon btn-active-color-primary" onclick="navigator.clipboard.writeText(this.parentElement.previousElementSibling.value)">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="text-muted fs-7 mt-2">Paste this URL in your Paystack Dashboard > Settings > API Keys & Webhooks</div>
                                </div>

                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">Save Paystack Settings</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
