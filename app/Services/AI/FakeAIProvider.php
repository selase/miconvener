<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AI\AIProvider;
use BackedEnum;

final class FakeAIProvider implements AIProvider
{
    public function cleanupTranscript(string $rawText, array $context): array
    {
        // Simple fake cleanup: trim whitespace, normalize multiple spaces
        $cleaned = preg_replace('/\s+/', ' ', mb_trim($rawText));

        return [
            'cleaned_text' => $cleaned,
            'prompt_tokens' => (int) ceil(mb_strlen($rawText) / 4),
            'completion_tokens' => (int) ceil(mb_strlen((string) $cleaned) / 4),
        ];
    }

    public function extractActionItems(string $transcriptText, array $context): array
    {
        $participants = $context['participants'] ?? [];
        $meetingDate = $context['meeting_date'] ?? now()->toDateString();
        $dueDate = date('Y-m-d', strtotime($meetingDate.' +7 days'));

        $items = [];

        if (count($participants) > 0) {
            $items[] = [
                'title' => 'Follow up on discussion points',
                'description' => 'Review and consolidate the key takeaways from the meeting discussion.',
                'assigned_to_name' => $participants[0]['name'],
                'source_text' => mb_substr($transcriptText, 0, 100),
                'priority' => 'medium',
                'due_date' => $dueDate,
            ];
        }

        if (count($participants) > 1) {
            $items[] = [
                'title' => 'Prepare status update',
                'description' => 'Compile a brief status update based on meeting outcomes.',
                'assigned_to_name' => $participants[1]['name'] ?? $participants[0]['name'],
                'source_text' => mb_substr($transcriptText, 0, 80),
                'priority' => 'high',
                'due_date' => $dueDate,
            ];
        }

        return [
            'action_items' => $items,
            'prompt_tokens' => (int) ceil(mb_strlen($transcriptText) / 4),
            'completion_tokens' => 250,
        ];
    }

    public function generateMinutes(string $transcriptText, array $context): array
    {
        $title = $context['meeting_title'] ?? 'Meeting';
        $date = $context['meeting_date'] ?? now()->toDateString();
        $participants = $context['participants'] ?? [];
        $actionItems = $context['action_items'] ?? [];

        $attendeesList = '';
        foreach ($participants as $participant) {
            $rawRole = $participant['role'] ?? 'participant';
            $role = ucfirst($rawRole instanceof BackedEnum ? $rawRole->value : (string) $rawRole);
            $attendeesList .= "- {$participant['name']} ({$role})\n";
        }

        $actionItemsList = '';
        foreach ($actionItems as $item) {
            $assignee = $item['assigned_to'] ?? 'Unassigned';
            $priority = ucfirst($item['priority'] ?? 'medium');
            $actionItemsList .= "| {$item['title']} | {$assignee} | {$priority} |\n";
        }

        $markdown = <<<MARKDOWN
        # {$title}

        **Date:** {$date}

        ## Attendees

        {$attendeesList}

        ## Executive Summary

        This meeting covered key discussion points and resulted in actionable outcomes for the team.

        ## Discussion Points

        1. The team discussed current progress and upcoming priorities.
        2. Key decisions were made regarding the project timeline.

        ## Decisions Made

        - Agreed to proceed with the proposed approach.
        - Set follow-up deadlines for action items.

        ## Action Items

        | Task | Assignee | Priority |
        |------|----------|----------|
        {$actionItemsList}

        ## Next Steps

        - Review action items and confirm assignments.
        - Schedule follow-up meeting if needed.
        MARKDOWN;

        // Remove leading whitespace from heredoc indentation
        $markdown = preg_replace('/^ {8}/m', '', $markdown);

        return [
            'markdown' => $markdown,
            'prompt_tokens' => (int) ceil(mb_strlen($transcriptText) / 4),
            'completion_tokens' => (int) ceil(mb_strlen((string) $markdown) / 4),
        ];
    }

    public function generateDiscussionLog(string $transcriptText, array $context): array
    {
        $participants = $context['participants'] ?? [];
        $agendaItems = $context['agenda_items'] ?? [];

        $entries = [];
        $speakerIndex = 0;

        foreach (array_slice(explode("\n\n", $transcriptText), 0, 10) as $index => $block) {
            $speakerName = 'Unknown';
            $statement = $block;

            if (preg_match('/^\[(.+?)\]:\s*(.+)$/s', $block, $matches)) {
                $speakerName = $matches[1];
                $statement = $matches[2];
            } elseif (count($participants) > 0) {
                $speakerName = $participants[$speakerIndex % count($participants)]['name'];
                $speakerIndex++;
            }

            $entries[] = [
                'segment_index' => $index,
                'agenda_item_index' => count($agendaItems) > 0 ? $index % count($agendaItems) : null,
                'speaker_name' => $speakerName,
                'statement' => mb_trim($statement),
                'notes' => null,
            ];
        }

        return [
            'entries' => $entries,
            'prompt_tokens' => (int) ceil(mb_strlen($transcriptText) / 4),
            'completion_tokens' => 500,
        ];
    }
}
