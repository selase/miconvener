<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

final class UpdateLastLoginIp
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
    public function handle(Login $event): void
    {
        $event->user->last_login_at = now();
        $event->user->last_login_ip = request()->getClientIp();
        $event->user->save();
    }
}
