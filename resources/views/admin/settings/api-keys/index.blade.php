@extends('layouts.admin.master')

@section('title', __('API Keys'))

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            <div class="card">
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h2>{{ __('API Keys') }}</h2>
                    </div>
                    <div class="card-toolbar">
                        <button type="button" class="btn btn-primary" onclick="generateApiKey()">
                            <span class="svg-icon svg-icon-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                    <rect opacity="0.5" x="11" y="18" width="12" height="2" rx="1" transform="rotate(-90 11 18)" fill="currentColor" />
                                    <rect x="6" y="11" width="12" height="2" rx="1" fill="currentColor" />
                                </svg>
                            </span>
                            {{ __('Generate New Key') }}
                        </button>
                    </div>
                </div>
                <div class="card-body py-4">
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed fs-6 gy-5" id="api-keys-table">
                            <thead>
                                <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                    <th class="min-w-125px">{{ __('Name') }}</th>
                                    <th class="min-w-125px">{{ __('Key Hint') }}</th>
                                    <th class="min-w-125px">{{ __('Created By') }}</th>
                                    <th class="min-w-125px">{{ __('Last Used') }}</th>
                                    <th class="min-w-125px">{{ __('Created') }}</th>
                                    <th class="min-w-125px">{{ __('Status') }}</th>
                                    <th class="text-end min-w-100px">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600 fw-bold">
                                @forelse($apiKeys as $key)
                                    <tr>
                                        <td>{{ $key->name }}</td>
                                        <td><code>sk_...{{ $key->key_hint }}</code></td>
                                        <td>{{ $key->user?->displayName() ?? 'System' }}</td>
                                        <td>{{ $key->last_used_at?->diffForHumans() ?? __('Never') }}</td>
                                        <td>{{ $key->created_at->format('Y-m-d H:i') }}</td>
                                        <td>
                                            @if($key->isRevoked())
                                                <span class="badge badge-light-danger">{{ __('Revoked') }}</span>
                                            @elseif($key->isExpired())
                                                <span class="badge badge-light-warning">{{ __('Expired') }}</span>
                                            @else
                                                <span class="badge badge-light-success">{{ __('Active') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if(!$key->isRevoked())
                                                <button class="btn btn-icon btn-active-light-danger w-30px h-30px"
                                                        onclick="revokeApiKey('{{ $key->id }}', '{{ $key->name }}')"
                                                        title="{{ __('Revoke Key') }}">
                                                    <i class="fas fa-trash fs-4"></i>
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-10">
                                            {{ __('No API keys found. Generate one to get started.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Generate Key Modal -->
    <div class="modal fade" id="generateKeyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered mw-650px">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="fw-bolder">{{ __('Generate API Key') }}</h2>
                    <div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
                        <i class="fas fa-times fs-4"></i>
                    </div>
                </div>
                <div class="modal-body scroll-y mx-5 mx-xl-15 my-7">
                    <form id="generateKeyForm" class="form" action="#">
                        <div class="d-flex flex-column mb-7 fv-row">
                            <label class="d-flex align-items-center fs-6 fw-bold form-label mb-2">
                                <span class="required">{{ __('Key Name') }}</span>
                                <i class="fas fa-exclamation-circle ms-2 fs-7" data-bs-toggle="tooltip" title="{{ __('Give your key a descriptive name (e.g. Production Backend)') }}"></i>
                            </label>
                            <input type="text" class="form-control form-control-solid" placeholder="{{ __('Enter key name') }}" name="name" id="key_name" required />
                        </div>
                        <div class="text-center pt-15">
                            <button type="reset" class="btn btn-light me-3" data-bs-dismiss="modal">{{ __('Discard') }}</button>
                            <button type="submit" class="btn btn-primary" id="generateKeySubmit">
                                <span class="indicator-label">{{ __('Generate') }}</span>
                                <span class="indicator-progress">{{ __('Please wait...') }}
                                <span class="spinner-border spinner-border-sm align-middle ms-2"></span></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Reveal Key Modal -->
    <div class="modal fade" id="revealKeyModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered mw-650px">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="fw-bolder">{{ __('New API Key Generated') }}</h2>
                </div>
                <div class="modal-body scroll-y mx-5 mx-xl-15 my-7">
                    <div class="notice d-flex bg-light-warning rounded border-warning border border-dashed mb-9 p-6">
                        <i class="fas fa-exclamation-triangle fs-2tx text-warning me-4"></i>
                        <div class="d-flex flex-stack flex-grow-1 ">
                            <div class="fw-bold">
                                <h4 class="text-gray-900 fw-bolder">{{ __('Save this key!') }}</h4>
                                <div class="fs-6 text-gray-700 ">{{ __('For security reasons, we will only show this key once. If you lose it, you will need to generate a new one.') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-10">
                        <label class="form-label fw-bold">{{ __('Your API Key') }}</label>
                        <div class="input-group">
                            <input type="text" id="plain_api_key" class="form-control form-control-solid" readonly value="" />
                            <button class="btn btn-active-light-primary btn-light" type="button" onclick="copyToClipboard('plain_api_key')">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <div class="text-center">
                        <button type="button" class="btn btn-primary" onclick="window.location.reload()">{{ __('I have saved the key') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    function generateApiKey() {
        $('#generateKeyModal').modal('show');
    }

    $('#generateKeyForm').on('submit', function(e) {
        e.preventDefault();
        const submitBtn = $('#generateKeySubmit');
        submitBtn.attr('data-indicator', 'on').attr('disabled', true);

        $.ajax({
            url: "{{ route('tenant.api-keys.store', ['subdomain' => request('subdomain')]) }}",
            type: 'POST',
            data: {
                _token: "{{ csrf_token() }}",
                name: $('#key_name').val()
            },
            success: function(response) {
                $('#generateKeyModal').modal('hide');
                $('#plain_api_key').val(response.key);
                $('#revealKeyModal').modal('show');
            },
            error: function(xhr) {
                submitBtn.removeAttr('data-indicator').attr('disabled', false);
                toastr.error(xhr.responseJSON?.message || 'Error generating key');
            }
        });
    });

    function revokeApiKey(id, name) {
        Swal.fire({
            text: "Are you sure you want to revoke the API key '" + name + "'? This action cannot be undone and any applications using this key will stop working immediately.",
            icon: "warning",
            showCancelButton: true,
            buttonsStyling: false,
            confirmButtonText: "Yes, revoke it!",
            cancelButtonText: "No, cancel",
            customClass: {
                confirmButton: "btn fw-bold btn-danger",
                cancelButton: "btn fw-bold btn-active-light-primary"
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                $.ajax({
                    url: "{{ route('tenant.api-keys.index', ['subdomain' => request('subdomain')]) }}/" + id,
                    type: 'DELETE',
                    data: {
                        _token: "{{ csrf_token() }}"
                    },
                    success: function() {
                        Swal.fire({
                            text: "You have revoked " + name + "!.",
                            icon: "success",
                            buttonsStyling: false,
                            confirmButtonText: "Ok, got it!",
                            customClass: {
                                confirmButton: "btn fw-bold btn-primary",
                            }
                        }).then(function() {
                            window.location.reload();
                        });
                    },
                    error: function(xhr) {
                        toastr.error(xhr.responseJSON?.message || 'Error revoking key');
                    }
                });
            }
        });
    }

    function copyToClipboard(id) {
        const copyText = document.getElementById(id);
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value).then(() => {
             toastr.success('Key copied to clipboard');
        });
    }
</script>
@endpush
