<?php

declare(strict_types=1);

namespace App\Contracts\AI;

interface AIProvider
{
    /**
     * Clean up raw transcript text, fixing grammar, removing filler words.
     *
     * @param  array{meeting_title: string, participant_names: list<string>}  $context
     * @return array{cleaned_text: string, prompt_tokens: int, completion_tokens: int}
     */
    public function cleanupTranscript(string $rawText, array $context): array;

    /**
     * Extract action items from transcript text.
     *
     * @param  array{meeting_title: string, participants: list<array{id: string, name: string}>, meeting_date: string}  $context
     * @return array{action_items: list<array{title: string, description: string, assigned_to_name: ?string, source_text: string, priority: string, due_date: ?string}>, prompt_tokens: int, completion_tokens: int}
     */
    public function extractActionItems(string $transcriptText, array $context): array;

    /**
     * Generate meeting minutes from transcript and action items.
     *
     * @param  array{meeting_title: string, meeting_date: string, participants: list<array{name: string, role: string}>, action_items: list<array{title: string, assigned_to: string, priority: string}>, template: string}  $context
     * @return array{markdown: string, prompt_tokens: int, completion_tokens: int}
     */
    public function generateMinutes(string $transcriptText, array $context): array;

    /**
     * Generate a structured discussion log from transcript text.
     *
     * @param  array{meeting_title: string, meeting_date: string, participants: list<array{id: string, name: string}>, agenda_items: list<array{title: string, description: ?string}>}  $context
     * @return array{entries: list<array{segment_index: ?int, agenda_item_index: ?int, speaker_name: string, statement: string, notes: ?string}>, prompt_tokens: int, completion_tokens: int}
     */
    public function generateDiscussionLog(string $transcriptText, array $context): array;
}
