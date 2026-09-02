# Meeting Minutes & Action Tracker SaaS
## Part 4: Technical Implementation Guide - Backend & AWS

**Version:** 2.0  
**Last Updated:** February 2025

---

## Laravel Backend Implementation

### Core Controllers

#### Meeting Controller

```php
<?php
// app/Http/Controllers/Api/MeetingController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMeetingRequest;
use App\Http\Requests\UpdateMeetingRequest;
use App\Http\Resources\MeetingResource;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Notifications\MeetingInvitationNotification;
use App\Services\FeatureGate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MeetingController extends Controller
{
    /**
     * Create a new meeting
     */
    public function store(StoreMeetingRequest $request)
    {
        // Check feature gate
        if (!FeatureGate::canCreateMeeting()) {
            return response()->json([
                'error' => 'Meeting limit reached for your plan',
                'current_usage' => auth()->user()->tenant->meetings()
                    ->whereMonth('created_at', now()->month)
                    ->count(),
                'plan_limit' => auth()->user()->tenant->subscription->plan->meetings_per_month,
                'upgrade_url' => route('subscriptions.plans')
            ], 403);
        }
        
        $tenant = auth()->user()->tenant;
        
        $meeting = Meeting::create([
            'tenant_id' => $tenant->id,
            'title' => $request->title,
            'description' => $request->description,
            'agenda' => $request->agenda,
            'scheduled_at' => $request->scheduled_at,
            'organizer_id' => auth()->id(),
            'secretary_id' => $request->secretary_id,
            'recording_mode' => $request->recording_mode ?? $tenant->settings->default_recording_mode,
            'enable_floor_control' => $request->enable_floor_control ?? $tenant->settings->enable_floor_control,
            'audio_retention_days' => $tenant->settings->default_audio_retention_days,
            'storage_option' => $tenant->settings->default_storage_option,
            'status' => 'scheduled'
        ]);
        
        // Create organizer as participant
        MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'user_id' => auth()->id(),
            'email' => auth()->user()->email,
            'name' => auth()->user()->name,
            'role' => 'organizer',
            'join_token' => Str::random(32),
            'status' => 'joined'
        ]);
        
        // Invite participants
        if ($request->has('participants')) {
            foreach ($request->participants as $participantData) {
                $participant = MeetingParticipant::create([
                    'meeting_id' => $meeting->id,
                    'user_id' => $participantData['user_id'] ?? null,
                    'email' => $participantData['email'],
                    'name' => $participantData['name'],
                    'role' => $participantData['role'] ?? 'participant',
                    'join_token' => Str::random(32),
                    'status' => 'invited'
                ]);
                
                // Send invitation email
                if ($tenant->settings->send_meeting_invitations) {
                    $participant->notify(new MeetingInvitationNotification($meeting));
                }
            }
        }
        
        // Audit log
        \App\Models\AuditLog::logEvent('meeting_created', $meeting, [
            'participants_invited' => count($request->participants ?? [])
        ]);
        
        return new MeetingResource($meeting->load('participants'));
    }
    
    /**
     * Start a meeting
     */
    public function start(Request $request, Meeting $meeting)
    {
        $this->authorize('start', $meeting);
        
        $meeting->update([
            'status' => 'in_progress',
            'started_at' => now()
        ]);
        
        // Broadcast to WebSocket
        broadcast(new \App\Events\MeetingStarted($meeting))->toOthers();
        
        // Notify participants
        foreach ($meeting->participants as $participant) {
            if ($participant->user_id) {
                $participant->user->notify(
                    new \App\Notifications\MeetingStartedNotification($meeting)
                );
            }
        }
        
        // Audit log
        \App\Models\AuditLog::logEvent('meeting_started', $meeting);
        
        return new MeetingResource($meeting);
    }
    
    /**
     * End a meeting and trigger processing
     */
    public function end(Request $request, Meeting $meeting)
    {
        $this->authorize('end', $meeting);
        
        $meeting->update([
            'status' => 'completed',
            'ended_at' => now()
        ]);
        
        // Trigger processing pipeline
        \App\Jobs\ProcessMeetingJob::dispatch($meeting)
            ->onQueue('processing')
            ->delay(now()->addSeconds(30)); // Give time for final uploads
        
        // Broadcast to WebSocket
        broadcast(new \App\Events\MeetingEnded($meeting))->toOthers();
        
        // Send event to AWS EventBridge
        $this->sendToEventBridge([
            'source' => 'custom.meetings',
            'detail-type' => 'MeetingEnded',
            'detail' => [
                'meeting_id' => $meeting->id,
                'tenant_id' => $meeting->tenant_id,
                'duration_minutes' => $meeting->duration_minutes,
                'participant_count' => $meeting->participants()->count(),
                'audio_segments' => $meeting->audioSegments()->count()
            ]
        ]);
        
        // Audit log
        \App\Models\AuditLog::logEvent('meeting_ended', $meeting, [
            'duration_minutes' => $meeting->duration_minutes
        ]);
        
        return new MeetingResource($meeting);
    }
    
    /**
     * Get S3 pre-signed upload URL
     */
    public function getUploadUrl(Request $request, Meeting $meeting)
    {
        $request->validate([
            'participant_id' => 'required|uuid|exists:meeting_participants,id',
            'sequence_number' => 'required|integer|min:1',
            'duration_seconds' => 'required|numeric|min:0'
        ]);
        
        // Verify participant belongs to this meeting
        $participant = MeetingParticipant::findOrFail($request->participant_id);
        
        if ($participant->meeting_id !== $meeting->id) {
            abort(403, 'Participant does not belong to this meeting');
        }
        
        // Verify tenant isolation
        if ($meeting->tenant_id !== auth()->user()->tenant_id) {
            abort(403, 'Unauthorized access to meeting');
        }
        
        // Generate S3 key
        $timestamp = now()->timestamp;
        $key = sprintf(
            'audio-chunks/%s/%s/%s/%05d_%d.webm',
            $meeting->tenant_id,
            $meeting->id,
            $participant->id,
            $request->sequence_number,
            $timestamp
        );
        
        // Create pre-signed URL
        $s3Client = app('aws.s3');
        
        $cmd = $s3Client->getCommand('PutObject', [
            'Bucket' => config('aws.audio_bucket'),
            'Key' => $key,
            'ContentType' => 'audio/webm',
            'Metadata' => [
                'tenant-id' => $meeting->tenant_id,
                'meeting-id' => $meeting->id,
                'participant-id' => $participant->id,
                'user-id' => auth()->id()
            ]
        ]);
        
        $presignedRequest = $s3Client->createPresignedRequest($cmd, '+15 minutes');
        
        return response()->json([
            'upload_url' => (string) $presignedRequest->getUri(),
            's3_key' => $key,
            's3_bucket' => config('aws.audio_bucket'),
            'expires_at' => now()->addMinutes(15)->toIso8601String()
        ]);
    }
    
    /**
     * Confirm audio chunk uploaded
     */
    public function confirmChunkUploaded(Request $request, Meeting $meeting)
    {
        $request->validate([
            'participant_id' => 'required|uuid',
            's3_key' => 'required|string',
            'sequence_number' => 'required|integer',
            'file_size_bytes' => 'required|integer|min:0',
            'duration_seconds' => 'numeric|min:0'
        ]);
        
        // Create audio segment record
        $audioSegment = \App\Models\AudioSegment::create([
            'meeting_id' => $meeting->id,
            'participant_id' => $request->participant_id,
            's3_key' => $request->s3_key,
            's3_bucket' => config('aws.audio_bucket'),
            'sequence_number' => $request->sequence_number,
            'file_size_bytes' => $request->file_size_bytes,
            'duration_seconds' => $request->duration_seconds,
            'recorded_at' => now(),
            'scheduled_deletion_at' => now()->addDays($meeting->audio_retention_days)
        ]);
        
        // Update participant stats
        $participant = MeetingParticipant::find($request->participant_id);
        $participant->increment('chunks_uploaded');
        $participant->speaking_duration_seconds += $request->duration_seconds ?? 0;
        $participant->last_spoke_at = now();
        $participant->save();
        
        return response()->json([
            'success' => true,
            'audio_segment_id' => $audioSegment->id,
            'total_chunks' => $participant->chunks_uploaded
        ]);
    }
    
    /**
     * Send event to AWS EventBridge
     */
    private function sendToEventBridge(array $event)
    {
        try {
            $eventBridge = app('aws.eventbridge');
            
            $eventBridge->putEvents([
                'Entries' => [
                    [
                        'Source' => $event['source'],
                        'DetailType' => $event['detail-type'],
                        'Detail' => json_encode($event['detail']),
                        'EventBusName' => config('aws.event_bus', 'default')
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to send event to EventBridge', [
                'event' => $event,
                'error' => $e->getMessage()
            ]);
        }
    }
}
```

