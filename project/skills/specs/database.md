# Database Specification

## Overview

New tables to be added to the **tenant database** (per-tenant isolation). These tables handle the meeting domain and build on top of the existing landlord schema which handles tenancy, billing, and usage metering.

> The existing landlord tables (tenants, subscriptions, features, usage_events, etc.) remain unchanged and are leveraged for billing, feature gating, and usage tracking.

---

## New Tables

### 1. `meetings`

Core meeting entity. One meeting per session.

```sql
CREATE TABLE meetings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    organizer_id BIGINT NOT NULL REFERENCES users(id),
    title VARCHAR(255) NOT NULL,
    description TEXT,
    scheduled_at TIMESTAMP,
    started_at TIMESTAMP,
    ended_at TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
        -- draft, scheduled, active, processing, completed, cancelled
    recording_mode VARCHAR(20) NOT NULL DEFAULT 'push_to_talk',
        -- push_to_talk, floor_control, open_mic, auto_vad
    join_code VARCHAR(8) UNIQUE,
    meeting_url VARCHAR(500),
    max_duration_minutes INT DEFAULT 120,
    language_code VARCHAR(10) DEFAULT 'en-US',
    settings JSONB DEFAULT '{}',
    processing_started_at TIMESTAMP,
    processing_completed_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP
);

CREATE INDEX idx_meetings_tenant ON meetings(tenant_id);
CREATE INDEX idx_meetings_organizer ON meetings(organizer_id);
CREATE INDEX idx_meetings_status ON meetings(status);
CREATE INDEX idx_meetings_scheduled ON meetings(scheduled_at);
CREATE INDEX idx_meetings_join_code ON meetings(join_code);
```

### 2. `meeting_participants`

Who is in the meeting and their role.

```sql
CREATE TABLE meeting_participants (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    user_id BIGINT REFERENCES users(id),
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'participant',
        -- organizer, secretary, participant, observer
    status VARCHAR(20) NOT NULL DEFAULT 'invited',
        -- invited, joined, left, declined
    joined_at TIMESTAMP,
    left_at TIMESTAMP,
    voice_enrollment_url VARCHAR(500),
    invitation_token VARCHAR(64) UNIQUE,
    invitation_sent_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_participants_meeting ON meeting_participants(meeting_id);
CREATE INDEX idx_participants_user ON meeting_participants(user_id);
CREATE INDEX idx_participants_token ON meeting_participants(invitation_token);
CREATE UNIQUE INDEX idx_participants_unique ON meeting_participants(meeting_id, email);
```

### 3. `audio_segments`

Temporary audio chunk metadata. Actual audio lives in S3.

```sql
CREATE TABLE audio_segments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    participant_id UUID NOT NULL REFERENCES meeting_participants(id),
    s3_key VARCHAR(500) NOT NULL,
    s3_bucket VARCHAR(100) NOT NULL,
    chunk_index INT NOT NULL,
    duration_seconds DECIMAL(8,2),
    file_size_bytes BIGINT,
    format VARCHAR(20) DEFAULT 'webm',
    sample_rate INT DEFAULT 16000,
    recorded_at TIMESTAMP NOT NULL,
    uploaded_at TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'uploading',
        -- uploading, uploaded, processing, transcribed, failed
    error_message TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_audio_meeting ON audio_segments(meeting_id);
CREATE INDEX idx_audio_participant ON audio_segments(participant_id);
CREATE INDEX idx_audio_status ON audio_segments(status);
CREATE INDEX idx_audio_order ON audio_segments(meeting_id, recorded_at);
```

### 4. `transcription_jobs`

Tracks AWS Transcribe job status.

```sql
CREATE TABLE transcription_jobs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    audio_segment_id UUID REFERENCES audio_segments(id),
    aws_job_name VARCHAR(255) NOT NULL UNIQUE,
    aws_job_status VARCHAR(20) NOT NULL DEFAULT 'queued',
        -- queued, in_progress, completed, failed
    language_code VARCHAR(10) DEFAULT 'en-US',
    media_format VARCHAR(20) DEFAULT 'webm',
    output_s3_key VARCHAR(500),
    confidence_score DECIMAL(5,4),
    duration_seconds DECIMAL(8,2),
    cost_estimate DECIMAL(10,6),
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    error_message TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_transcription_meeting ON transcription_jobs(meeting_id);
CREATE INDEX idx_transcription_status ON transcription_jobs(aws_job_status);
CREATE INDEX idx_transcription_aws_job ON transcription_jobs(aws_job_name);
```

### 5. `transcript_segments`

Transcribed text with speaker attribution.

