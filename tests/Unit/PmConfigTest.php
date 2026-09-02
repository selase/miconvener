<?php

declare(strict_types=1);

test('pm config has all provider sections', function () {
    expect(config('pm.jira'))->toBeArray()
        ->toHaveKeys(['client_id', 'client_secret', 'redirect_uri', 'scopes'])
        ->and(config('pm.asana'))->toBeArray()
        ->toHaveKey('auth_type', 'api_key')
        ->and(config('pm.linear'))->toBeArray()
        ->toHaveKey('auth_type', 'api_key')
        ->and(config('pm.monday'))->toBeArray()
        ->toHaveKeys(['client_id', 'client_secret', 'redirect_uri']);
});

test('pm config has sync section with defaults', function () {
    expect(config('pm.sync'))->toBeArray()
        ->toHaveKeys(['enabled', 'auto_push_on_create'])
        ->and(config('pm.sync.enabled'))->toBeTrue()
        ->and(config('pm.sync.auto_push_on_create'))->toBeTrue();
});

test('pm config has queue setting', function () {
    expect(config('pm.queue'))->toBe('pm-sync');
});