---

### Queue Jobs

#### Process Meeting Job

```php
<?php
// app/Jobs/ProcessMeetingJob.php

namespace App\Jobs;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMeetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $tries = 3;
    public $timeout = 600; // 10 minutes
    
    public function __construct(
        public Meeting $meeting
    ) {}
    
    public function handle()
    {
        \Log::info("Starting to process meeting {$this->meeting->id}");
        
        // Step 1: Verify all audio segments are transcribed
        $this->waitForTranscriptions();
        
        // Step 2: Clean up transcripts
        CleanupTranscriptJob::dispatch($this->meeting)
            ->onQueue('processing');
        
        // Step 3: Extract action items (chained after cleanup)
        ExtractActionItemsJob::dispatch($this->meeting)
            ->onQueue('processing')
            ->delay(now()->addMinutes(2));
        
        // Step 4: Generate minutes (chained after extraction)
        GenerateMeetingMinutesJob::dispatch($this->meeting)
            ->onQueue('processing')
            ->delay(now()->addMinutes(5));
    }
    
    private function waitForTranscriptions()
    {
        $maxWaitSeconds = 300; // 5 minutes
        $elapsed = 0;
        
        while ($elapsed < $maxWaitSeconds) {
            $pendingTranscriptions = $this->meeting->audioSegments()
                ->where('transcribed', false)
                ->count();
            
            if ($pendingTranscriptions === 0) {
                return true;
            }
            
            \Log::info("Waiting for {$pendingTranscriptions} transcriptions to complete");
            sleep(10);
            $elapsed += 10;
        }
        
        throw new \Exception("Transcription timeout after {$maxWaitSeconds} seconds");
    }
    
    public function failed(\Throwable $exception)
    {
        \Log::error("Failed to process meeting {$this->meeting->id}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
        
        // Notify secretary
        if ($this->meeting->secretary) {
            $this->meeting->secretary->notify(
                new \App\Notifications\MeetingProcessingFailedNotification(
                    $this->meeting,
                    $exception->getMessage()
                )
            );
        }
    }
}
```

