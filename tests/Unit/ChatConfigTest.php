<?php

declare(strict_types=1);

test('chat config has all provider sections', function () {
    expect(config('chat.slack'))->toBeArray()
        ->toHaveKeys(['client_id', 'client_secret', 'redirect_uri', 'signing_secret', 'scopes'])
        ->and(config('chat.teams'))->toBeArray()
        ->toHaveKeys(['client_id', 'client_secret', 'redirect_uri', 'tenant_id']);
});

test('chat config has notification settings with defaults', function () {
    expect(config('chat.notifications'))->toBeArray()
        ->toHaveKeys(['minutes_summary', 'task_assignment', 'meeting_reminder', 'task_status_update'])
        ->and(config('chat.notifications.minutes_summary'))->toBeTrue()
        ->and(config('chat.notifications.task_assignment'))->toBeTrue();
});

test('chat config has queue setting', function () {
    expect(config('chat.queue'))->toBe('chat-notifications');
});
