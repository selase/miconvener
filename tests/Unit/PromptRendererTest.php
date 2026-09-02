<?php

declare(strict_types=1);

use App\Services\AI\PromptRenderer;

test('cleanup prompt renders with expected variables', function () {
    $renderer = new PromptRenderer;

    $result = $renderer->render('cleanup', [
        'meetingTitle' => 'Weekly Standup',
        'participantNames' => ['Alice', 'Bob'],
        'rawText' => '[SEG_0]Hello world[/SEG_0]',
    ]);

    expect($result)->toContain('Weekly Standup')
        ->and($result)->toContain('Alice, Bob')
        ->and($result)->toContain('[SEG_0]Hello world[/SEG_0]');
});

test('extract action items prompt renders with participants', function () {
    $renderer = new PromptRenderer;

    $result = $renderer->render('extract-action-items', [
        'meetingTitle' => 'Budget Review',
        'meetingDate' => '2026-02-22',
        'participants' => [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ],
        'transcriptText' => 'Alice will prepare the budget report by Friday.',
    ]);

    expect($result)->toContain('Budget Review')
        ->and($result)->toContain('2026-02-22')
        ->and($result)->toContain('Alice')
        ->and($result)->toContain('Bob')
        ->and($result)->toContain('Alice will prepare the budget report by Friday.');
});

test('generate minutes prompt renders with action items and participants', function () {
    $renderer = new PromptRenderer;

    $result = $renderer->render('generate-minutes', [
        'meetingTitle' => 'Q1 Planning',
        'meetingDate' => '2026-02-22',
        'participants' => [
            ['name' => 'Alice', 'role' => 'organizer'],
            ['name' => 'Bob', 'role' => 'participant'],
        ],
        'actionItems' => [
            ['title' => 'Draft proposal', 'assigned_to' => 'Alice', 'priority' => 'high'],
        ],
        'transcriptText' => 'We discussed Q1 objectives.',
        'template' => 'standard',
    ]);

    expect($result)->toContain('Q1 Planning')
        ->and($result)->toContain('Alice (Organizer)')
        ->and($result)->toContain('Bob (Participant)')
        ->and($result)->toContain('Draft proposal')
        ->and($result)->toContain('We discussed Q1 objectives.')
        ->and($result)->toContain('standard');
});

test('missing prompt template throws exception', function () {
    $renderer = new PromptRenderer;

    $renderer->render('nonexistent-template', []);
})->throws(InvalidArgumentException::class);
