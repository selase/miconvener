<?php

declare(strict_types=1);

namespace App\Livewire\Tenant;

use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Dashboard extends Component
{
    public function render(): View
    {
        $tenant = app(TenantContext::class)->getTenant();
        auth()->user();

        // Onboarding checklist
        $checklist = [
            'onboarding' => (bool) $tenant->onboarding_completed_at,
            'team' => $tenant->users()->count() > 1,
            'branding' => ! empty($tenant->logo) || ! empty(data_get($tenant->meta, 'branding.primary_color')) || ! empty(data_get($tenant->meta, 'primary_color')),
        ];

        return view('livewire.tenant.dashboard', [
            'checklist' => $checklist,
            'tenant' => $tenant,
        ]);
    }
}