#### Cleanup Transcript Job

```php
<?php
// app/Jobs/CleanupTranscriptJob.php

namespace App\Jobs;

use App\Models\Meeting;
use App\Models\TranscriptSegment;
use App\Services\AIProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class CleanupTranscriptJob implements ShouldQueue
{
    use Queueable;
    
    public $tries = 3;
    public $timeout = 300;
    
    public function __construct(
        public Meeting $meeting
    ) {}
    
    public function handle(AIProvider $ai)
    {
        $segments = TranscriptSegment::where('meeting_id', $this->meeting->id)
            ->whereNotNull('raw_text')
            ->orderBy('segment_number')
            ->get();
        
        if ($segments->isEmpty()) {
            \Log::warning("No transcript segments found for meeting {$this->meeting->id}");
            return;
        }
        
        // Group by speaker for better context
        $groupedSegments = $segments->groupBy('speaker_name');
        
        foreach ($groupedSegments as $speakerName => $speakerSegments) {
            $rawText = $speakerSegments->pluck('raw_text')->implode(' ');
            
            // Skip very short segments
            if (strlen($rawText) < 50) {
                continue;
            }
            
            // Call AI for cleanup
            $cleanedText = $ai->cleanupTranscript($rawText, $speakerName);
            
            // Update segments with cleaned text
            foreach ($speakerSegments as $segment) {
                $segment->update([
                    'cleaned_text' => $cleanedText,
                    'processed' => true
                ]);
            }
            
            \Log::info("Cleaned transcript for speaker {$speakerName} in meeting {$this->meeting->id}");
        }
        
        // Mark meeting transcription as completed
        $this->meeting->update(['transcription_status' => 'completed']);
    }
}
```

#### Extract Action Items Job