```sql
CREATE TABLE transcript_segments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    participant_id UUID REFERENCES meeting_participants(id),
    transcription_job_id UUID REFERENCES transcription_jobs(id),
    segment_index INT NOT NULL,
    raw_text TEXT NOT NULL,
    cleaned_text TEXT,
    start_time DECIMAL(10,3),
    end_time DECIMAL(10,3),
    confidence DECIMAL(5,4),
    speaker_label VARCHAR(50),
    is_attributed BOOLEAN DEFAULT true,
        -- false for room-capture segments needing manual assignment
    attribution_source VARCHAR(20) DEFAULT 'session',
        -- session (push-to-talk), diarization (room capture), manual
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_transcript_meeting ON transcript_segments(meeting_id);
CREATE INDEX idx_transcript_participant ON transcript_segments(participant_id);
CREATE INDEX idx_transcript_order ON transcript_segments(meeting_id, segment_index);
CREATE INDEX idx_transcript_unattributed ON transcript_segments(meeting_id, is_attributed) WHERE NOT is_attributed;
```

### 6. `secretary_markers` (Phase 2)

Live markers placed by secretary during meeting.

```sql
CREATE TABLE secretary_markers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    participant_id UUID NOT NULL REFERENCES meeting_participants(id),
        -- the secretary who placed the marker
    marker_type VARCHAR(20) NOT NULL,
        -- decision, action, note, risk, parking_lot, important
    label VARCHAR(255),
    description TEXT,
    placed_at TIMESTAMP NOT NULL,
    transcript_segment_id UUID REFERENCES transcript_segments(id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_markers_meeting ON secretary_markers(meeting_id);
CREATE INDEX idx_markers_type ON secretary_markers(meeting_id, marker_type);
```

### 7. `action_items`

Tasks extracted from meetings.

```sql
CREATE TABLE action_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    assigned_to UUID REFERENCES meeting_participants(id),
    assigned_to_user_id BIGINT REFERENCES users(id),
    title VARCHAR(500) NOT NULL,
    description TEXT,
    source_text TEXT,
        -- exact quote from transcript
    source_segment_id UUID REFERENCES transcript_segments(id),
    priority VARCHAR(10) DEFAULT 'medium',
        -- low, medium, high, urgent
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
        -- pending, in_progress, completed, cancelled, deferred
    due_date DATE,
    completed_at TIMESTAMP,
    external_id VARCHAR(255),
        -- linked Jira/Asana/Linear ID (Phase 3)
    external_provider VARCHAR(50),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP
);

CREATE INDEX idx_actions_meeting ON action_items(meeting_id);
CREATE INDEX idx_actions_assigned ON action_items(assigned_to_user_id);
CREATE INDEX idx_actions_status ON action_items(status);
CREATE INDEX idx_actions_due ON action_items(due_date) WHERE status NOT IN ('completed', 'cancelled');
```

### 8. `meeting_minutes`

Generated meeting minutes documents.

```sql
CREATE TABLE meeting_minutes (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    version INT NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
        -- draft, review, approved, distributed
    content_markdown TEXT NOT NULL,
    content_html TEXT,
    pdf_s3_key VARCHAR(500),
    template VARCHAR(50) DEFAULT 'standard',
        -- standard, formal, technical, casual
    generated_by VARCHAR(20) DEFAULT 'ai',
        -- ai, manual
    approved_by BIGINT REFERENCES users(id),
    approved_at TIMESTAMP,
    distributed_at TIMESTAMP,
    ai_model VARCHAR(100),
    ai_tokens_used INT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_minutes_meeting ON meeting_minutes(meeting_id);
CREATE INDEX idx_minutes_status ON meeting_minutes(status);
CREATE UNIQUE INDEX idx_minutes_version ON meeting_minutes(meeting_id, version);
```

### 9. `task_reminders`

Scheduled reminders for action items.

```sql
CREATE TABLE task_reminders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    action_item_id UUID NOT NULL REFERENCES action_items(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id),
    remind_at TIMESTAMP NOT NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'email',
        -- email, in_app, slack, teams
    sent_at TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
        -- pending, sent, failed, cancelled
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_reminders_pending ON task_reminders(remind_at) WHERE status = 'pending';
CREATE INDEX idx_reminders_action ON task_reminders(action_item_id);
```

### 10. `meeting_notifications`

Notification log for meeting-related communications.

