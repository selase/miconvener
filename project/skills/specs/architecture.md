# Architecture Specification

## Starter Kit Capabilities (Already Built)

The existing SaaS starter kit provides a production-ready foundation. These features are **available immediately** and do not need to be rebuilt:

| Capability                           | Status | Notes                                          |
| ------------------------------------ | ------ | ---------------------------------------------- |
| Multi-tenancy (shared/per-tenant DB) | Ready  | Tenant isolation, provisioning, health checks  |
| Authentication (Breeze + Sanctum)    | Ready  | Web + API auth, 2FA with Google Authenticator  |
| Authorization (Spatie Permissions)   | Ready  | Roles, permissions, feature-based entitlements |
| Billing (Stripe + Paystack)          | Ready  | Subscriptions, invoices, usage-based pricing   |
| Usage Metering                       | Ready  | Event recording, rollups, limits, alerts       |
| Feature Flags                        | Ready  | Boolean + metered features with gates          |
| API Key Management                   | Ready  | Encrypted keys, scopes, IP restrictions        |
| Webhooks                             | Ready  | Endpoint management, delivery, retry logic     |
| LLM Token Management                 | Ready  | Quota, topup, BYOK, spending limits            |
| Activity Logging                     | Ready  | Spatie Activity Log with audit trails          |
| Queue Infrastructure                 | Ready  | Redis queues, job processing                   |
| Email System                         | Ready  | Laravel mail with templates                    |
| Admin Dashboard                      | Ready  | Tenant management, analytics, user management  |
| PDF Generation                       | Ready  | DomPDF integration                             |
| Excel Export                         | Ready  | Maatwebsite Excel                              |
| Health Monitoring                    | Ready  | Spatie Health checks                           |

---

## What Needs to Be Built

### New Domain: Meeting Management

Everything related to meetings, audio, transcription, AI processing, and task distribution is **new functionality** that builds on top of the starter kit.

### Key Architectural Decisions

#### Decision 1: Frontend Approach

**Context:** The starter kit uses Livewire + Blade. The project spec suggests React PWA + NativePHP Mobile.

**Recommendation: Livewire for dashboard, Livewire+Alpine PWA for meeting room, separate NativePHP project for mobile**

- **Meeting Dashboard, Settings, Minutes Review** -> Livewire components (consistent with starter kit)
- **Meeting Room (recording interface)** -> Livewire + Alpine.js with PWA manifest (needs offline support, push-to-talk)
- **Mobile Apps** -> **Separate Laravel project** (`xdata-audition-mobile/`) using NativePHP Mobile v3

**Rationale:**

- Livewire handles 80% of the UI (CRUD, dashboards, settings) with zero JS overhead
- The meeting room recording interface uses Alpine.js for MediaRecorder/WebSocket/IndexedDB with Livewire for server communication
- PWA manifest enables "Add to Home Screen" for app-like mobile experience on the web

**Why NativePHP must be a separate project:**

- NativePHP compiles the **entire Laravel app** into a native bundle (PHP runtime + all source code)
- Bundling the main backend would ship admin controllers, billing logic, tenant management, database patterns, and all 50+ controllers to every user's phone
- This is a security risk (source code extractable from app bundle) and a bloat problem
- The mobile app only needs: push-to-talk recording, S3 upload, WebSocket presence, offline buffering
- A separate thin Laravel project acts as an API client to the main backend

**Mobile Project Structure (nested during dev, separate git repo):**

The mobile app lives inside `xdata-audition/` for development convenience but is a completely separate git repository. The parent `.gitignore` contains `/xdata-audition-mobile` so the main repo ignores it entirely.