```php
<?php
// app/Jobs/ExtractActionItemsJob.php

namespace App\Jobs;

use App\Models\Meeting;
use App\Models\ActionItem;
use App\Models\User;
use App\Services\AIProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ExtractActionItemsJob implements ShouldQueue
{
    use Queueable;
    
    public $tries = 3;
    public $timeout = 300;
    
    public function __construct(
        public Meeting $meeting
    ) {}
    
    public function handle(AIProvider $ai)
    {
        // Get cleaned transcript
        $cleanedTranscript = $this->meeting->transcriptSegments()
            ->whereNotNull('cleaned_text')
            ->orderBy('segment_number')
            ->get()
            ->map(fn($segment) => "{$segment->speaker_name}: {$segment->cleaned_text}")
            ->implode("\n\n");
        
        if (empty($cleanedTranscript)) {
            \Log::warning("No cleaned transcript available for meeting {$this->meeting->id}");
            return;
        }
        
        // Extract action items with AI
        $actionItems = $ai->extractActionItems($cleanedTranscript);
        
        if (empty($actionItems)) {
            \Log::info("No action items extracted from meeting {$this->meeting->id}");
            return;
        }
        
        // Store in database
        foreach ($actionItems as $item) {
            // Try to match assignee to user
            $assignedUser = $this->findUserByName($item['assigned_to'] ?? null);
            
            $actionItem = ActionItem::create([
                'meeting_id' => $this->meeting->id,
                'tenant_id' => $this->meeting->tenant_id,
                'title' => $item['task'],
                'description' => $item['context'] ?? null,
                'assigned_to' => $assignedUser?->id,
                'assigned_to_name' => $item['assigned_to'] ?? 'Unassigned',
                'assigned_by' => $this->meeting->secretary_id ?? $this->meeting->organizer_id,
                'due_date' => $this->parseDueDate($item['due_date'] ?? null),
                'due_confirmation_status' => isset($item['due_date']) ? 'confirmed' : 'needs_confirmation',
                'priority' => $item['priority'] ?? 'medium',
                'source_quote' => $item['source_quote'] ?? null,
                'status' => 'pending'
            ]);
            
            \Log::info("Created action item {$actionItem->id} for meeting {$this->meeting->id}");
            
            // Send notification to assignee
            if ($assignedUser) {
                $assignedUser->notify(
                    new \App\Notifications\TaskAssignedNotification($actionItem)
                );
            }
        }
        
        // Trigger task distribution job
        DistributeTasksJob::dispatch($this->meeting)
            ->delay(now()->addMinutes(2));
    }
    
    private function findUserByName(?string $name): ?User
    {
        if (empty($name) || $name === 'Unassigned') {
            return null;
        }
        
        // Exact match first
        $user = User::where('tenant_id', $this->meeting->tenant_id)
            ->where('name', 'LIKE', $name)
            ->first();
        
        if ($user) {
            return $user;
        }
        
        // Try fuzzy match on meeting participants
        $participant = $this->meeting->participants()
            ->where('name', 'LIKE', "%{$name}%")
            ->first();
        
        return $participant?->user;
    }
    
    private function parseDueDate(?string $date): ?\DateTime
    {
        if (empty($date)) {
            return null;
        }
        
        try {
            return new \DateTime($date);
        } catch (\Exception $e) {
            \Log::warning("Failed to parse due date: {$date}");
            return null;
        }
    }
}
```

---

### AI Provider Service

```php
<?php
// app/Services/AIProvider.php

namespace App\Services;

interface AIProvider
{
    /**
     * Clean up transcript text
     */
    public function cleanupTranscript(string $rawText, string $speakerName): string;
    
    /**
     * Extract action items from transcript
     */
    public function extractActionItems(string $transcript): array;
    
    /**
     * Generate professional meeting minutes
     */
    public function generateMinutes(array $context): string;
}
```

