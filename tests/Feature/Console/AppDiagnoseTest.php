<?php

declare(strict_types=1);

test('app:diagnose command exists and displays diagnostic info', function () {
    $this->artisan('app:diagnose')
        ->assertExitCode(0)
        ->expectsOutputToContain('Diagnostic Information')
        ->expectsOutputToContain('queue.default')
        ->expectsOutputToContain('cache.default')
        ->expectsOutputToContain('session.driver')
        ->expectsOutputToContain('db.default');
});