```
xdata-audition/                      # Main SaaS backend (git repo #1)
├── .gitignore                       # Contains "/xdata-audition-mobile"
├── app/                             # Full backend + Livewire UI
├── routes/api.php                   # API consumed by mobile app
├── xdata-audition-mobile/                    # NativePHP mobile app (git repo #2)
│   ├── .git/                        # Separate git history
│   ├── app/
│   │   ├── Providers/
│   │   │   └── NativeAppServiceProvider.php
│   │   ├── Livewire/
│   │   │   ├── MeetingRoom.php      # Push-to-talk + RNNoise + upload
│   │   │   ├── PushToTalk.php       # Recording component
│   │   │   ├── JoinMeeting.php      # Join via code/link
│   │   │   └── TaskList.php         # View assigned tasks
│   │   └── Services/
│   │       ├── ApiClient.php        # Sanctum-authenticated calls to main backend
│   │       └── OfflineBuffer.php    # Local audio buffering
│   ├── config/
│   │   ├── nativephp.php            # Bundle ID, permissions (microphone, background)
│   │   └── api.php                  # Main backend URL, auth config
│   ├── resources/views/             # Mobile Blade views (Livewire + Alpine.js)
│   └── composer.json                # Minimal deps (NO admin/billing/tenant code)
└── ...
```

**Why nested with .gitignore (not submodules):**

- Both projects in same IDE workspace for easy development
- No git submodule complexity (avoids merge conflicts, team confusion)
- Truly independent repos: separate CI/CD, separate deployment
- `git status` in parent ignores mobile folder completely
- Can reference main app's `.env` API URL during development

**MVP approach:** Ship web PWA first (zero compilation needed). Build NativePHP mobile app in Phase 2/3 when native features (background recording, biometric auth, push notifications) become necessary.

> **Full mobile development plan:** See [NativePHP Mobile Plan](nativephp-mobile-plan.md) for the complete specification covering auth, database, audio recording, push notifications, offline support, and build/deployment.
> **Reusable NativePHP reference:** See [NativePHP Mobile Skill](../nativephp-mobile-skill.md) for a general-purpose NativePHP Mobile v3 development guide.

#### Decision 1b: Audio Processing (Client-Side)

**Pattern:** Client-side noise cancellation before upload

- **MVP:** RNNoise via WASM (`@jitsi/rnnoise-wasm`, 85 KB model, free, runs in AudioWorklet)
- **Upgrade path:** Picovoice Koala (commercial, better quality) or dtln-rs (open source, newer model)
- Audio is cleaned on the phone before uploading to S3, improving transcription accuracy and reducing bandwidth
- No server-side cost for noise processing

**Speaker identification is not needed for MVP** because push-to-talk provides 100% accurate attribution (each phone = one person). Voice recognition becomes relevant only for Phase 2 room capture mode where multiple speakers are on one device.

#### Decision 2: Audio Storage Architecture

**Pattern:** Temporary S3 with lifecycle policies

```
Audio Upload -> S3 (temporary bucket, lifecycle: 30-90 days based on plan)
                -> Lambda trigger -> AWS Transcribe
                -> Transcript stored in PostgreSQL
                -> Audio deleted per retention policy
```

#### Decision 3: AI Provider Abstraction

**Pattern:** Interface-based provider with Bedrock as primary

```php
interface AIProvider {
    public function cleanupTranscript(string $rawText): string;
    public function extractActionItems(string $transcript, array $participants): array;
    public function generateMinutes(string $transcript, array $metadata): string;
}
```

- Primary: AWS Bedrock (Claude Haiku for cleanup/extraction, Sonnet for minutes)
- Leverages existing LLM token management system in starter kit
- Tenant BYOK support through existing `tenant_llm_configs`

#### Decision 4: Real-time Communication

**Pattern:** Laravel Reverb (WebSocket) for meeting room events

- Participant join/leave notifications
- Push-to-talk state broadcasting
- Secretary marker placement
- Meeting status changes
- Floor control signals (Phase 2)

#### Decision 5: Transcription Job Tracking

**Pattern:** Laravel Queue + AWS EventBridge

```
Meeting End -> ProcessMeetingJob (Laravel Queue)
    -> Waits for all transcription jobs to complete
    -> CleanupTranscriptJob -> ExtractActionItemsJob -> GenerateMeetingMinutesJob -> DistributeTasksJob
```

AWS Transcribe completion notifications come via EventBridge -> SQS -> Laravel job.

#### Decision 6: Meeting Feature Gating

