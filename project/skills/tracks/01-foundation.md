# Track 01: Foundation & Core Infrastructure

**Phase:** MVP (Weeks 1-2)
**Priority:** Critical - blocks all other tracks
**Status:** Not Started

---

## Objective

Set up the database schema, Eloquent models, AWS infrastructure, and queue/WebSocket services that every other track depends on.

---

## Tasks

### T01.1 - Database Migrations

**Effort:** 1 day
**Dependencies:** None

Create all meeting-domain migrations in the correct order:

- [ ] `create_meetings_table` - Core meeting entity with status, recording mode, join code
- [ ] `create_meeting_participants_table` - Participant roles, invitation tokens, voice enrollment
- [ ] `create_audio_segments_table` - S3 references, chunk metadata, processing status
- [ ] `create_transcription_jobs_table` - AWS Transcribe job tracking
- [ ] `create_transcript_segments_table` - Speaker-attributed text with confidence scores
- [ ] `create_secretary_markers_table` - Live marker placement (Phase 2, but schema now)
- [ ] `create_action_items_table` - Extracted tasks with priority, status, external links
- [ ] `create_meeting_minutes_table` - Versioned minutes with approval workflow
- [ ] `create_task_reminders_table` - Scheduled reminder delivery
- [ ] `create_meeting_notifications_table` - Meeting notification log

**Acceptance Criteria:**
- All migrations run cleanly on fresh database
- Foreign keys and indexes match database spec
- Rollback (down) works for each migration

---

### T01.2 - Eloquent Models & Relationships

**Effort:** 1-2 days
**Dependencies:** T01.1

Create models with factories and relationships:

- [ ] `Meeting` model with tenant scope, status casting, relationships to participants/segments/actions
- [ ] `MeetingParticipant` model with invitation token generation, role enum
- [ ] `AudioSegment` model with S3 URL accessor, status transitions
- [ ] `TranscriptionJob` model with AWS job name generation
- [ ] `TranscriptSegment` model with full-text search scope, ordering
- [ ] `SecretaryMarker` model with marker type enum
- [ ] `ActionItem` model with priority/status enums, overdue scope
- [ ] `MeetingMinutes` model with version incrementing, PDF accessor
- [ ] `TaskReminder` model with pending scope, scheduling logic
- [ ] `MeetingNotification` model with unread scope

**For each model:**
- [ ] Define `$fillable`, `$casts`, relationships, and scopes
- [ ] Create factory with realistic test data
- [ ] Follow existing model conventions (check `app/Models/Tenant.php` for patterns)

**Acceptance Criteria:**
- All relationships load correctly (eager loading tested)
- Factories produce valid records
- Model casts work (enums, dates, JSON)

---

### T01.3 - Feature Registration

**Effort:** 0.5 days
**Dependencies:** T01.1

Register meeting features in the existing feature system:

- [ ] Create seeder for meeting-domain features (`meetings`, `meeting_duration`, `meeting_participants`, `transcription`, `ai_minutes`)
- [ ] Map features to existing packages (Starter, Professional, Business, Enterprise)
- [ ] Map features to permissions via `feature_permissions` table
- [ ] Verify feature gates work with existing middleware

**Acceptance Criteria:**
- `EntitlementService::isEntitled($tenant, 'meetings')` returns correctly
- `EnsureFeatureEnabled` middleware blocks access for disabled features
- Usage limits enforced via `EnsureFeatureLimit` middleware

---

### T01.4 - AWS S3 Configuration

**Effort:** 0.5 days
**Dependencies:** None

- [ ] Create S3 bucket for audio storage (naming: `{app}-audio-{env}`)
- [ ] Configure lifecycle policy (30-day default expiration)
- [ ] Set up CORS for direct browser uploads
- [ ] Create IAM role for pre-signed URL generation
- [ ] Add S3 disk configuration to `config/filesystems.php`
- [ ] Add environment variables to `.env.example`

**Acceptance Criteria:**
- Pre-signed upload URL generates successfully
- Direct browser upload to S3 works
- Lifecycle rules delete files after retention period

---

### T01.5 - Queue & WebSocket Setup

**Effort:** 0.5 days
**Dependencies:** None

- [ ] Configure dedicated queue for meeting jobs (`meeting-processing`)
- [ ] Set up Laravel Reverb WebSocket server config
- [ ] Create broadcasting channels for meetings (`meeting.{id}`)
- [ ] Create presence channel for participant tracking
- [ ] Configure Horizon queue workers for meeting queues

**Acceptance Criteria:**
- Jobs dispatch to correct queue
- WebSocket connections establish on meeting channels
- Presence channel shows online participants

---

### T01.6 - Route Structure

**Effort:** 0.5 days
**Dependencies:** T01.2

- [ ] Define web routes for meeting dashboard (Livewire pages)
- [ ] Define API routes for meeting room (PWA endpoints)
- [ ] Apply existing tenant middleware to all meeting routes
- [ ] Apply feature gate middleware (`EnsureFeatureEnabled:meetings`)
- [ ] Apply usage limit middleware (`EnsureFeatureLimit:meetings`)

**Acceptance Criteria:**
- Routes load without errors
- Middleware stack applies correctly
- Unauthenticated requests rejected
- Feature-gated routes return 403 for disabled features

---

## Testing Requirements

- [ ] Migration test: fresh migrate + rollback + migrate
- [ ] Model relationship tests for all 10 models
- [ ] Factory tests ensuring all factories produce valid records
- [ ] Feature gate integration test
- [ ] Route access control tests (auth, tenant, feature gates)