```php
<?php
// app/Services/BedrockProvider.php

namespace App\Services;

use Aws\BedrockRuntime\BedrockRuntimeClient;

class BedrockProvider implements AIProvider
{
    private BedrockRuntimeClient $client;
    
    public function __construct()
    {
        $this->client = new BedrockRuntimeClient([
            'region' => config('aws.region'),
            'version' => 'latest'
        ]);
    }
    
    public function cleanupTranscript(string $rawText, string $speakerName): string
    {
        $systemPrompt = <<<PROMPT
You are a professional secretary cleaning up meeting transcripts.

TASK:
- Fix grammar and punctuation
- Remove filler words (um, uh, like, you know, etc.)
- Organize into coherent sentences and paragraphs
- Preserve the original meaning and all factual information
- Do NOT add information that wasn't said
- Do NOT summarize or shorten the content

FORMAT:
Return only the cleaned text, no preamble or commentary.
PROMPT;
        
        $response = $this->client->invokeModel([
            'modelId' => 'anthropic.claude-3-haiku-20240307-v1:0',
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode([
                'anthropic_version' => 'bedrock-2023-05-31',
                'max_tokens' => 4000,
                'temperature' => 0.3,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => "{$systemPrompt}\n\nSpeaker: {$speakerName}\n\nTranscript:\n{$rawText}"
                    ]
                ]
            ])
        ]);
        
        $result = json_decode($response['body']->getContents(), true);
        
        return $result['content'][0]['text'] ?? $rawText;
    }
    
    public function extractActionItems(string $transcript): array
    {
        $systemPrompt = <<<PROMPT
Extract action items from this meeting transcript.

For each action item, identify:
1. The task description (concise but complete)
2. Who it's assigned to (participant name)
3. Due date if mentioned (YYYY-MM-DD format)
4. Priority (low, medium, high, urgent)
5. The exact quote where it was mentioned

IMPORTANT:
- Only extract tasks explicitly mentioned or clearly implied
- If no assignee is clear, use "Unassigned"
- If no due date mentioned, omit the due_date field
- Be conservative: quality over quantity

Return ONLY a JSON array with NO markdown formatting:
[
  {
    "task": "Complete Q4 financial report",
    "assigned_to": "John Smith",
    "due_date": "2025-02-20",
    "priority": "high",
    "source_quote": "John, can you finish the Q4 report by next Friday?",
    "context": "Needs to be ready before board meeting"
  }
]
PROMPT;
        
        $response = $this->client->invokeModel([
            'modelId' => 'anthropic.claude-3-haiku-20240307-v1:0',
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode([
                'anthropic_version' => 'bedrock-2023-05-31',
                'max_tokens' => 4000,
                'temperature' => 0.2,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => "{$systemPrompt}\n\nTranscript:\n{$transcript}"
                    ]
                ]
            ])
        ]);
        
        $result = json_decode($response['body']->getContents(), true);
        $content = $result['content'][0]['text'] ?? '[]';
        
        // Remove markdown code blocks if present
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*$/', '', $content);
        
        try {
            return json_decode(trim($content), true) ?? [];
        } catch (\Exception $e) {
            \Log::error("Failed to parse action items JSON", [
                'content' => $content,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    public function generateMinutes(array $context): string
    {
        $systemPrompt = <<<PROMPT
Generate professional meeting minutes in Markdown format.

REQUIRED STRUCTURE:
# {Meeting Title}
**Date:** {Date}  
**Time:** {Start Time} - {End Time}  
**Duration:** {Duration}

## Attendees
- [List all attendees]

## Executive Summary
[2-3 paragraph overview of meeting outcomes]

## Agenda & Discussion
[Organize key discussion points by topic]

## Key Decisions
[Numbered list of decisions made]

## Action Items
| Task | Assigned To | Due Date | Priority |
|------|-------------|----------|----------|
[Table of action items]

## Risks & Blockers
[If any were identified]

## Parking Lot
[Topics deferred to future meetings]

## Next Steps
[Brief overview of what happens next]

TONE: Professional, concise, factual. Use active voice.
PROMPT;
        
        $userPrompt = $this->formatContextForPrompt($context);
        
        $response = $this->client->invokeModel([
            'modelId' => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode([
                'anthropic_version' => 'bedrock-2023-05-31',
                'max_tokens' => 4000,
                'temperature' => 0.3,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => "{$systemPrompt}\n\n{$userPrompt}"
                    ]
                ]
            ])
        ]);
        
        $result = json_decode($response['body']->getContents(), true);
        
        return $result['content'][0]['text'] ?? '';
    }
    
    private function formatContextForPrompt(array $context): string
    {
        $formatted = "Meeting Details:\n";
        $formatted .= "Title: {$context['title']}\n";
        $formatted .= "Date: {$context['date']}\n";
        $formatted .= "Time: {$context['start_time']} - {$context['end_time']}\n";
        $formatted .= "Duration: {$context['duration']}\n\n";
        
        $formatted .= "Attendees:\n";
        foreach ($context['attendees'] as $attendee) {
            $formatted .= "- {$attendee}\n";
        }
        
        $formatted .= "\nTranscript:\n{$context['transcript']}\n\n";
        
        $formatted .= "Action Items:\n";
        $formatted .= json_encode($context['action_items'], JSON_PRETTY_PRINT);
        
        return $formatted;
    }
}
```