**Pattern:** Leverage existing feature flags system

New features to register:

- `meetings` (metered) - number of meetings per billing period
- `meeting_duration` (metered) - max minutes per meeting
- `meeting_participants` (metered) - max participants per meeting
- `transcription` (boolean) - access to transcription
- `ai_minutes` (boolean) - AI-generated minutes
- `live_captions` (boolean) - real-time captions (Phase 3)
- `floor_control` (boolean) - floor control mode (Phase 2)
- `secretary_markers` (boolean) - live markers (Phase 2)
- `custom_vocabulary` (boolean) - custom vocabulary (Phase 2)
- `calendar_sync` (boolean) - calendar integrations (Phase 3)
- `pm_integrations` (boolean) - project management integrations (Phase 3)

---

## System Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                        CLIENT LAYER                              │
├──────────────┬──────────────────┬────────────────────────────────┤
│ Livewire     │ Meeting Room     │ NativePHP Mobile               │
│ Dashboard    │ PWA              │ (SEPARATE PROJECT)             │
│ (Blade+      │ (Livewire+       │                                │
│  Alpine.js)  │  Alpine.js,      │ xdata-audition-mobile/                  │
│              │  Offline-first,  │ - Thin API client              │
│ Same Laravel │  Push-to-talk,   │ - Push-to-talk recording       │
│ app          │  RNNoise WASM)   │ - Background audio             │
│              │                  │ - Calls main backend API       │
│              │  Same Laravel    │ - Phase 2/3 (not MVP)          │
│              │  app + PWA       │                                │
│              │  manifest        │                                │
├──────────────┴──────────────────┴────────────────────────────────┤
│                              │                                   │
│                              ▼                                   │
├─────────────────────────────────────────────────────────────────┤
│                    API LAYER (Laravel 12 - xdata-audition)       │
├─────────────────────────────────────────────────────────────────┤
│ Sanctum Auth │ Reverb WebSocket │ Horizon Queue Manager          │
│ API Routes   │ Broadcasting     │ Job Processing                 │
│ Middleware   │ Presence Channels│ Retry Logic                    │
└──────┬───────┴────────┬─────────┴───────────┬───────────────────┘
       │                │                     │
       ▼                ▼                     ▼
┌─────────────────────────────────────────────────────────────────┐
│                    AWS SERVICES                                  │
├──────────┬──────────┬──────────┬──────────┬─────────────────────┤
│ S3       │ Lambda   │Transcribe│ Bedrock  │ SQS/EventBridge/SES │
│ (Audio   │ (Audio   │ (Speech  │ (Claude  │ (Events, Email)     │
│  Storage)│  Process)│  to Text)│  AI)     │                     │
└──────────┴──────────┴──────────┴──────────┴─────────────────────┘
       │
       ▼
