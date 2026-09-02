<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Mail\WelcomeMail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

final class SendWelcomeEmail implements ShouldQueue
{
    public function handle(Registered $event): void
    {
        Mail::to($event->user->email)->queue(new WelcomeMail($event->user));
    }
}