---

## AWS Lambda Functions

### Audio Processor Lambda

```python
# lambda/audio_processor.py

import boto3
import json
import os
import logging
from datetime import datetime
import requests

# Configure logging
logger = logging.getLogger()
logger.setLevel(logging.INFO)

# AWS Clients
transcribe = boto3.client('transcribe')
s3 = boto3.client('s3')

# Environment variables
LARAVEL_API_URL = os.environ['LARAVEL_API_URL']
LARAVEL_API_KEY = os.environ['LARAVEL_API_KEY']

def lambda_handler(event, context):
    """
    Triggered when audio chunk uploaded to S3.
    Starts AWS Transcribe job for the audio segment.
    """
    
    try:
        # Parse S3 event
        for record in event['Records']:
            bucket = record['s3']['bucket']['name']
            key = record['s3']['object']['key']
            
            logger.info(f"Processing audio: s3://{bucket}/{key}")
            
            # Validate S3 key format
            # Expected: audio-chunks/{tenant_id}/{meeting_id}/{participant_id}/{sequence}_{timestamp}.webm
            if not key.startswith('audio-chunks/'):
                logger.warning(f"Ignoring file with invalid prefix: {key}")
                continue
            
            parts = key.split('/')
            if len(parts) < 5:
                logger.warning(f"Invalid S3 key format: {key}")
                continue
            
            tenant_id = parts[1]
            meeting_id = parts[2]
            participant_id = parts[3]
            filename = parts[4]
            
            # Extract sequence number and timestamp from filename
            file_parts = filename.replace('.webm', '').split('_')
            sequence_number = file_parts[0]
            
            # Create unique job name
            job_name = f"{meeting_id}_{participant_id}_{sequence_number}"
            
            # Truncate if too long (Transcribe has 200 char limit)
            if len(job_name) > 200:
                job_name = job_name[:200]
            
            # Check if job already exists
            try:
                existing_job = transcribe.get_transcription_job(
                    TranscriptionJobName=job_name
                )
                logger.info(f"Transcription job already exists: {job_name}")
                continue
            except transcribe.exceptions.BadRequestException:
                # Job doesn't exist, proceed
                pass
            
            # Start transcription job
            response = transcribe.start_transcription_job(
                TranscriptionJobName=job_name,
                Media={'MediaFileUri': f's3://{bucket}/{key}'},
                MediaFormat='webm',
                LanguageCode='en-US',
                Settings={
                    'ShowSpeakerLabels': False,  # We know speaker from participant_id
                    'MaxSpeakerLabels': 1,
                    'ChannelIdentification': False
                },
                OutputBucketName=bucket,
                OutputKey=f'transcripts/{tenant_id}/{meeting_id}/{filename}.json',
                JobExecutionSettings={
                    'AllowDeferredExecution': False
                }
            )
            
            logger.info(f"Started transcription job: {job_name}")
            
            # Notify Laravel API
            notify_laravel({
                'job_name': job_name,
                'meeting_id': meeting_id,
                'participant_id': participant_id,
                'audio_s3_key': key,
                'status': 'in_progress',
                'started_at': datetime.utcnow().isoformat()
            })
            
        return {
            'statusCode': 200,
            'body': json.dumps({'message': 'Transcription jobs started'})
        }
        
    except Exception as e:
        logger.error(f"Error processing audio: {str(e)}", exc_info=True)
        return {
            'statusCode': 500,
            'body': json.dumps({'error': str(e)})
        }

def notify_laravel(job_data):
    """Notify Laravel API about transcription job"""
    try:
        response = requests.post(
            f"{LARAVEL_API_URL}/api/transcription-jobs",
            json=job_data,
            headers={
                'Authorization': f'Bearer {LARAVEL_API_KEY}',
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            timeout=10
        )
        response.raise_for_status()
        logger.info(f"Notified Laravel: {response.status_code}")
    except Exception as e:
        logger.error(f"Failed to notify Laravel: {str(e)}")
```

---

This completes Part 4. Ready for Part 5 (Frontend Implementation & Cost Analysis)?
