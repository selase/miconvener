# Meeting Minutes & Action Tracker SaaS
## Part 2: Database Schema & Data Model

**Version:** 2.0  
**Last Updated:** February 2025

---

## Complete Database Schema

### Schema Design Principles

1. **Multi-Tenant Isolation:** Every meeting-related table has `tenant_id` for strict data separation
2. **Audit Trail:** Track who created, modified, and accessed sensitive data
3. **Soft Deletes:** Enable data recovery and comply with legal hold requirements
4. **Optimistic Locking:** Use version columns for conflict resolution in concurrent edits
5. **Performance:** Strategic indexes on frequently queried columns
6. **Flexibility:** JSONB columns for extensible metadata

---

### Core Meeting Tables

```sql
-- ============================================================================
-- MEETINGS TABLE
-- ============================================================================
CREATE TABLE meetings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    
    -- Meeting Details
    title VARCHAR(255) NOT NULL,
    description TEXT,
    agenda JSONB,  
    -- Example: [
    --   {"section": "Opening", "duration": 10, "presenter": "John"},
    --   {"section": "Q4 Results", "duration": 30, "presenter": "Sarah"}
    -- ]
    
    -- Scheduling
    scheduled_at TIMESTAMP NOT NULL,
    started_at TIMESTAMP,
    ended_at TIMESTAMP,
    duration_minutes INT GENERATED ALWAYS AS (
        EXTRACT(EPOCH FROM (ended_at - started_at)) / 60
    ) STORED,
    
    -- Status & Configuration
    status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
        -- Values: 'scheduled' | 'in_progress' | 'completed' | 'cancelled'
    recording_mode VARCHAR(20) DEFAULT 'push_to_talk',
        -- Values: 'push_to_talk' | 'floor_control' | 'open_mics' | 'auto_vad'
    
    -- Feature Toggles (may override tenant defaults)
    enable_floor_control BOOLEAN DEFAULT false,
    floor_auto_release_seconds INT DEFAULT 300,  -- 5 minutes
    enable_room_capture BOOLEAN DEFAULT false,   -- Secretary fallback recording
    enable_live_captions BOOLEAN DEFAULT false,  -- Real-time streaming transcription
    
    -- Retention & Storage
    audio_retention_days INT DEFAULT 30,
    storage_option VARCHAR(20) DEFAULT 'text_only',
        -- Values: 'audio_and_text' | 'text_only'
    
    -- Ownership
    organizer_id UUID NOT NULL REFERENCES users(id),
    secretary_id UUID REFERENCES users(id),
    
    -- Processing Status
    transcription_status VARCHAR(20) DEFAULT 'pending',
        -- Values: 'pending' | 'in_progress' | 'completed' | 'failed'
    minutes_status VARCHAR(20) DEFAULT 'pending',
        -- Values: 'pending' | 'draft' | 'approved' | 'published'
    
    -- Metadata
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    deleted_at TIMESTAMP,
    
    -- Constraints
    CONSTRAINT valid_status CHECK (status IN (
        'scheduled', 'in_progress', 'completed', 'cancelled'
    )),
    CONSTRAINT valid_recording_mode CHECK (recording_mode IN (
        'push_to_talk', 'floor_control', 'open_mics', 'auto_vad'
    ))
);

-- Indexes
CREATE INDEX idx_meetings_tenant_status ON meetings(tenant_id, status);
CREATE INDEX idx_meetings_scheduled ON meetings(scheduled_at) WHERE status = 'scheduled';
CREATE INDEX idx_meetings_organizer ON meetings(organizer_id);
CREATE INDEX idx_meetings_secretary ON meetings(secretary_id) WHERE secretary_id IS NOT NULL;
CREATE INDEX idx_meetings_transcription_pending ON meetings(transcription_status) 
    WHERE transcription_status = 'pending';

-- ============================================================================
-- MEETING PARTICIPANTS
-- ============================================================================
CREATE TABLE meeting_participants (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    user_id UUID REFERENCES users(id),  -- NULL if external guest
    
    -- Participant Details
    email VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'participant',
        -- Values: 'organizer' | 'secretary' | 'participant' | 'observer'
    
    -- Join Management
    join_token VARCHAR(64) UNIQUE NOT NULL,  -- Secure random token
    invited_at TIMESTAMP DEFAULT NOW(),
    joined_at TIMESTAMP,
    left_at TIMESTAMP,
    
    -- Voice & Device Identity
    voice_enrollment_s3_key VARCHAR(500),  -- For optional voice matching
    device_fingerprint VARCHAR(255),       -- Unique device ID
    device_type VARCHAR(50),               -- 'ios' | 'android' | 'web'
    device_info JSONB,                     -- Browser/OS details
    
    -- Participation Tracking
    speaking_duration_seconds INT DEFAULT 0,
    chunks_uploaded INT DEFAULT 0,
    last_spoke_at TIMESTAMP,
    
    -- Status
    status VARCHAR(20) DEFAULT 'invited',
        -- Values: 'invited' | 'joined' | 'left' | 'ejected'
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    -- Constraints
    UNIQUE(meeting_id, email),
    CONSTRAINT valid_role CHECK (role IN (
        'organizer', 'secretary', 'participant', 'observer'
    ))
);

-- Indexes
CREATE INDEX idx_participants_meeting ON meeting_participants(meeting_id);
CREATE INDEX idx_participants_user ON meeting_participants(user_id) WHERE user_id IS NOT NULL;
CREATE INDEX idx_participants_token ON meeting_participants(join_token);
CREATE INDEX idx_participants_status ON meeting_participants(meeting_id, status);

-- ============================================================================
-- AUDIO SEGMENTS (Temporary Storage)
-- ============================================================================
CREATE TABLE audio_segments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    participant_id UUID REFERENCES meeting_participants(id) ON DELETE CASCADE,
    
    -- Storage Details
    s3_key VARCHAR(500) NOT NULL UNIQUE,
    s3_bucket VARCHAR(255) NOT NULL,
    file_size_bytes BIGINT,
    duration_seconds DECIMAL(10, 2),
    
    -- Sequencing
    sequence_number INT NOT NULL,
    recorded_at TIMESTAMP NOT NULL,
    uploaded_at TIMESTAMP DEFAULT NOW(),
    
    -- Processing Status
    transcribed BOOLEAN DEFAULT FALSE,
    transcription_job_name VARCHAR(255),
    transcription_started_at TIMESTAMP,
    transcription_completed_at TIMESTAMP,
    transcription_failed_reason TEXT,
    
    -- Lifecycle Management
    scheduled_deletion_at TIMESTAMP,  -- Auto-calculated from retention policy
    deleted_at TIMESTAMP,
    
    -- Audio Metadata
    codec VARCHAR(20) DEFAULT 'opus',  -- 'opus' | 'webm' | 'mp3'
    sample_rate INT DEFAULT 16000,     -- 16kHz for speech
    channels INT DEFAULT 1,            -- Mono
    bitrate INT DEFAULT 16000,         -- 16kbps
    
    created_at TIMESTAMP DEFAULT NOW(),
    
    CONSTRAINT unique_meeting_participant_sequence 
        UNIQUE(meeting_id, participant_id, sequence_number)
);

-- Indexes
CREATE INDEX idx_audio_meeting ON audio_segments(meeting_id);
CREATE INDEX idx_audio_participant ON audio_segments(participant_id);
CREATE INDEX idx_audio_transcribed ON audio_segments(meeting_id, transcribed);
CREATE INDEX idx_audio_scheduled_deletion ON audio_segments(scheduled_deletion_at) 
    WHERE deleted_at IS NULL AND scheduled_deletion_at IS NOT NULL;

-- ============================================================================
-- TRANSCRIPT SEGMENTS
-- ============================================================================
CREATE TABLE transcript_segments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    participant_id UUID REFERENCES meeting_participants(id),  -- NULL if unattributed
    audio_segment_id UUID REFERENCES audio_segments(id) ON DELETE SET NULL,
    
    -- Content
    speaker_name VARCHAR(255),  -- Denormalized for performance
    raw_text TEXT NOT NULL,     -- Direct from AWS Transcribe
    cleaned_text TEXT,          -- After AI cleanup
    
    -- Timing
    segment_number INT NOT NULL,  -- Chronological order in meeting
    started_at TIMESTAMP NOT NULL,
    ended_at TIMESTAMP NOT NULL,
    duration_seconds DECIMAL(10, 2) GENERATED ALWAYS AS (
        EXTRACT(EPOCH FROM (ended_at - started_at))
    ) STORED,
    
    -- Confidence & Attribution
    confidence DECIMAL(3, 2),  -- 0.00 to 1.00 from AWS Transcribe
    speaker_identification_method JSONB,
        -- Example: {
        --   "primary": "push_to_talk",
        --   "fallback": "diarization",
        --   "diarization_confidence": 0.87,
        --   "voice_match_confidence": 0.92
        -- }
    
    -- Secretary Categorization (Live Markers)
    marker_type VARCHAR(20),  
        -- Values: 'decision' | 'action' | 'note' | 'question' | 'risk' | 'parking_lot'
    is_action_item BOOLEAN DEFAULT FALSE,
    is_decision BOOLEAN DEFAULT FALSE,
    is_key_point BOOLEAN DEFAULT FALSE,
    
    -- Attribution Review (for unattributed segments from room capture)
    attribution_status VARCHAR(20) DEFAULT 'confirmed',
        -- Values: 'confirmed' | 'unattributed' | 'disputed' | 'reassigned'
    assigned_by UUID REFERENCES users(id),  -- Secretary who manually assigned
    assigned_at TIMESTAMP,
    attribution_notes TEXT,
    
    -- Processing Flags
    processed BOOLEAN DEFAULT FALSE,
    included_in_minutes BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    CONSTRAINT unique_meeting_segment UNIQUE(meeting_id, segment_number)
);

-- Indexes
CREATE INDEX idx_transcript_meeting ON transcript_segments(meeting_id, segment_number);
CREATE INDEX idx_transcript_participant ON transcript_segments(participant_id) 
    WHERE participant_id IS NOT NULL;
CREATE INDEX idx_transcript_unattributed ON transcript_segments(meeting_id, attribution_status) 
    WHERE attribution_status = 'unattributed';
CREATE INDEX idx_transcript_markers ON transcript_segments(meeting_id, marker_type) 
    WHERE marker_type IS NOT NULL;
CREATE INDEX idx_transcript_action_items ON transcript_segments(meeting_id) 
    WHERE is_action_item = TRUE;

-- ============================================================================
-- ACTION ITEMS (Tasks)
-- ============================================================================
CREATE TABLE action_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    tenant_id UUID NOT NULL REFERENCES tenants(id),  -- Denormalized for queries
    
    -- Task Details
    title VARCHAR(500) NOT NULL,
    description TEXT,
    
    -- Assignment
    assigned_to UUID REFERENCES users(id),  -- NULL if external/unassigned
    assigned_to_email VARCHAR(255),         -- For external assignments
    assigned_to_name VARCHAR(255),          -- Denormalized
    assigned_by UUID REFERENCES users(id),  -- Usually secretary
    assigned_at TIMESTAMP DEFAULT NOW(),
    
    -- Timing
    due_date DATE,
    due_time TIME,  -- Optional specific time
    due_confirmation_status VARCHAR(20) DEFAULT 'needs_confirmation',
        -- Values: 'confirmed' | 'needs_confirmation' | 'flexible'
    
    -- Priority & Classification
    priority VARCHAR(10) DEFAULT 'medium',
        -- Values: 'low' | 'medium' | 'high' | 'urgent'
    category VARCHAR(50),  -- 'research' | 'approval' | 'delivery' | 'communication'
    tags TEXT[],           -- Flexible tagging
    
    -- Evidence & Context
    requires_evidence BOOLEAN DEFAULT FALSE,
    evidence_type VARCHAR(50),  -- 'document' | 'approval' | 'data' | 'deliverable'
    extracted_from_transcript_id UUID REFERENCES transcript_segments(id),
    source_quote TEXT,     -- Exact quote from transcript
    context_before TEXT,   -- Previous context
    context_after TEXT,    -- Following context
    
    -- Status Tracking
    status VARCHAR(20) DEFAULT 'pending',
        -- Values: 'pending' | 'in_progress' | 'blocked' | 'completed' | 'cancelled'
    completion_percentage INT DEFAULT 0,
    blocked_reason TEXT,
    blocked_by UUID REFERENCES action_items(id),  -- What's blocking this
    
    -- Completion
    completed_at TIMESTAMP,
    completed_by UUID REFERENCES users(id),
    completion_notes TEXT,
    evidence_s3_keys TEXT[],  -- Array of S3 keys for uploaded evidence
    
    -- Review & Approval
    requires_approval BOOLEAN DEFAULT FALSE,
    approved_by UUID REFERENCES users(id),
    approved_at TIMESTAMP,
    approval_notes TEXT,
    
    -- Dependencies
    depends_on UUID[] DEFAULT '{}',  -- Array of action_item IDs
    blocks UUID[] DEFAULT '{}',      -- Action items blocked by this one
    
    -- Recurring Tasks (Future Enhancement)
    is_recurring BOOLEAN DEFAULT FALSE,
    recurrence_pattern JSONB,  -- {"frequency": "weekly", "day": "monday"}
    parent_action_item_id UUID REFERENCES action_items(id),
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    deleted_at TIMESTAMP,
    
    CONSTRAINT valid_priority CHECK (priority IN ('low', 'medium', 'high', 'urgent')),
    CONSTRAINT valid_status CHECK (status IN (
        'pending', 'in_progress', 'blocked', 'completed', 'cancelled'
    )),
    CONSTRAINT valid_completion_percentage 
        CHECK (completion_percentage BETWEEN 0 AND 100)
);

-- Indexes
CREATE INDEX idx_action_items_meeting ON action_items(meeting_id);
CREATE INDEX idx_action_items_assigned ON action_items(assigned_to, status) 
    WHERE assigned_to IS NOT NULL;
CREATE INDEX idx_action_items_due ON action_items(due_date, status) 
    WHERE status != 'completed' AND due_date IS NOT NULL;
CREATE INDEX idx_action_items_tenant ON action_items(tenant_id, status);
CREATE INDEX idx_action_items_priority ON action_items(priority, status) 
    WHERE status IN ('pending', 'in_progress');

-- ============================================================================
-- MEETING MINUTES DOCUMENTS
-- ============================================================================
CREATE TABLE meeting_minutes (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    
    -- Version Control
    version INT NOT NULL DEFAULT 1,
    is_current BOOLEAN DEFAULT TRUE,
    supersedes_id UUID REFERENCES meeting_minutes(id),  -- Previous version
    
    -- Content
    executive_summary TEXT,
    full_content TEXT,  -- Complete markdown content
    
    -- Structured Sections (for easy parsing)
    agenda_items JSONB,
        -- Example: [
        --   {
        --     "section": "Opening",
        --     "summary": "Meeting called to order at 9:00 AM"
        --   }
        -- ]
    
    attendees JSONB,
        -- Example: [
        --   {"name": "John Doe", "role": "participant", "present": true},
        --   {"name": "Jane Smith", "role": "secretary", "present": true}
        -- ]
    
    key_decisions TEXT[],  -- Array of decision statements
    action_items_summary JSONB,
        -- Summary of action items for quick reference
    
    risks_and_blockers TEXT[],
    parking_lot TEXT[],  -- Topics deferred to future meetings
    next_steps TEXT[],
    
    -- File Storage
    s3_markdown_key VARCHAR(500),
    s3_html_key VARCHAR(500),
    s3_pdf_key VARCHAR(500),
    s3_docx_key VARCHAR(500),
    
    -- Generation Details
    generated_by VARCHAR(20) NOT NULL,
        -- Values: 'ai' | 'secretary' | 'hybrid'
    generated_at TIMESTAMP DEFAULT NOW(),
    ai_model_used VARCHAR(100),  -- e.g., 'claude-3-sonnet-20240229'
    generation_prompt_tokens INT,
    generation_completion_tokens INT,
    generation_cost DECIMAL(10, 4),  -- Cost in USD
    
    -- Approval Workflow
    approved_by UUID REFERENCES users(id),
    approved_at TIMESTAMP,
    approval_notes TEXT,
    
    published_by UUID REFERENCES users(id),
    published_at TIMESTAMP,
    
    -- Distribution
    distributed_at TIMESTAMP,
    distribution_list JSONB,
        -- Example: [
        --   {"email": "john@example.com", "sent_at": "2025-02-11T10:30:00Z"},
        --   {"email": "jane@example.com", "sent_at": "2025-02-11T10:30:05Z"}
        -- ]
    
    -- Metadata
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(meeting_id, version)
);

-- Indexes
CREATE INDEX idx_minutes_meeting ON meeting_minutes(meeting_id);
CREATE INDEX idx_minutes_current ON meeting_minutes(meeting_id, is_current) 
    WHERE is_current = TRUE;
CREATE INDEX idx_minutes_approval ON meeting_minutes(approved_by, approved_at) 
    WHERE approved_at IS NOT NULL;

-- ============================================================================
-- SECRETARY LIVE MARKERS
-- ============================================================================
CREATE TABLE secretary_markers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    created_by UUID NOT NULL REFERENCES users(id),  -- Secretary
    
    -- Marker Details
    marker_type VARCHAR(20) NOT NULL,
        -- Values: 'decision' | 'action' | 'note' | 'question' | 'risk' | 'parking_lot'
    timestamp TIMESTAMP NOT NULL,  -- When marker was placed
    
    -- Content
    title VARCHAR(255),
    description TEXT,
    
    -- Association
    linked_transcript_segment_id UUID REFERENCES transcript_segments(id),
    linked_action_item_id UUID REFERENCES action_items(id),
    
    -- Visual Metadata (for UI)
    color VARCHAR(7) DEFAULT '#3b82f6',  -- Hex color
    icon VARCHAR(50) DEFAULT 'flag',      -- Icon identifier
    
    created_at TIMESTAMP DEFAULT NOW(),
    
    CONSTRAINT valid_marker_type CHECK (marker_type IN (
        'decision', 'action', 'note', 'question', 'risk', 'parking_lot'
    ))
);

-- Indexes
CREATE INDEX idx_secretary_markers_meeting ON secretary_markers(meeting_id, timestamp);
CREATE INDEX idx_secretary_markers_type ON secretary_markers(meeting_id, marker_type);

-- ============================================================================
-- NOTIFICATIONS & REMINDERS
-- ============================================================================
CREATE TABLE notifications (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    user_id UUID NOT NULL REFERENCES users(id),
    
    -- Notification Details
    type VARCHAR(50) NOT NULL,
        -- Types: 'meeting_invitation' | 'task_assigned' | 'task_reminder' | 
        --        'task_overdue' | 'meeting_started' | 'minutes_published' |
        --        'floor_granted' | 'marker_added'
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    
    -- Channels
    channel VARCHAR(20) NOT NULL,  -- 'email' | 'in_app' | 'push' | 'both'
    
    -- Related Entities
    meeting_id UUID REFERENCES meetings(id) ON DELETE CASCADE,
    action_item_id UUID REFERENCES action_items(id) ON DELETE CASCADE,
    
    -- Delivery
    scheduled_for TIMESTAMP NOT NULL,
    sent_at TIMESTAMP,
    delivered_at TIMESTAMP,
    read_at TIMESTAMP,
    clicked_at TIMESTAMP,
    
    -- Status
    status VARCHAR(20) DEFAULT 'pending',
        -- Values: 'pending' | 'sent' | 'delivered' | 'failed' | 'cancelled'
    failure_reason TEXT,
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    
    -- Email/Push Specifics
    email_message_id VARCHAR(255),  -- SES Message ID
    push_notification_id VARCHAR(255),
    
    -- Metadata
    metadata JSONB,  -- Flexible additional data
    
    created_at TIMESTAMP DEFAULT NOW(),
    
    CONSTRAINT valid_channel CHECK (channel IN ('email', 'in_app', 'push', 'both')),
    CONSTRAINT valid_status CHECK (status IN (
        'pending', 'sent', 'delivered', 'failed', 'cancelled'
    ))
);

-- Indexes
CREATE INDEX idx_notifications_user ON notifications(user_id, read_at);
CREATE INDEX idx_notifications_scheduled ON notifications(scheduled_for, status) 
    WHERE status = 'pending';
CREATE INDEX idx_notifications_meeting ON notifications(meeting_id) 
    WHERE meeting_id IS NOT NULL;
CREATE INDEX idx_notifications_action_item ON notifications(action_item_id) 
    WHERE action_item_id IS NOT NULL;

-- ============================================================================
-- TASK REMINDERS (Specific to Action Items)
-- ============================================================================
CREATE TABLE task_reminders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    action_item_id UUID NOT NULL REFERENCES action_items(id) ON DELETE CASCADE,
    
    -- Reminder Configuration
    reminder_type VARCHAR(20) NOT NULL,
        -- Values: 'due_soon' | 'overdue' | 'follow_up' | 'status_check'
    days_before_due INT,  -- For 'due_soon' reminders
    days_after_due INT,   -- For 'overdue' reminders
    
    -- Scheduling
    scheduled_for TIMESTAMP NOT NULL,
    sent_at TIMESTAMP,
    
    -- Delivery
    notification_id UUID REFERENCES notifications(id),
    status VARCHAR(20) DEFAULT 'pending',
        -- Values: 'pending' | 'sent' | 'cancelled'
    
    created_at TIMESTAMP DEFAULT NOW(),
    
    CONSTRAINT valid_reminder_type CHECK (reminder_type IN (
        'due_soon', 'overdue', 'follow_up', 'status_check'
    ))
);

-- Indexes
CREATE INDEX idx_task_reminders_action_item ON task_reminders(action_item_id);
CREATE INDEX idx_task_reminders_scheduled ON task_reminders(scheduled_for, status) 
    WHERE status = 'pending';

-- ============================================================================
-- TRANSCRIPTION JOBS (Track AWS Transcribe Jobs)
-- ============================================================================
CREATE TABLE transcription_jobs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    meeting_id UUID NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    audio_segment_id UUID REFERENCES audio_segments(id),
    
    -- AWS Job Details
    job_name VARCHAR(255) NOT NULL UNIQUE,
    job_id VARCHAR(255),
    
    -- Status
    status VARCHAR(20) DEFAULT 'queued',
        -- Values: 'queued' | 'in_progress' | 'completed' | 'failed'
    
    -- Timing
    queued_at TIMESTAMP DEFAULT NOW(),
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    duration_seconds INT,
    
    -- Results
    transcript_s3_key VARCHAR(500),
    transcript_text TEXT,  -- Cached for quick access
    confidence_average DECIMAL(3, 2),
    error_message TEXT,
    
    -- Configuration
    language_code VARCHAR(10) DEFAULT 'en-US',
    media_format VARCHAR(20) DEFAULT 'webm',
    enable_speaker_diarization BOOLEAN DEFAULT FALSE,
    
    -- Cost Tracking
    audio_duration_seconds DECIMAL(10, 2),
    estimated_cost DECIMAL(10, 4),  -- $0.024 per minute
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Indexes
CREATE INDEX idx_transcription_jobs_meeting ON transcription_jobs(meeting_id);
CREATE INDEX idx_transcription_jobs_status ON transcription_jobs(status);
CREATE INDEX idx_transcription_jobs_audio_segment ON transcription_jobs(audio_segment_id) 
    WHERE audio_segment_id IS NOT NULL;

-- ============================================================================
-- USAGE METRICS (For Billing & Analytics)
-- ============================================================================
CREATE TABLE usage_metrics (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Time Period
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    
    -- Meeting Metrics
    meetings_created INT DEFAULT 0,
    meetings_completed INT DEFAULT 0,
    total_meeting_minutes INT DEFAULT 0,
    total_participants INT DEFAULT 0,
    avg_participants_per_meeting DECIMAL(10, 2),
    
    -- Audio Metrics
    audio_hours_recorded DECIMAL(10, 2) DEFAULT 0,
    audio_hours_transcribed DECIMAL(10, 2) DEFAULT 0,
    audio_gigabytes_stored DECIMAL(10, 2) DEFAULT 0,
    
    -- AI Usage
    ai_cleanup_requests INT DEFAULT 0,
    ai_extraction_requests INT DEFAULT 0,
    ai_minutes_generated INT DEFAULT 0,
    total_ai_tokens INT DEFAULT 0,
    
    -- Tasks
    action_items_created INT DEFAULT 0,
    action_items_completed INT DEFAULT 0,
    action_items_overdue INT DEFAULT 0,
    
    -- Costs (Internal Tracking)
    transcription_cost DECIMAL(10, 2) DEFAULT 0,
    ai_cost DECIMAL(10, 2) DEFAULT 0,
    storage_cost DECIMAL(10, 2) DEFAULT 0,
    total_cost DECIMAL(10, 2) GENERATED ALWAYS AS (
        transcription_cost + ai_cost + storage_cost
    ) STORED,
    
    created_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(tenant_id, period_start)
);

-- Indexes
CREATE INDEX idx_usage_metrics_tenant ON usage_metrics(tenant_id, period_start);

-- ============================================================================
-- AUDIT LOGS (Compliance & Security)
-- ============================================================================
CREATE TABLE audit_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    user_id UUID REFERENCES users(id),
    
    -- Event Details
    event_type VARCHAR(50) NOT NULL,
        -- Examples: 'meeting_created' | 'meeting_started' | 'meeting_ended' |
        --           'transcript_viewed' | 'transcript_edited' | 'minutes_approved' |
        --           'task_assigned' | 'task_completed' | 'audio_deleted' |
        --           'participant_joined' | 'participant_ejected'
    
    -- Entity References
    meeting_id UUID REFERENCES meetings(id),
    action_item_id UUID REFERENCES action_items(id),
    
    -- Event Data
    description TEXT,
    metadata JSONB,  -- Flexible event-specific data
    
    -- Request Context
    ip_address INET,
    user_agent TEXT,
    request_path VARCHAR(500),
    request_method VARCHAR(10),
    
    -- Sensitive Operations Flag
    is_sensitive BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT NOW()
);

-- Indexes
CREATE INDEX idx_audit_logs_tenant ON audit_logs(tenant_id, created_at DESC);
CREATE INDEX idx_audit_logs_user ON audit_logs(user_id, created_at DESC) 
    WHERE user_id IS NOT NULL;
CREATE INDEX idx_audit_logs_meeting ON audit_logs(meeting_id) 
    WHERE meeting_id IS NOT NULL;
CREATE INDEX idx_audit_logs_event_type ON audit_logs(event_type, created_at DESC);
CREATE INDEX idx_audit_logs_sensitive ON audit_logs(tenant_id, created_at DESC) 
    WHERE is_sensitive = TRUE;
```

