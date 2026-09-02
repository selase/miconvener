<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\UserLoginHistory;

final readonly class LogoutLogs
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(
        private UserLoginHistory $userLoginHistory
    ) {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(): void
    {
        $this->userLoginHistory->setLogoutLog();
    }
}
