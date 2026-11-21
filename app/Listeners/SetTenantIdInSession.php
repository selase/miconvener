<?php

declare(strict_types=1);

namespace App\Listeners;

final class SetTenantIdInSession
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        $memberships = app(\App\Services\Tenancy\OrganisationMemberships::class);
        if ($id = session('active_tenant_id')) {
            $selected = \App\Models\Tenant::query()->find($id);
            if ($selected !== null && $memberships->isActive($event->user, $selected)) {
                return;
            }
            session()->forget('active_tenant_id');
        }

        $tenant = $event->user->tenants()->get()->first(fn (\App\Models\Tenant $tenant): bool => $memberships->isActive($event->user, $tenant));

        if ($tenant === null) {
            return;
        }

        session()->put('active_tenant_id', $tenant->id);
    }
}