```sql
CREATE TABLE meeting_notifications (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id),
    type VARCHAR(50) NOT NULL,
        -- invitation, meeting_started, meeting_ended, minutes_ready,
        -- task_assigned, task_reminder, task_overdue
    channel VARCHAR(20) NOT NULL DEFAULT 'email',
    payload JSONB DEFAULT '{}',
    sent_at TIMESTAMP,
    read_at TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_notifications_meeting ON meeting_notifications(meeting_id);
CREATE INDEX idx_notifications_user ON meeting_notifications(user_id, read_at);
```

---

## Database Views

### `v_meeting_summary`

Quick overview of meeting status with counts.

```sql
CREATE VIEW v_meeting_summary AS
SELECT
    m.id,
    m.tenant_id,
    m.title,
    m.status,
    m.started_at,
    m.ended_at,
    COUNT(DISTINCT mp.id) AS participant_count,
    COUNT(DISTINCT ts.id) AS transcript_segment_count,
    COUNT(DISTINCT ai.id) AS action_item_count,
    COUNT(DISTINCT ai.id) FILTER (WHERE ai.status = 'completed') AS completed_actions,
    EXTRACT(EPOCH FROM (m.ended_at - m.started_at)) / 60 AS duration_minutes
FROM meetings m
LEFT JOIN meeting_participants mp ON mp.meeting_id = m.id
LEFT JOIN transcript_segments ts ON ts.meeting_id = m.id
LEFT JOIN action_items ai ON ai.meeting_id = m.id
WHERE m.deleted_at IS NULL
GROUP BY m.id;
```

### `v_pending_actions`

All open action items with assignee info.

```sql
CREATE VIEW v_pending_actions AS
SELECT
    ai.*,
    m.title AS meeting_title,
    m.tenant_id,
    mp.name AS assignee_name,
    mp.email AS assignee_email,
    CASE
        WHEN ai.due_date < CURRENT_DATE THEN 'overdue'
        WHEN ai.due_date = CURRENT_DATE THEN 'due_today'
        WHEN ai.due_date <= CURRENT_DATE + INTERVAL '3 days' THEN 'due_soon'
        ELSE 'on_track'
    END AS urgency
FROM action_items ai
JOIN meetings m ON m.id = ai.meeting_id
LEFT JOIN meeting_participants mp ON mp.id = ai.assigned_to
WHERE ai.status IN ('pending', 'in_progress')
AND ai.deleted_at IS NULL;
```

---

## Relationship Map

```
Tenant (existing)
  └── Meeting
        ├── MeetingParticipant (many)
        │     ├── AudioSegment (many)
        │     └── SecretaryMarker (many, if role=secretary)
        ├── AudioSegment (many, via participant)
        ├── TranscriptionJob (many)
        ├── TranscriptSegment (many)
        │     └── linked to MeetingParticipant (speaker)
        ├── ActionItem (many)
        │     ├── assigned to MeetingParticipant
        │     └── TaskReminder (many)
        ├── MeetingMinutes (many versions)
        └── MeetingNotification (many)
```

---

## Migration Order

Migrations should be created in this order to respect foreign key dependencies:

1. `create_meetings_table`
2. `create_meeting_participants_table`
3. `create_audio_segments_table`
4. `create_transcription_jobs_table`
5. `create_transcript_segments_table`
6. `create_secretary_markers_table`
7. `create_action_items_table`
8. `create_meeting_minutes_table`
9. `create_task_reminders_table`
10. `create_meeting_notifications_table`
11. `create_meeting_views` (v_meeting_summary, v_pending_actions)

---

## Integration with Existing Schema

### Usage Metering

Meeting events feed into the existing `usage_events` table:

| Metric | Trigger | Dimensions |
|---|---|---|
| `meeting.created` | Meeting created | `{plan, recording_mode}` |
| `meeting.minutes` | Meeting ended | `{duration_minutes}` |
| `meeting.participants` | Participant joined | `{count}` |
| `transcription.minutes` | Transcription complete | `{audio_minutes}` |
| `ai.tokens` | AI processing | `{model, job_type, tokens}` |

### Feature Gating

New features to register in existing `features` table:

| Feature Key | Type | Description |
|---|---|---|
| `meetings` | metered | Number of meetings per period |
| `meeting_duration` | metered | Max meeting duration (minutes) |
| `meeting_participants` | metered | Max participants per meeting |
| `transcription` | boolean | Access to transcription |
| `ai_minutes` | boolean | AI-generated meeting minutes |
| `secretary_markers` | boolean | Live secretary markers |
| `floor_control` | boolean | Floor control recording mode |
| `custom_vocabulary` | boolean | Custom vocabulary management |
| `live_captions` | boolean | Real-time live captions |
| `audio_retention_days` | metered | Audio retention period |
