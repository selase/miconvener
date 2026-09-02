<?php

declare(strict_types=1);

namespace App\Listeners;

final class SetTenantIdInSession
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  object  $event
     */
    public function handle($event): void
    {
        if (session()->has('active_tenant_id')) {
            return;
        }

        $tenant = $event->user->tenants()->first();

        if ($tenant === null) {
            return;
        }

        session()->put('active_tenant_id', $tenant->id);
    }
}
