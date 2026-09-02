<?php

declare(strict_types=1);

use App\Listeners\UpdateLastLoginIp;
use App\Models\User;
use Illuminate\Auth\Events\Login;

it('updates last_login_at and last_login_ip on login', function () {
    $user = User::factory()->create([
        'last_login_at' => null,
        'last_login_ip' => null,
    ]);

    $event = new Login('web', $user, false);

    $listener = new UpdateLastLoginIp();
    $listener->handle($event);

    $user->refresh();

    expect($user->last_login_at)->not->toBeNull()
        ->and($user->last_login_ip)->not->toBeNull();
});
