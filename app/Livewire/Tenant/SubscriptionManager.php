<?php

declare(strict_types=1);

namespace App\Livewire\Tenant;

use App\Models\Event;
use App\Models\EventMaterial;
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

    /**
     * What the tenant is about to lose, measured against their actual data
     * rather than described in the abstract. A plan change is agreed to in this
     * dialog and felt weeks later, so the consequences that bite -- registration
     * turned away at the door, a paid ticket type that can no longer be edited --
     * belong here where the decision is made.
     *
     * @return list<string>
     */
    #[Computed]
    public function pendingChangeWarnings(): array
    {
        if ($this->confirmationType === '') {
            return [];
        }

        $tenant = app(TenantContext::class)->getTenant();
        $target = Package::query()
            ->where('slug', $this->confirmingPlanSlug !== '' ? $this->confirmingPlanSlug : 'free')
            ->with('features')
            ->first();

        if (! $tenant || ! $target) {
            return [];
        }

        $warnings = [];

        $registrationLimit = $this->packageFeatureValue($target, 'event_registrations');
        if ($registrationLimit !== null && $registrationLimit >= 0) {
            $used = (int) $tenant->usage()
                ->where('feature_slug', 'event_registrations')
                ->whereNull('period_start')
                ->value('used_count');

            if ($used >= $registrationLimit) {
                $warnings[] = "You have {$used} registrations this month and {$target->name} allows {$registrationLimit}. "
                    .'New registrations will be turned away until the count resets at the start of next month.';
            }
        }

        if (! $this->packageAllows($target, 'paid_tickets')) {
            $paidEvents = Event::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', Event::STATUS_PUBLISHED)
                ->where(fn ($query) => $query
                    ->where('ticket_price', '>', 0)
                    ->orWhereHas('ticketTypes', fn ($tickets) => $tickets->where('is_active', true)->where('price', '>', 0))
                )
                ->count();

            if ($paidEvents > 0) {
                $warnings[] = "{$paidEvents} published event(s) sell paid tickets. On {$target->name} you cannot add or change "
                    .'paid ticket types. Tickets already on sale keep selling, so an event in progress will not break.';
            }
        }

        if (! $this->packageAllows($target, 'event_materials')) {
            $materials = EventMaterial::query()->where('tenant_id', $tenant->id)->count();

            if ($materials > 0) {
                $warnings[] = "{$materials} speaker material file(s) are attached to your events. On {$target->name} you cannot "
                    .'upload new ones. Files already uploaded stay available to attendees.';
            }
        }

        return $warnings;
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

    private function packageFeatureValue(Package $package, string $slug): ?int
    {
        $feature = $package->features->firstWhere('slug', $slug);

        return $feature ? (int) $feature->pivot->value : null;
    }

    private function packageAllows(Package $package, string $slug): bool
    {
        $feature = $package->features->firstWhere('slug', $slug);

        // A package that does not list the feature at all predates it; treat that
        // as permitted, matching how the runtime gates read a missing row.
        return $feature === null || filter_var($feature->pivot->value, FILTER_VALIDATE_BOOLEAN);
    }
}
