<div>
    @if(session('success'))
        <div class="alert alert-success d-flex align-items-center p-5 mb-7">
            <i class="fas fa-check-circle text-success me-4 fs-2"></i>
            <span class="fw-bold">{{ session('success') }}</span>
        </div>
    @endif

    <div class="card card-flush shadow-sm mb-8">
        <div class="card-header border-0 pt-6">
            <div>
                <h3 class="card-title fw-boldest text-dark mb-1">Email Notifications</h3>
                <span class="text-gray-400 fw-bold fs-7">Choose which queue, billing, and security updates are sent to <strong>{{ auth()->user()->email }}</strong></span>
            </div>
        </div>
        <div class="card-body pt-2">
            @foreach(\App\Livewire\Tenant\NotificationPreferences::CHANNELS as $key => $channel)
                <div class="d-flex align-items-center justify-content-between py-4 {{ !$loop->last ? 'border-bottom border-gray-200' : '' }}">
                    <div class="me-5">
                        <div class="text-dark fw-bold fs-6">{{ $channel['label'] }}</div>
                        <div class="text-muted fw-bold fs-7">{{ $channel['description'] }}</div>
                    </div>
                    <label class="form-check form-switch form-check-custom form-check-solid flex-shrink-0">
                        <input class="form-check-input"
                               type="checkbox"
                               wire:model.live="preferences.{{ $key }}" />
                    </label>
                </div>
            @endforeach
        </div>
        <div class="card-footer d-flex justify-content-end pt-0 pb-6 px-9">
            <button wire:click="save" class="btn btn-primary">
                <span wire:loading.remove wire:target="save">
                    <i class="fas fa-save me-1"></i> Save Preferences
                </span>
                <span wire:loading wire:target="save">
                    <span class="spinner-border spinner-border-sm me-2"></span>Saving...
                </span>
            </button>
        </div>
    </div>

    <div class="card card-flush shadow-sm">
        <div class="card-body d-flex align-items-center gap-5 py-5">
            <div class="symbol symbol-50px">
                <span class="symbol-label bg-light-primary">
                    <i class="fas fa-bell text-primary fs-2"></i>
                </span>
            </div>
            <div>
                <div class="text-dark fw-bolder fs-6 mb-1">Browser Push Notifications</div>
                <div class="text-muted fw-bold fs-7">
                    Public queue users can enable browser alerts to receive turn updates without keeping the page in focus.
                </div>
            </div>
        </div>
    </div>
</div>
