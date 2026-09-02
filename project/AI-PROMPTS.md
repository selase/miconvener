# AI Coding Assistant Prompts
## Complete Reference Guide for Meeting Minutes SaaS

**Purpose:** This document contains all the prompts you can use with AI coding assistants (Claude Code, Cursor, GitHub Copilot, etc.) to implement the Meeting Minutes SaaS platform.

---

## Table of Contents

1. [Backend Development Prompts](#backend-development-prompts)
2. [Frontend Development Prompts](#frontend-development-prompts)
3. [AWS Lambda Prompts](#aws-lambda-prompts)
4. [DevOps & Infrastructure Prompts](#devops--infrastructure-prompts)
5. [Mobile Development Prompts](#mobile-development-prompts)

---

## Backend Development Prompts

### Prompt 1: Laravel Meeting Management System

```
TASK: Create Laravel 12 meeting management system with multi-tenant architecture

CONTEXT:
- Using existing Laravel 12 starter kit with multi-tenancy, auth, and payments
- Building on top of existing infrastructure
- Need to integrate with AWS services

REQUIREMENTS:

1. Models & Migrations:
   - Meeting (tenant_id, title, description, scheduled_at, organizer_id, secretary_id, status, recording_mode)
   - MeetingParticipant (meeting_id, user_id, email, name, role, join_token, status)
   - AudioSegment (meeting_id, participant_id, s3_key, s3_bucket, sequence_number, transcribed)
   - TranscriptSegment (meeting_id, participant_id, raw_text, cleaned_text, speaker_name)
   - ActionItem (meeting_id, assigned_to, title, due_date, priority, status)
   - MeetingMinutes (meeting_id, version, full_content, approved_by)
   - Global scope for tenant isolation on all models

2. Relationships:
   - Meeting hasMany Participants, AudioSegments, TranscriptSegments, ActionItems
   - Meeting hasOne MeetingMinutes
   - Meeting belongsTo Organizer, Secretary
   - ActionItem belongsTo Meeting, User (assigned_to)

3. Controllers:
   - MeetingController with: store, show, start, end, getUploadUrl, confirmChunkUploaded
   - ParticipantController with: invite, join, list
   - TranscriptController with: show, segments
   - ActionItemController with: index, update (status)

4. API Resources:
   - MeetingResource (include participants count, duration, status)
   - ParticipantResource
   - TranscriptSegmentResource
   - ActionItemResource

5. Form Requests:
   - StoreMeetingRequest (validate title, scheduled_at, participants array)
   - InviteParticipantRequest

6. Policies:
   - MeetingPolicy: start() only organizer/secretary, end() only organizer/secretary, view() only participants
   - ActionItemPolicy: update() only assignee or organizer

7. Feature Gates:
   - Check tenant subscription plan limits
   - FeatureGate::canCreateMeeting() - check against plan limit
   - FeatureGate::canUseAudioRetention() - check plan feature
   - Return 403 with upgrade URL if limit exceeded

8. Events & Notifications:
   - MeetingStarted event (broadcast via WebSocket)
   - MeetingEnded event (broadcast + AWS EventBridge)
   - MeetingInvitationNotification (email with join link)
   - TaskAssignedNotification (email to assignee)

9. Audit Logging:
   - AuditLog::logEvent() for: meeting_created, meeting_started, meeting_ended, transcript_viewed, minutes_approved

10. Testing:
    - Feature tests for meeting CRUD
    - Test tenant isolation (user A cannot access user B's meetings)
    - Test email sending (use Mail::fake())
    - Test S3 pre-signed URL generation

TECH STACK: Laravel 12, PostgreSQL, Spatie Laravel Permission, Laravel Sanctum, AWS SDK for PHP

OUTPUT STRUCTURE:
```
app/
├── Models/
│   ├── Meeting.php
│   ├── MeetingParticipant.php
│   ├── AudioSegment.php
│   ├── TranscriptSegment.php
│   ├── ActionItem.php
│   └── MeetingMinutes.php
├── Http/
│   ├── Controllers/Api/
│   │   ├── MeetingController.php
│   │   ├── ParticipantController.php
│   │   └── ActionItemController.php
│   ├── Requests/
│   │   ├── StoreMeetingRequest.php
│   │   └── InviteParticipantRequest.php
│   └── Resources/
│       ├── MeetingResource.php
│       └── ActionItemResource.php
├── Policies/
│   └── MeetingPolicy.php
├── Services/
│   └── FeatureGate.php
└── Notifications/
    ├── MeetingInvitationNotification.php
    └── TaskAssignedNotification.php

database/
└── migrations/
    ├── create_meetings_table.php
    ├── create_meeting_participants_table.php
    ├── create_audio_segments_table.php
    ├── create_transcript_segments_table.php
    └── create_action_items_table.php

tests/
└── Feature/
    ├── MeetingManagementTest.php
    ├── ParticipantInvitationTest.php
    └── TenantIsolationTest.php
```

IMPORTANT:
- Use comprehensive PHPDoc comments
- Include proper error handling
- Implement exponential backoff for retries
- Use database transactions where appropriate
- Follow Laravel 12 best practices
```

---

### Prompt 2: Queue Jobs for AI Processing

```
TASK: Create Laravel queue jobs for meeting processing pipeline

CONTEXT:
- Jobs run after meeting ends
- Process transcripts, extract actions, generate minutes
- Use AWS Bedrock (Claude) for AI processing
- Chain jobs in sequence

REQUIREMENTS:

1. ProcessMeetingJob (Orchestrator):
   - Dispatched when meeting ends
   - Wait for all transcriptions to complete (max 5 minutes)
   - Chain subsequent jobs with delays
   - Handle failure notifications

2. CleanupTranscriptJob:
   - Fetch raw transcript segments
   - Group by speaker
   - Call AI to fix grammar, remove filler words
   - Preserve original meaning
   - Update segments with cleaned_text
   - Mark as processed

3. ExtractActionItemsJob:
   - Fetch cleaned transcript
   - Call AI with extraction prompt
   - Parse JSON response
   - Match assigned_to names to users (fuzzy matching)
   - Create ActionItem records
   - Send notifications to assignees

4. GenerateMeetingMinutesJob:
   - Prepare context: meeting details, attendees, transcript, actions
   - Call AI to generate professional minutes (Markdown format)
   - Structure: Executive Summary, Attendees, Discussion, Decisions, Actions, Next Steps
   - Convert Markdown to PDF using dompdf
   - Upload PDF to S3
   - Create MeetingMinutes record
   - Notify secretary for approval

5. DistributeTasksJob:
   - Fetch all action items for meeting
   - Email each participant their assigned tasks
   - Create task reminders (3 days before due)
   - Schedule follow-up reminders

6. AI Provider Service:
   - Interface: AIProvider with methods: cleanupTranscript(), extractActionItems(), generateMinutes()
   - Implementation: BedrockProvider using AWS Bedrock SDK
   - Fallback: OpenAIProvider using OpenAI SDK
   - Strategy pattern to switch providers

7. Error Handling:
   - Retry 3 times with exponential backoff
   - Log errors to CloudWatch
   - Send failure notification to secretary
   - Store failed job data for manual review

8. Cost Tracking:
   - Log AI tokens used (input + output)
   - Calculate cost per meeting
   - Store in usage_metrics table

TECH STACK: Laravel 12, Laravel Horizon, AWS Bedrock SDK, OpenAI PHP Client

AI PROMPTS TO USE:

Cleanup Prompt:
```
You are a professional secretary cleaning up meeting transcripts.

TASK:
- Fix grammar and punctuation
- Remove filler words (um, uh, like, you know, etc.)
- Organize into coherent sentences and paragraphs
- Preserve the original meaning and all factual information
- Do NOT add information that wasn't said
- Do NOT summarize or shorten the content

FORMAT: Return only the cleaned text, no preamble or commentary.
```

Extraction Prompt:
```
Extract action items from this meeting transcript.

For each action item, identify:
1. Task description (concise but complete)
2. Who it's assigned to (participant name)
3. Due date if mentioned (YYYY-MM-DD)
4. Priority (low, medium, high, urgent)
5. Exact quote from transcript

Return ONLY a JSON array:
[
  {
    "task": "Complete Q4 report",
    "assigned_to": "John Smith",
    "due_date": "2025-02-20",
    "priority": "high",
    "source_quote": "John, finish the Q4 report by next Friday"
  }
]
```

OUTPUT:
- Job classes in app/Jobs/
- AIProvider interface in app/Services/
- BedrockProvider implementation
- Unit tests with mocked AI responses
- Queue configuration in config/horizon.php
```

---

## Frontend Development Prompts

### Prompt 3: React PWA with Push-to-Talk Recording

```
TASK: Build React PWA for meeting participation with push-to-talk audio recording

CONTEXT:
- Progressive Web App installable from browser
- Works on mobile and desktop
- Must work offline with IndexedDB buffering
- Direct upload to S3 using pre-signed URLs

REQUIREMENTS:

1. Core Components:
   - MeetingLobby: Join meeting, voice enrollment (10s recording)
   - MeetingRoom: Main interface with participant list and controls
   - PushToTalkRecorder: Large button for hold-to-speak recording
   - ParticipantList: Real-time list with speaking indicators
   - WebSocketProvider: Real-time connection management

2. Push-to-Talk Recording:
   - MediaRecorder API with Opus codec (audio/webm;codecs=opus)
   - 16kHz sample rate, mono channel
   - Start recording on mousedown/touchstart
   - Stop on mouseup/touchend
   - Auto-release after 5 minutes
   - Visual feedback: pulsing animation when recording
   - Recording timer display

3. Audio Upload Flow:
   - Chunk audio every 10 seconds
   - Call /api/meetings/{id}/upload-url to get S3 pre-signed URL
   - PUT request directly to S3 with audio blob
   - Call /api/meetings/{id}/audio-chunk to confirm
   - Show upload progress indicator

4. Offline Support:
   - Service Worker to intercept failed uploads
   - Store failed chunks in IndexedDB
   - Background Sync API to retry when online
   - Show "Offline" badge in UI
   - Queue indicator showing pending uploads

5. WebSocket Integration:
   - Connect to Laravel Reverb server
   - Subscribe to meeting channel
   - Broadcast "participant_speaking" when recording starts/stops
   - Listen for other participants' speaking events
   - Show live speaking indicators

6. UI/UX:
   - Large circular push-to-talk button (200px)
   - Blue when idle, red pulsing when recording
   - Participant list with avatars and status badges
   - Network status indicator
   - Recording timer
   - Upload progress bar

7. PWA Configuration:
   - Manifest.json with icons
   - Service worker for offline support
   - Cache API resources
   - Background sync for uploads
   - Install prompt

TECH STACK: React 18, TypeScript, TailwindCSS, Vite PWA, React Query, IndexedDB (idb library)

FILE STRUCTURE:
```
src/
├── components/
│   ├── MeetingLobby/
│   │   ├── MeetingLobby.tsx
│   │   └── VoiceEnrollment.tsx
│   ├── MeetingRoom/
│   │   ├── MeetingRoom.tsx
│   │   ├── PushToTalkRecorder.tsx
│   │   ├── ParticipantList.tsx
│   │   └── NetworkStatus.tsx
│   └── common/
│       └── LoadingSpinner.tsx
├── hooks/
│   ├── useWebSocket.ts
│   ├── useAudioRecorder.ts
│   └── useOfflineStorage.ts
├── services/
│   ├── offlineStorage.ts
│   ├── audioUpload.ts
│   └── api.ts
├── App.tsx
└── main.tsx

public/
├── manifest.json
├── service-worker.js
└── icons/
    ├── icon-192.png
    └── icon-512.png
```

CRITICAL REQUIREMENTS:
- NEVER use localStorage for audio (too small)
- ALWAYS use IndexedDB for offline chunks
- Implement exponential backoff for retries
- Show clear feedback for all user actions
- Handle permissions gracefully
- Test on iOS Safari and Chrome Android
```

---

### Prompt 4: Offline Storage Service with IndexedDB

```
TASK: Create robust offline storage service for audio chunks

CONTEXT:
- Users may lose connection during meetings
- Audio must not be lost
- Retry uploads when connection restored
- Handle multiple failed chunks efficiently

REQUIREMENTS:

1. IndexedDB Schema:
   - Database: audio-chunks
   - Object store: failed-chunks
   - Key: auto-incrementing id
   - Indexes: meetingId, timestamp
   - Max storage: 500MB

2. Core Functions:
   - storeFailedChunk(chunk): Store audio blob with metadata
   - retryFailedChunks(meetingId): Upload all pending chunks
   - getPendingChunks(): Get count of pending uploads
   - clearOldChunks(): Delete chunks older than 7 days

3. Chunk Metadata:
   - meetingId: string
   - participantId: string
   - audioBlob: Blob
   - sequenceNumber: number
   - timestamp: number
   - durationSeconds: number
   - retryCount: number
   - lastRetryAt: number

4. Retry Strategy:
   - Max 5 retries per chunk
   - Exponential backoff: 1s, 2s, 4s, 8s, 16s
   - Delete chunk after 5 failed retries
   - Log failures for debugging

5. Network Detection:
   - Listen to online/offline events
   - Auto-retry when back online
   - Show pending uploads count in UI
   - Warn if storage quota exceeded

6. Performance:
   - Batch operations in transactions
   - Use cursor for large datasets
   - Limit concurrent uploads to 3
   - Show progress for each upload

TECH STACK: TypeScript, idb library (IndexedDB wrapper)

OUTPUT: TypeScript service with full error handling
```

---

## AWS Lambda Prompts

### Prompt 5: Audio Processor Lambda

```
TASK: Create AWS Lambda function to start transcription jobs

CONTEXT:
- Triggered by S3 ObjectCreated events
- Starts AWS Transcribe jobs for audio chunks
- Notifies Laravel API when job starts

REQUIREMENTS:

1. S3 Event Handling:
   - Parse bucket and key from event
   - Validate key format: audio-chunks/{tenant}/{meeting}/{participant}/{sequence}.webm
   - Extract metadata from key path
   - Skip invalid file names

2. AWS Transcribe Job:
   - Generate unique job name: {meeting}_{participant}_{sequence}
   - Check if job already exists (avoid duplicates)
   - Start transcription with settings:
     * MediaFormat: webm
     * LanguageCode: en-US
     * ShowSpeakerLabels: false (we know speaker)
     * OutputBucket: same bucket
     * OutputKey: transcripts/{tenant}/{meeting}/{filename}.json

3. Laravel API Notification:
   - POST to /api/transcription-jobs
   - Include: job_name, meeting_id, participant_id, status, started_at
   - Use Bearer token authentication
   - Timeout: 10 seconds
   - Log errors but don't fail Lambda

4. Error Handling:
   - Catch all exceptions
   - Log to CloudWatch
   - Return 500 status on error
   - Don't retry automatically (S3 will retry)

5. Environment Variables:
   - LARAVEL_API_URL: Backend URL
   - LARAVEL_API_KEY: API token

RUNTIME: Python 3.11
MEMORY: 512MB
TIMEOUT: 5 minutes

DEPENDENCIES:
- boto3 (AWS SDK)
- requests (HTTP client)

OUTPUT: Python file with comprehensive logging
```

---

### Prompt 6: Transcription Orchestrator Lambda

```
TASK: Lambda to process completed transcriptions and store in database

CONTEXT:
- Triggered by AWS Transcribe job completion (CloudWatch Events)
- Fetches transcript from S3
- Stores in Laravel database via API

REQUIREMENTS:

1. Event Handling:
   - Parse TranscriptionJobName and status from event
   - Skip if status is not COMPLETED
   - Get job details from AWS Transcribe
   - Extract transcript S3 URI

2. Transcript Processing:
   - Download transcript JSON from S3
   - Parse results.items array
   - Extract words and confidence scores
   - Combine into full text
   - Calculate average confidence
   - Extract start/end times

3. Store in Database:
   - POST to /api/transcript-segments
   - Include: meeting_id, participant_id, raw_text, confidence, timestamps
   - Update transcription job status
   - Handle API failures gracefully

4. Metadata Extraction:
   - Parse meeting_id and participant_id from job name
   - Calculate duration from first/last item timestamps
   - Count total words
   - Flag low confidence segments (<0.7)

5. Error Handling:
   - Retry on API failures (3 attempts)
   - Log all errors to CloudWatch
   - Send SNS notification on failure
   - Update job status to 'failed'

RUNTIME: Python 3.11
MEMORY: 1GB
TIMEOUT: 5 minutes

OUTPUT: Complete Lambda with error handling
```

---

## DevOps & Infrastructure Prompts

### Prompt 7: Terraform AWS Infrastructure

```
TASK: Create Terraform configuration for complete AWS infrastructure

CONTEXT:
- Serverless architecture
- Event-driven processing
- Cost-optimized with pay-per-use

REQUIREMENTS:

1. Resources:
   - S3 bucket for audio with lifecycle policies
   - S3 bucket for transcripts
   - Lambda functions (audio processor, orchestrator)
   - IAM roles with least privilege
   - SQS queue for job processing
   - EventBridge rules for scheduling
   - CloudWatch Log Groups
   - Cost Anomaly Detector

2. S3 Configuration:
   - Versioning enabled
   - Server-side encryption (AES256)
   - Lifecycle rules:
     * Transition to STANDARD_IA after 7 days
     * Delete after 30 days
   - CORS for web uploads
   - Block public access

3. Lambda Configuration:
   - Runtime: Python 3.11
   - Memory: 512MB (audio processor), 1GB (orchestrator)
   - Timeout: 5 minutes
   - Reserved concurrency: 10 (cost control)
   - Environment variables from secrets
   - VPC attachment: false (no VPC needed)

4. IAM Policies:
   - Lambda execution role
   - S3 read/write permissions (scoped to specific prefixes)
   - Transcribe job permissions
   - Bedrock model invocation
   - CloudWatch Logs

5. EventBridge Rules:
   - MeetingEnded event pattern
   - Daily reminder schedule (cron: 0 9 * * ? *)
   - Route to correct Lambda targets

6. Cost Controls:
   - Budget alarms at $100, $500, $1000
   - Cost anomaly detection
   - SNS topic for alerts
   - Lambda reserved concurrency limits

VARIABLES:
- aws_region (default: us-east-1)
- environment (dev/staging/production)
- laravel_api_url
- laravel_api_key (sensitive)

OUTPUTS:
- S3 bucket names
- Lambda function ARNs
- IAM role ARNs

STRUCTURE:
```
terraform/
├── main.tf
├── s3.tf
├── lambda.tf
├── iam.tf
├── eventbridge.tf
├── cloudwatch.tf
├── variables.tf
└── outputs.tf
```

BEST PRACTICES:
- Use remote state in S3
- Enable state locking with DynamoDB
- Use terraform workspaces for environments
- Tag all resources consistently
```

---

## Mobile Development Prompts

### Prompt 8: NativePHP Background Recording Service

```
TASK: Create NativePHP service for background audio recording on iOS/Android

CONTEXT:
- NativePHP Mobile v3
- Must record even when app backgrounded
- Native API access for better battery life
- Local storage for offline chunks

REQUIREMENTS:

1. Service Setup:
   - Register background task on recording start
   - Configure audio recording settings:
     * Format: webm
     * Codec: opus
     * Sample rate: 16kHz
     * Channels: 1 (mono)
     * Quality: high
     * Chunk duration: 10 seconds

2. Recording Management:
   - Start/stop methods
   - Pause/resume support
   - Handle interruptions (phone calls, other apps)
   - Auto-restart after interruption
   - Track recording state

3. Chunk Handling:
   - Callback when chunk completes
   - Check network status
   - If online: upload immediately
   - If offline: store locally
   - Track sequence numbers

4. Offline Storage:
   - Store chunks in app's document directory
   - Organize by meeting ID
   - Include metadata file with each chunk
   - Max storage: 500MB
   - Cleanup old chunks (7+ days)

5. Network Sync:
   - Listen for network state changes
   - Auto-upload when connection restored
   - Show sync progress in UI
   - Retry failed uploads
   - Delete local file after successful upload

6. Battery Optimization:
   - Use native audio APIs (not web APIs)
   - Reduce sample rate to minimum (16kHz)
   - Compress chunks before upload
   - Batch small operations
   - Release resources when not recording

7. Notifications:
   - Show persistent notification when recording
   - Display recording time
   - Quick stop button
   - Pending uploads indicator

TECH STACK: NativePHP Mobile v3, Laravel

FILE STRUCTURE:
```
app/
├── Services/
│   ├── BackgroundAudioRecorder.php
│   ├── OfflineSync.php
│   └── NotificationManager.php
├── NativePHP/
│   ├── Audio/
│   │   └── AudioConfig.php
│   └── Background/
│       └── RecordingTask.php
```

TESTING:
- Test on iOS and Android
- Verify recording continues in background
- Test offline storage and sync
- Measure battery impact
- Test interruption handling
```

---

## Usage Instructions

### How to Use These Prompts

1. **Copy the entire prompt** including context and requirements
2. **Paste into your AI coding assistant** (Claude Code, Cursor, GitHub Copilot Chat)
3. **Review the generated code** carefully
4. **Test thoroughly** before deploying
5. **Iterate** if needed with follow-up prompts

### Follow-up Prompt Template

```
The code you generated works but has an issue with [describe issue].
Can you fix [specific problem] while maintaining [important feature]?

Current behavior: [what happens now]
Expected behavior: [what should happen]
Error message (if any): [paste error]
```

### Best Practices

- ✅ **Be specific** about requirements
- ✅ **Include context** about existing code
- ✅ **Specify tech stack** explicitly
- ✅ **Request tests** alongside implementation
- ✅ **Ask for error handling**
- ✅ **Request documentation/comments**

- ❌ **Don't assume** AI knows your project structure
- ❌ **Don't skip** testing generated code
- ❌ **Don't ignore** security considerations
- ❌ **Don't accept** code without understanding it

---

## Recommended AI Tools

### For Backend Development
- **Claude Code** (Excellent for Laravel)
- **Cursor** (Great for full-stack)
- **GitHub Copilot** (Good autocomplete)

### For Frontend Development
- **Cursor** (Best for React/TypeScript)
- **Claude Code** (Good for complex logic)
- **v0.dev** (UI components)

### For Infrastructure
- **Claude** (Best for Terraform)
- **ChatGPT** (Good for AWS configs)

---

This completes the AI coding assistant prompts reference guide!
