<div class="row mb-6">
    <label class="col-lg-4 col-form-label required fw-bold fs-6">{{ $label }} API Key</label>
    <div class="col-lg-8">
        <div class="row">
            <div class="col-lg-12 fv-row">
                <input type="hidden" name="configs[{{ $provider }}][provider]" value="{{ $provider }}">
                <div class="input-group mb-3">
                    <input type="password" name="configs[{{ $provider }}][api_key]"
                        class="form-control form-control-lg form-control-solid"
                        placeholder="{{ $config ? 'Current key set (leave empty to keep)' : 'sk-...' }}" value="" />
                    <span class="input-group-text">
                        <div class="form-check form-switch form-check-custom form-check-solid">
                            <input class="form-check-input" type="checkbox" name="configs[{{ $provider }}][is_active]"
                                value="1" {{ ($config && $config->is_active) || !$config ? 'checked' : '' }} />
                            <label class="form-check-label px-2">{{ __('Active') }}</label>
                        </div>
                    </span>
                </div>
                @if($config)
                    <div class="d-flex align-items-center mb-3">
                        <span class="badge badge-light-success me-2">{{ __('Configured') }}</span>
                        <span
                            class="text-muted fs-7 me-auto">{{ __('Last updated: :date', ['date' => $config->updated_at->diffForHumans()]) }}</span>

                        <a href="{{ route('tenant.llm-config.destroy', $provider) }}" class="btn btn-sm btn-light-danger"
                            onclick="event.preventDefault(); document.getElementById('delete-config-{{ $provider }}').submit();">
                            {{ __('Remove') }}
                        </a>
                    </div>
                @else
                    <div class="text-muted fs-7 mb-3">{{ __('Not configured.') }}</div>
                @endif
            </div>
        </div>
    </div>
</div>

@if($config)
    <form id="delete-config-{{ $provider }}" action="{{ route('tenant.llm-config.destroy', $provider) }}" method="POST"
        class="d-none">
        @csrf
        @method('DELETE')
    </form>
@endif