---

## Key Relationships

```
meetings (1) ───────────< (many) meeting_participants
    │
    ├──────────────────< (many) audio_segments
    │
    ├──────────────────< (many) transcript_segments
    │
    ├──────────────────< (many) action_items
    │
    ├──────────────────< (many) secretary_markers
    │
    ├──────────────────< (many) transcription_jobs
    │
    └──────────────────< (1) meeting_minutes

action_items (1) ───────< (many) task_reminders
    │
    └──────────────────< (many) notifications

users (1) ──────────────< (many) meeting_participants
    │
    ├──────────────────< (many) action_items (assigned_to)
    │
    ├──────────────────< (many) notifications
    │
    └──────────────────< (many) audit_logs

tenants (1) ────────────< (many) meetings
    │
    ├──────────────────< (many) action_items
    │
    ├──────────────────< (many) usage_metrics
    │
    └──────────────────< (many) audit_logs
```

---

## Views for Common Queries

```sql
-- ============================================================================
-- ACTIVE MEETINGS DASHBOARD
-- ============================================================================
CREATE VIEW active_meetings_view AS
SELECT 
    m.id,
    m.tenant_id,
    m.title,
    m.started_at,
    m.status,
    m.recording_mode,
    COUNT(DISTINCT mp.id) as participant_count,
    COUNT(DISTINCT mp.id) FILTER (WHERE mp.status = 'joined') as active_participant_count,
    COUNT(DISTINCT ts.id) as transcript_segment_count,
    SUM(ts.duration_seconds) as total_speaking_seconds,
    m.organizer_id,
    u.name as organizer_name,
    sec.name as secretary_name
FROM meetings m
LEFT JOIN meeting_participants mp ON m.id = mp.meeting_id
LEFT JOIN transcript_segments ts ON m.id = ts.meeting_id
LEFT JOIN users u ON m.organizer_id = u.id
LEFT JOIN users sec ON m.secretary_id = sec.id
WHERE m.status = 'in_progress'
GROUP BY m.id, u.name, sec.name;

-- ============================================================================
-- USER TASK DASHBOARD
-- ============================================================================
CREATE VIEW user_tasks_view AS
SELECT 
    ai.id,
    ai.title,
    ai.description,
    ai.priority,
    ai.status,
    ai.due_date,
    ai.completion_percentage,
    ai.assigned_to,
    ai.meeting_id,
    m.title as meeting_title,
    m.scheduled_at as meeting_date,
    u.name as assigned_by_name,
    CASE 
        WHEN ai.due_date < CURRENT_DATE THEN 'overdue'
        WHEN ai.due_date = CURRENT_DATE THEN 'due_today'
        WHEN ai.due_date <= CURRENT_DATE + INTERVAL '3 days' THEN 'due_soon'
        ELSE 'on_track'
    END as urgency_status,
    COALESCE(ai.due_date - CURRENT_DATE, 0) as days_until_due
FROM action_items ai
JOIN meetings m ON ai.meeting_id = m.id
LEFT JOIN users u ON ai.assigned_by = u.id
WHERE ai.status IN ('pending', 'in_progress', 'blocked')
    AND ai.deleted_at IS NULL
ORDER BY 
    CASE ai.priority
        WHEN 'urgent' THEN 1
        WHEN 'high' THEN 2
        WHEN 'medium' THEN 3
        WHEN 'low' THEN 4
    END,
    ai.due_date ASC NULLS LAST;

-- ============================================================================
-- MEETING SUMMARY VIEW
-- ============================================================================
CREATE VIEW meeting_summary_view AS
SELECT 
    m.id,
    m.tenant_id,
    m.title,
    m.scheduled_at,
    m.started_at,
    m.ended_at,
    m.duration_minutes,
    m.status,
    COUNT(DISTINCT mp.id) as total_participants,
    COUNT(DISTINCT mp.id) FILTER (WHERE mp.joined_at IS NOT NULL) as participants_attended,
    COUNT(DISTINCT ts.id) as total_transcript_segments,
    SUM(ts.duration_seconds) as total_speaking_seconds,
    COUNT(DISTINCT ai.id) as total_action_items,
    COUNT(DISTINCT ai.id) FILTER (WHERE ai.status = 'completed') as completed_action_items,
    COUNT(DISTINCT ai.id) FILTER (WHERE ai.status = 'pending') as pending_action_items,
    EXISTS(SELECT 1 FROM meeting_minutes mm WHERE mm.meeting_id = m.id AND mm.is_current = TRUE) as has_minutes,
    EXISTS(SELECT 1 FROM meeting_minutes mm WHERE mm.meeting_id = m.id AND mm.approved_at IS NOT NULL) as minutes_approved
FROM meetings m
LEFT JOIN meeting_participants mp ON m.id = mp.meeting_id
LEFT JOIN transcript_segments ts ON m.id = ts.meeting_id
LEFT JOIN action_items ai ON m.id = ai.meeting_id
GROUP BY m.id;

-- ============================================================================
-- TENANT USAGE SUMMARY (Current Month)
-- ============================================================================
CREATE VIEW tenant_usage_current_month AS
SELECT 
    t.id as tenant_id,
    t.name as tenant_name,
    COUNT(DISTINCT m.id) as meetings_this_month,
    SUM(m.duration_minutes) as total_meeting_minutes,
    COUNT(DISTINCT mp.id) as total_participants,
    SUM(CASE WHEN as2.file_size_bytes IS NOT NULL THEN as2.file_size_bytes ELSE 0 END) / 1073741824.0 as audio_gb_stored,
    COUNT(DISTINCT ai.id) as action_items_created,
    s.meetings_per_month as plan_meeting_limit,
    CASE 
        WHEN COUNT(DISTINCT m.id) >= s.meetings_per_month THEN TRUE
        ELSE FALSE
    END as limit_reached
FROM tenants t
LEFT JOIN subscriptions sub ON t.id = sub.tenant_id
LEFT JOIN subscription_plans s ON sub.plan_id = s.id
LEFT JOIN meetings m ON t.id = m.tenant_id 
    AND DATE_TRUNC('month', m.created_at) = DATE_TRUNC('month', CURRENT_DATE)
LEFT JOIN meeting_participants mp ON m.id = mp.meeting_id
LEFT JOIN audio_segments as2 ON m.id = as2.meeting_id
LEFT JOIN action_items ai ON m.id = ai.meeting_id
GROUP BY t.id, t.name, s.meetings_per_month;
```

---

This completes Part 2. Ready for Part 3 (Implementation Phases & Development Roadmap)?