┌─────────────────────────────────────────────────────────────────┐
│                    DATA LAYER                                    │
├──────────────────────┬──────────────────────────────────────────┤
│ PostgreSQL 16        │ Redis 7                                  │
│ (Landlord + Tenant   │ (Cache, Queues, WebSocket,              │
│  databases)          │  Session, Broadcasting)                  │
└──────────────────────┴──────────────────────────────────────────┘
```

---

## Package Plan Mapping

| Feature            | Starter ($49) | Professional ($199) | Business ($499) | Enterprise ($2000+) |
| ------------------ | ------------- | ------------------- | --------------- | ------------------- |
| Meetings/month     | 5             | 25                  | 100             | 200+                |
| Max participants   | 10            | 50                  | Unlimited       | Unlimited           |
| Max duration       | 2 hrs         | 4 hrs               | Unlimited       | Unlimited           |
| Transcription      | Yes           | Yes                 | Yes             | Yes                 |
| AI Minutes         | Yes           | Yes                 | Yes             | Yes                 |
| Audio retention    | None          | 30 days             | 90 days         | 1 year              |
| Secretary markers  | No            | No                  | Yes             | Yes                 |
| Floor control      | No            | No                  | Yes             | Yes                 |
| Custom vocabulary  | No            | No                  | Yes             | Yes                 |
| Live captions      | No            | No                  | No              | Yes                 |
| Calendar sync      | No            | Yes                 | Yes             | Yes                 |
| PM integrations    | No            | No                  | Yes             | Yes                 |
| White-label        | No            | No                  | No              | Yes                 |
| Compliance (HIPAA) | No            | No                  | No              | Yes                 |

---

## Directory Structure (New Files in xdata-audition)

```
app/
├── Http/Controllers/
│   ├── Meeting/
│   │   ├── MeetingController.php
│   │   ├── ParticipantController.php
│   │   ├── AudioController.php
│   │   ├── TranscriptController.php
│   │   ├── ActionItemController.php
│   │   └── MeetingMinutesController.php
│   └── Api/Meeting/
│       ├── MeetingApiController.php
│       ├── AudioUploadController.php
│       └── RealtimeController.php
├── Models/
│   ├── Meeting.php
│   ├── MeetingParticipant.php
│   ├── AudioSegment.php
│   ├── TranscriptSegment.php
│   ├── TranscriptionJob.php
│   ├── SecretaryMarker.php
│   ├── ActionItem.php
│   ├── MeetingMinutes.php
│   ├── TaskReminder.php
│   └── MeetingNotification.php
├── Jobs/
│   ├── Meeting/
│   │   ├── ProcessMeetingJob.php
│   │   ├── CleanupTranscriptJob.php
│   │   ├── ExtractActionItemsJob.php
│   │   ├── GenerateMeetingMinutesJob.php
│   │   └── DistributeTasksJob.php
│   └── Audio/
│       └── ProcessAudioSegmentJob.php
├── Services/
│   ├── Meeting/
│   │   ├── MeetingService.php
│   │   ├── MeetingLifecycleService.php
│   │   └── ParticipantService.php
│   ├── Transcription/
│   │   ├── TranscriptionService.php
│   │   └── TranscribeClientService.php
│   └── AI/
│       ├── AIProvider.php (interface)
│       ├── BedrockProvider.php
│       └── PromptTemplates.php
├── Events/
│   ├── MeetingStarted.php
│   ├── MeetingEnded.php
│   ├── ParticipantJoined.php
│   ├── AudioSegmentUploaded.php
│   ├── TranscriptionCompleted.php
│   └── MinutesGenerated.php
└── Livewire/
    └── Meeting/
        ├── MeetingDashboard.php
        ├── MeetingDetail.php
        ├── MeetingRoom.php          # Push-to-talk recording (PWA page)
        ├── TranscriptViewer.php
        ├── MinutesEditor.php
        ├── ActionItemList.php
        └── MeetingSettings.php
```

## Separate Mobile Project (Phase 2/3)

Nested inside `xdata-audition/` during development, ignored by parent `.gitignore`. Uses **Livewire + Alpine.js** (same stack as main app - not React) because the audio logic is vanilla JavaScript regardless, and Alpine handles the 3-4 mobile screens without adding a build toolchain.

```
xdata-audition/xdata-audition-mobile/            # Nested, but separate git repo
├── .git/                                # Independent git history
├── app/
│   ├── Providers/
│   │   └── NativeAppServiceProvider.php # Window config, permissions (microphone, background)
│   ├── Livewire/
│   │   ├── MeetingRoom.php             # Push-to-talk + RNNoise + S3 upload
│   │   ├── PushToTalk.php              # Recording component (Alpine.js audio logic)
│   │   ├── JoinMeeting.php             # Join via code/link
│   │   └── TaskList.php                # View assigned tasks
│   └── Services/
│       ├── ApiClient.php               # Sanctum-authenticated calls to main backend
│       ├── OfflineBuffer.php           # Local audio buffering
│       └── AudioService.php            # Native microphone access via NativePHP
├── config/
│   ├── nativephp.php                   # Bundle ID, version, platform settings
│   └── api.php                         # Main backend URL, auth config
├── resources/views/                    # Mobile Blade views (Livewire + Alpine.js)
└── composer.json                       # Minimal dependencies (no admin, billing, etc.)
```
