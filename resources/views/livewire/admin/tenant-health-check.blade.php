<?php

use App\Models\Tenant;
use App\Services\Tenancy\TenantHealthService;
use Livewire\Component;

new class extends Component
{
    public Tenant $tenant;

    public ?array $results = null;

    public bool $loading = false;

    public function mount(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    public function runCheck(TenantHealthService $healthService)
    {
        $this->loading = true;
        try {
            $this->results = $healthService->runAll($this->tenant);
        } catch (\Throwable $e) {
            $this->results = [
                'error' => $e->getMessage(),
            ];
        }
        $this->loading = false;
    }
};

?>

<div>
    <div class="card card-flush mb-6">
        <div class="card-header pt-7">
            <h3 class="card-title align-items-start flex-column">
                <span class="card-label fw-bolder text-dark">Infrastructure Health</span>
                <span class="text-muted mt-1 fw-bold fs-7">Real-time status of tenant resources</span>
            </h3>
            <div class="card-toolbar">
                <button wire:click="runCheck" class="btn btn-sm btn-light-primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>Run Full Health Check</span>
                    <span wire:loading>Checking...</span>
                </button>
            </div>
        </div>
        <div class="card-body">
            @if($results)
                @if(isset($results['error']))
                    <div class="alert alert-danger d-flex align-items-center p-5 mb-10">
                        <span class="svg-icon svg-icon-2hx svg-icon-danger me-4">
                            <i class="fas fa-exclamation-triangle fs-2"></i>
                        </span>
                        <div class="d-flex flex-column">
                            <h4 class="mb-1 text-danger">System Error</h4>
                            <span>{{ $results['error'] }}</span>
                        </div>
                    </div>
                @else
                    <div class="row g-6">
                        {{-- Database Check --}}
                        <div class="col-md-4">
                            <div class="border border-dashed rounded py-3 px-4 mb-3 {{ $results['database']['status'] === 'ok' ? 'border-success' : 'border-danger' }}">
                                <div class="d-flex align-items-center">
                                    <div class="fs-2 fw-bolder text-gray-800 me-2">Database</div>
                                    @if($results['database']['status'] === 'ok')
                                        <span class="badge badge-light-success">Active</span>
                                    @else
                                        <span class="badge badge-light-danger">Error</span>
                                    @endif
                                </div>
                                <div class="fw-bold fs-7 text-gray-500 mt-2">
                                    {{ $results['database']['message'] }}
                                    @if(isset($results['database']['database_name']))
                                        <br><span class="text-muted">DB: {{ $results['database']['database_name'] }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Storage Check --}}
                        <div class="col-md-4">
                            <div class="border border-dashed rounded py-3 px-4 mb-3 {{ $results['storage']['status'] === 'ok' ? 'border-success' : 'border-danger' }}">
                                <div class="d-flex align-items-center">
                                    <div class="fs-2 fw-bolder text-gray-800 me-2">Storage</div>
                                    @if($results['storage']['status'] === 'ok')
                                        <span class="badge badge-light-success">Active</span>
                                    @else
                                        <span class="badge badge-light-danger">Error</span>
                                    @endif
                                </div>
                                <div class="fw-bold fs-7 text-gray-500 mt-2">
                                    {{ $results['storage']['message'] }}
                                    @if(isset($results['storage']['disk']))
                                        <br><span class="text-muted">Driver: {{ $results['storage']['disk'] }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Features Check --}}
                        <div class="col-md-4">
                            <div class="border border-dashed rounded py-3 px-4 mb-3 {{ $results['features']['status'] === 'ok' ? 'border-success' : 'border-warning' }}">
                                <div class="d-flex align-items-center">
                                    <div class="fs-2 fw-bolder text-gray-800 me-2">Features</div>
                                    @if($results['features']['status'] === 'ok')
                                        <span class="badge badge-light-success">Synced</span>
                                    @else
                                        <span class="badge badge-light-warning">Warning</span>
                                    @endif
                                </div>
                                <div class="fw-bold fs-7 text-gray-500 mt-2">
                                    {{ $results['features']['message'] }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-muted fs-8 mt-4">
                        Last checked: {{ \Carbon\Carbon::parse($results['last_checked_at'])->diffForHumans() }}
                    </div>
                @endif
            @else
                <div class="d-flex flex-center flex-column py-5">
                    <span class="text-muted fw-bold">No health check data available. Click "Run Full Health Check" to begin.</span>
                </div>
            @endif
        </div>
    </div>
</div>
