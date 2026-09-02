<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AI\AIProvider;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Support\Facades\Log;

final readonly class BedrockProvider implements AIProvider
{
    private BedrockRuntimeClient $client;

    public function __construct(
        private PromptRenderer $promptRenderer,
    ) {
        $region = config('meeting.ai.region', 'us-east-1');

        $this->client = new BedrockRuntimeClient([
            'version' => 'latest',
            'region' => $region,
        ]);
    }

    public function cleanupTranscript(string $rawText, array $context): array
    {
        $prompt = $this->promptRenderer->render('cleanup', [
            'meetingTitle' => $context['meeting_title'] ?? 'Meeting',
            'participantNames' => $context['participant_names'] ?? [],
            'rawText' => $rawText,
        ]);

        $modelId = config('meeting.ai.cleanup_model', 'anthropic.claude-3-haiku-20240307-v1:0');
        $result = $this->invokeModel($modelId, $prompt);

        return [
            'cleaned_text' => $result['text'],
            'prompt_tokens' => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
        ];
    }

    public function extractActionItems(string $transcriptText, array $context): array
    {
        $prompt = $this->promptRenderer->render('extract-action-items', [
            'meetingTitle' => $context['meeting_title'] ?? 'Meeting',
            'meetingDate' => $context['meeting_date'] ?? now()->toDateString(),
            'participants' => $context['participants'] ?? [],
            'agendaItems' => $context['agenda_items'] ?? [],
            'markers' => $context['markers'] ?? [],
            'transcriptText' => $transcriptText,
        ]);

        $modelId = config('meeting.ai.extraction_model', 'anthropic.claude-3-5-sonnet-20241022-v2:0');
        $result = $this->invokeModel($modelId, $prompt);

        $actionItems = $this->parseJsonResponse($result['text']);

        return [
            'action_items' => $actionItems,
            'prompt_tokens' => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
        ];
    }

    public function generateMinutes(string $transcriptText, array $context): array
    {
        $prompt = $this->promptRenderer->render('generate-minutes', [
            'meetingTitle' => $context['meeting_title'] ?? 'Meeting',
            'meetingDate' => $context['meeting_date'] ?? now()->toDateString(),
            'participants' => $context['participants'] ?? [],
            'actionItems' => $context['action_items'] ?? [],
            'agendaItems' => $context['agenda_items'] ?? [],
            'markers' => $context['markers'] ?? [],
            'transcriptText' => $transcriptText,
            'template' => $context['template'] ?? 'standard',
        ]);

        $modelId = config('meeting.ai.minutes_model', 'anthropic.claude-3-5-sonnet-20241022-v2:0');
        $result = $this->invokeModel($modelId, $prompt);

        return [
            'markdown' => $result['text'],
            'prompt_tokens' => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
        ];
    }

    public function generateDiscussionLog(string $transcriptText, array $context): array
    {
        $prompt = $this->promptRenderer->render('generate-discussion-log', [
            'meetingTitle' => $context['meeting_title'] ?? 'Meeting',
            'meetingDate' => $context['meeting_date'] ?? now()->toDateString(),
            'participants' => $context['participants'] ?? [],
            'agendaItems' => $context['agenda_items'] ?? [],
            'transcriptText' => $transcriptText,
        ]);

        $modelId = config('meeting.ai.minutes_model', 'anthropic.claude-3-5-sonnet-20241022-v2:0');
        $result = $this->invokeModel($modelId, $prompt);

        $entries = $this->parseDiscussionLogResponse($result['text']);

        return [
            'entries' => $entries,
            'prompt_tokens' => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
        ];
    }

    /**
     * @return array{text: string, prompt_tokens: int, completion_tokens: int}
     */
    private function invokeModel(string $modelId, string $prompt): array
    {
        $response = $this->client->invokeModel([
            'modelId' => $modelId,
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode([
                'anthropic_version' => 'bedrock-2023-05-31',
                'max_tokens' => 4096,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]),
        ]);

        $body = json_decode((string) $response->get('body'), true);

        return [
            'text' => $body['content'][0]['text'] ?? '',
            'prompt_tokens' => $body['usage']['input_tokens'] ?? 0,
            'completion_tokens' => $body['usage']['output_tokens'] ?? 0,
        ];
    }

    /**
     * Parse JSON response from AI, handling potential markdown code blocks.
     *
     * @return list<array{title: string, description: string, assigned_to_name: ?string, source_text: string, priority: string, due_date: ?string}>
     */
    private function parseJsonResponse(string $text): array
    {
        // Strip markdown code blocks if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```\s*$/m', '', (string) $text);
        $text = mb_trim($text);

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            Log::warning('AI returned invalid JSON for action item extraction', ['response' => $text]);

            return [];
        }

        // Handle both direct array and wrapped { "action_items": [...] } format
        $items = $decoded['action_items'] ?? $decoded;

        if (! is_array($items) || (count($items) > 0 && ! isset($items[0]))) {
            return [];
        }

        return array_map(fn (array $item): array => [
            'title' => $item['title'] ?? 'Untitled action item',
            'description' => $item['description'] ?? '',
            'assigned_to_name' => $item['assigned_to_name'] ?? null,
            'source_text' => $item['source_text'] ?? '',
            'priority' => $item['priority'] ?? 'medium',
            'due_date' => $item['due_date'] ?? null,
        ], $items);
    }

    /**
     * @return list<array{segment_index: ?int, agenda_item_index: ?int, speaker_name: string, statement: string, notes: ?string}>
     */
    private function parseDiscussionLogResponse(string $text): array
    {
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```\s*$/m', '', (string) $text);
        $text = mb_trim($text);

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            Log::warning('AI returned invalid JSON for discussion log', ['response' => $text]);

            return [];
        }

        $entries = $decoded['entries'] ?? $decoded;

        if (! is_array($entries) || (count($entries) > 0 && ! isset($entries[0]))) {
            return [];
        }

        return array_map(fn (array $entry): array => [
            'segment_index' => $entry['segment_index'] ?? null,
            'agenda_item_index' => $entry['agenda_item_index'] ?? null,
            'speaker_name' => $entry['speaker_name'] ?? 'Unknown',
            'statement' => $entry['statement'] ?? '',
            'notes' => $entry['notes'] ?? null,
        ], $entries);
    }
}
