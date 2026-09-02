<?php

declare(strict_types=1);

use App\Listeners\SendWelcomeEmail;
use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Mail;

it('sends welcome email on registration', function () {
    Mail::fake();

    $user = User::factory()->create();

    $event = new Registered($user);

    $listener = new SendWelcomeEmail();
    $listener->handle($event);

    Mail::assertQueued(WelcomeMail::class, function (WelcomeMail $mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});
