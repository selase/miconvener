<?php

declare(strict_types=1);

namespace App\Livewire\Tenant;

use App\Models\Package;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionProvisioningService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

use function in_array;

final class SubscriptionManager extends Component
{
    public string $interval = 'month';

    public string $confirmingPlanSlug = '';

    public string $confirmationType = ''; // 'downgrade' | 'cancel'

    public function setInterval(string $interval): void
    {
        $this->interval = in_array($interval, ['month', 'year'], true) ? $interval : 'month';
    }

    /**
     * Called for downgrades & free tier — upgrades go through a standard form POST.
     */
    public function requestPlanChange(string $slug): void
    {
        $tenant = app(TenantContext::class)->getTenant();
        $newPackage = Package::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $currentPackage = $tenant->package;

        if ($currentPackage?->id === $newPackage->id) {
            return;
        }

        if ($newPackage->isFree()) {
            $this->confirmingPlanSlug = $slug;
            $this->confirmationType = 'cancel';

            return;
        }

        // Only handle downgrades here; upgrades use the checkout form POST
        if ($currentPackage && $newPackage->sort_order < $currentPackage->sort_order) {
            $this->confirmingPlanSlug = $slug;
            $this->confirmationType = 'downgrade';
        }
    }

    public function openCancelConfirmation(): void
    {
        $this->confirmingPlanSlug = 'free';
        $this->confirmationType = 'cancel';
    }

    public function dismissConfirmation(): void
    {
        $this->confirmingPlanSlug = '';
        $this->confirmationType = '';
    }

    public function applyConfirmedChange(): void
    {
        $tenant = app(TenantContext::class)->getTenant();
        $provisioningService = app(SubscriptionProvisioningService::class);

        if ($this->confirmationType === 'cancel') {
            $provisioningService->cancelAtPeriodEnd($tenant);
            $this->dismissConfirmation();
            session()->flash('info', 'Your plan will revert to Free at the end of your current billing period.');

            return;
        }

        if ($this->confirmationType === 'downgrade' && $this->confirmingPlanSlug) {
            $newPackage = Package::where('slug', $this->confirmingPlanSlug)->firstOrFail();
            $provisioningService->downgrade($tenant, $newPackage);
            $this->dismissConfirmation();
            session()->flash('info', "Your plan will downgrade to {$newPackage->name} at the end of your billing period.");

            return;
        }

        $this->dismissConfirmation();
    }

    #[Computed]
    public function packages(): \Illuminate\Database\Eloquent\Collection
    {
        return Package::where('is_active', true)
            ->with('features')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function currentPackage(): ?Package
    {
        return app(TenantContext::class)->getTenant()?->package;
    }

    #[Computed]
    public function subscription(): ?Subscription
    {
        $tenant = app(TenantContext::class)->getTenant();

        return $tenant
            ? Subscription::where('tenant_id', $tenant->id)->with('pendingPackage')->latest()->first()
            : null;
    }

    public function render(): View
    {
        return view('livewire.tenant.subscription-manager');
    }
}
