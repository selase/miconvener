# Meeting Minutes & Action Tracker SaaS
## Part 3: Implementation Phases & Development Roadmap

**Version:** 2.0  
**Last Updated:** February 2025

---

## MVP Development Strategy

### Core Principle: Ship Fast, Iterate Based on Feedback

**MVP Timeline:** 6-8 weeks  
**Goal:** Launch a functional product that solves the core problem  
**Philosophy:** "Make it work, make it right, make it fast" - in that order

---

## Phase 1: Ship MVP (Weeks 1-6)

### Week 1-2: Foundation & Core Infrastructure

**Backend Setup**
- [ ] Clone your Laravel 12 starter kit
- [ ] Create new database tables (meetings, participants, transcripts, actions)
- [ ] Run migrations and seed test data
- [ ] Set up Laravel Horizon for queue management
- [ ] Configure Laravel Reverb for WebSocket server
- [ ] Create API routes structure

**Models & Relationships**
```php
// Priority order for model creation:
1. Meeting (core entity)
2. MeetingParticipant (join meetings)
3. AudioSegment (store upload metadata)
4. TranscriptSegment (store transcriptions)
5. ActionItem (tasks)
6. MeetingMinutes (final output)
```

**AWS Setup**
- [ ] Create S3 bucket for audio storage
- [ ] Configure bucket lifecycle policies (30-day deletion)
- [ ] Set up IAM roles for Lambda functions
- [ ] Deploy initial Lambda function (audio processor)

**API Endpoints (Critical Path Only)**
```
POST   /api/meetings                    # Create meeting
POST   /api/meetings/{id}/start         # Start meeting
POST   /api/meetings/{id}/end           # End meeting
POST   /api/meetings/{id}/participants  # Invite participants
POST   /api/meetings/{id}/join          # Join with token
POST   /api/meetings/{id}/upload-url    # Get S3 pre-signed URL
POST   /api/meetings/{id}/audio-chunk   # Confirm upload
GET    /api/meetings/{id}/transcript    # View transcript
```

**Deliverables:**
- Working API for meeting CRUD
- Participant invitation system
- Email notifications working
- S3 upload coordination functional

---

### Week 3-4: PWA & Native Mobile

**React PWA Development**

Priority Components:
```typescript
1. MeetingLobby (join meeting)
2. VoiceEnrollment (10-second recording)
3. PushToTalkRecorder (core recording interface)
4. ParticipantList (live presence)
5. MeetingControls (start/end meeting)
```

**Critical Features Only:**
- [ ] Push-to-talk recording with visual feedback
- [ ] Direct S3 upload via pre-signed URLs
- [ ] WebSocket connection for real-time updates
- [ ] Offline buffering in IndexedDB
- [ ] Auto-retry on upload failure

**Skip for MVP:**
- ~~Floor control system~~ (Phase 2)
- ~~Live captions~~ (Phase 2)
- ~~Secretary live markers~~ (Phase 2)
- ~~Advanced UI polish~~ (Phase 2)

**NativePHP Mobile (Parallel Development)**

Focus on iOS first, Android second:
```php
// Week 3: iOS Setup
- Configure NativePHP for iOS
- Background audio recording
- Local notifications
- Deploy to TestFlight

// Week 4: Android Setup  
- Configure NativePHP for Android
- Background services
- Notification permissions
- Deploy to Internal Testing
```

**Deliverables:**
- Functional PWA for meeting participation
- iOS app in TestFlight
- Android app in Internal Testing
- All apps can record and upload audio

---

### Week 5: AWS Transcription Pipeline

**Lambda Functions to Build:**

1. **Audio Processor** (Highest Priority)
```python
# Trigger: S3 ObjectCreated
# Action: Start AWS Transcribe job
# Complexity: Low
# Time: 1 day
```

2. **Transcription Orchestrator**
```python
# Trigger: Transcribe job completion
# Action: Fetch transcript, store in DB
# Complexity: Medium
# Time: 2 days
```

3. **Meeting Completion Handler**
```python
# Trigger: EventBridge (MeetingEnded)
# Action: Aggregate all transcripts
# Complexity: Medium
# Time: 1 day
```

**AWS Transcribe Configuration:**
```json
{
  "MediaFormat": "webm",
  "LanguageCode": "en-US",
  "Settings": {
    "ShowSpeakerLabels": false,  // We know speaker from participant_id
    "MaxSpeakerLabels": 1,
    "ChannelIdentification": false
  }
}
```

**Deliverables:**
- Audio automatically transcribed when uploaded
- Transcripts visible in Laravel API
- Basic transcript viewing in UI

---

### Week 6: AI Processing & Task Distribution

**Laravel Queue Jobs:**

1. **CleanupTranscriptJob** (2 days)
```php
// Fetch raw transcripts
// Call Claude Haiku to fix grammar
// Store cleaned version
```

2. **ExtractActionItemsJob** (2 days)
```php
// Fetch cleaned transcript
// Call Claude Haiku to extract tasks
// Parse JSON response
// Create ActionItem records
// Match assignees to users
```

3. **GenerateMeetingMinutesJob** (2 days)
```php
// Prepare context (meeting, transcript, actions)
// Call Claude Sonnet for professional minutes
// Convert Markdown to PDF
// Store in S3
// Notify secretary
```

4. **DistributeTasksJob** (1 day)
```php
// Email each participant their tasks
// Create task reminders
// Schedule follow-ups
```

**AI Prompt Engineering:**

Critical prompts to develop:
```
1. Transcript Cleanup
   - Remove filler words
   - Fix grammar
   - Organize into paragraphs
   - PRESERVE MEANING (don't summarize)

2. Action Item Extraction
   - Return ONLY JSON
   - Conservative extraction
   - Exact source quotes
   - Handle ambiguity gracefully

3. Minutes Generation
   - Professional tone
   - Clear structure
   - Executive summary
   - Action items table
```

**Deliverables:**
- Automated transcript cleanup
- Action items auto-extracted
- Tasks emailed to participants
- Basic meeting minutes generated

---

### Week 7: Testing & Bug Fixes

**End-to-End Testing Checklist:**

Meeting Lifecycle:
- [ ] Create meeting → Participants receive emails
- [ ] Join meeting → Voice enrollment works
- [ ] Record audio → Uploads to S3 successfully
- [ ] Audio transcribes → Appears in transcript view
- [ ] End meeting → AI processing triggers
- [ ] Minutes generated → Secretary receives notification
- [ ] Tasks distributed → Participants receive emails

**Critical Bugs to Fix:**
- Audio upload failures and retries
- WebSocket disconnections
- Transcript segment ordering
- Action item assignee matching
- Email delivery issues

**Performance Testing:**
- 10 participants in one meeting
- 60-minute meeting duration
- Concurrent meeting creation

**Deliverables:**
- All critical bugs fixed
- MVP is stable and demo-ready
- Documentation for onboarding

---

### Week 8: Launch Preparation & MVP Release

**Pre-Launch Checklist:**

Technical:
- [ ] Production database backups configured
- [ ] CloudWatch monitoring and alerts set up
- [ ] Error tracking (Sentry/Bugsnag)
- [ ] Rate limiting on API endpoints
- [ ] Cost anomaly detection configured
- [ ] SSL certificates renewed
- [ ] DNS configured

Business:
- [ ] Pricing page live
- [ ] Payment processing tested
- [ ] Subscription upgrade/downgrade flows
- [ ] Cancellation policy clear
- [ ] Terms of service & privacy policy

Content:
- [ ] Landing page with demo video
- [ ] Help documentation
- [ ] Onboarding tutorial
- [ ] FAQ section
- [ ] Support email set up

**Soft Launch Strategy:**
1. **Week 8 Day 1-2:** Internal testing with team
2. **Week 8 Day 3-4:** Beta testers (5-10 companies)
3. **Week 8 Day 5-7:** Public launch announcement

**Launch Channels:**
- Product Hunt launch
- LinkedIn posts
- Email to waitlist
- Reddit (r/productivity, r/saas)
- Indie Hackers community

**Deliverables:**
- MVP LAUNCHED! 🚀
- First paying customers
- Feedback collection system active

---

## Phase 2: "Feels Magical" (Weeks 9-14)

**Goal:** Add features that delight users and differentiate from competitors

### Week 9-10: Secretary Live Features

**Secretary Live Markers**
```typescript
// New UI components
<MarkerPlacementToolbar>
  <MarkerButton type="decision" color="#10b981" />
  <MarkerButton type="action" color="#f59e0b" />
  <MarkerButton type="note" color="#3b82f6" />
  <MarkerButton type="risk" color="#ef4444" />
  <MarkerButton type="parking_lot" color="#8b5cf6" />
</MarkerPlacementToolbar>
```

**Features:**
- Secretary can place markers during meeting
- Markers broadcast to all participants in real-time
- Markers linked to transcript segments
- Visual timeline of markers
- Markers influence AI processing (more weight on marked segments)

**Floor Control System**
- One speaker at a time mode
- Request floor button
- Visual queue of pending speakers
- Auto-release after 5 minutes
- Secretary override controls

**Deliverables:**
- Secretary can mark key moments live
- Floor control reduces cost by 60%
- Better meeting structure

---

### Week 11-12: Room Capture Fallback & Review UI

**Room Capture Feature:**
- Secretary device records room audio
- AWS Transcribe with speaker diarization
- Segments marked as "Unattributed"
- Secretary assigns segments to participants

**Segment Review UI:**
```typescript
<UnattributedSegments>
  {segments.map(segment => (
    <SegmentCard>
      <AudioPlayer src={segment.audioUrl} />
      <Text>{segment.text}</Text>
      <ParticipantSelector
        participants={meetingParticipants}
        onAssign={(participantId) => assignSegment(segment.id, participantId)}
      />
    </SegmentCard>
  ))}
</UnattributedSegments>
```

**Deliverables:**
- Fallback for missed recordings
- Manual segment assignment
- Higher transcript completeness

---

### Week 13: Advanced Minutes Editor

**Rich Text Editor Integration:**
- TipTap or Quill editor
- Section templates (drag & drop)
- Track changes mode
- Comment system for review
- Version history with diff view
- Approval workflow

**PDF Generation Improvements:**
- Custom branding (logo, colors)
- Multiple templates (formal, casual, technical)
- Automatic formatting
- Page numbers and headers
- Export to DOCX option

**Deliverables:**
- Secretary can edit minutes easily
- Professional output
- Version control

---

### Week 14: Domain Accuracy & Custom Vocabulary

**Custom Vocabulary Management:**
```php
// app/Models/TenantSettings.php

public function updateCustomVocabulary(array $terms)
{
    // Validate terms
    $validated = array_map(function($term) {
        return [
            'phrase' => $term['phrase'],
            'sounds_like' => $term['sounds_like'] ?? null,
            'display_as' => $term['display_as'] ?? $term['phrase']
        ];
    }, $terms);
    
    $this->custom_vocabulary = $validated;
    $this->save();
    
    // Upload to AWS Transcribe
    $this->uploadVocabularyToAWS();
}
```

**Industry Templates:**
- Medical: drug names, procedures
- Legal: case citations, legal terms
- Tech: product names, acronyms
- Finance: financial instruments, regulations

**Deliverables:**
- Tenant-specific vocabulary
- Industry templates
- Higher transcription accuracy

---

## Phase 3: Enterprise Features (Weeks 15-20)

**Goal:** Scale to enterprise customers with advanced needs

### Week 15-16: Real-time Live Captions

**AWS Transcribe Streaming API:**
```python
# New Lambda: Streaming Transcriber
# Uses WebSocket to stream audio
# Returns captions with <2 second latency
```

**Features:**
- Live captions display during meeting
- Speaker labels in real-time
- Caption export to SRT/VTT
- Accessibility compliance (ADA, Section 508)

**Cost Impact:**
- Streaming: $0.025/minute (4% more expensive)
- Only enable for Enterprise plan

---

### Week 17-18: Calendar & Project Management Integrations

**Google Calendar Integration:**
- OAuth 2.0 authentication
- Auto-create meetings from calendar events
- Sync meeting links to calendar
- Update calendar on meeting changes

**Microsoft Outlook Integration:**
- Similar to Google Calendar
- Microsoft Graph API

**Jira Integration:**
```php
// Convert action items to Jira issues
public function syncToJira(ActionItem $actionItem)
{
    $jiraClient->issues()->create([
        'project' => ['key' => $tenant->jira_project_key],
        'summary' => $actionItem->title,
        'description' => $actionItem->description,
        'assignee' => ['accountId' => $this->mapUserToJira($actionItem->assigned_to)],
        'duedate' => $actionItem->due_date->format('Y-m-d')
    ]);
}
```

**Asana, Linear, Monday.com:**
- Similar integration patterns
- Use webhooks for bi-directional sync

---

### Week 19: Team Communication Integrations

**Slack Integration:**
- Send meeting minutes to channel
- Task notifications in DMs
- Slash commands (/minutes, /tasks)
- Interactive buttons

**Microsoft Teams Integration:**
- Similar to Slack
- Adaptive Cards for rich notifications

**Webhook Support:**
- Generic webhook endpoint
- Custom integration builder
- Zapier integration

---

### Week 20: Compliance & Governance

**Features:**
- Audit log UI (view all access)
- Compliance reports (GDPR, HIPAA, SOC 2)
- Legal hold functionality (prevent deletion)
- Data retention policy enforcement
- E-discovery support (search across meetings)
- Data export (all tenant data in one ZIP)

**Certifications to Pursue:**
- SOC 2 Type II ($15,000-25,000 annual)
- HIPAA compliance (Healthcare market)
- ISO 27001 (International enterprise)

---

## Post-Launch: Continuous Improvement

### Metrics to Track

**Product Metrics:**
- Monthly Active Users (MAU)
- Meetings per user per month
- Average meeting duration
- Transcription accuracy (surveyed)
- Action item completion rate
- Time saved vs. manual transcription

**Business Metrics:**
- Monthly Recurring Revenue (MRR)
- Customer Acquisition Cost (CAC)
- Lifetime Value (LTV)
- Churn rate
- Net Promoter Score (NPS)

**Technical Metrics:**
- API response times
- WebSocket connection stability
- Audio upload success rate
- Transcription job success rate
- Average time to process meeting

### Feedback Loops

**In-App Feedback:**
- Thumbs up/down on features
- Bug report button
- Feature request form

**Regular User Interviews:**
- Weekly calls with 5-10 active users
- Monthly power user roundtable
- Quarterly all-hands user feedback session

**Analytics:**
- Mixpanel for product analytics
- Hotjar for session recordings
- Sentry for error tracking

---

## Development Resources Needed

### Team Structure (MVP)

**Minimum Viable Team:**
- 1 Full-Stack Developer (Laravel + React)
- 1 DevOps/Cloud Engineer (AWS)
- 1 Product Manager/Designer (part-time)

**Ideal Team:**
- 1 Backend Developer (Laravel)
- 1 Frontend Developer (React/TypeScript)
- 1 Mobile Developer (NativePHP)
- 1 DevOps Engineer (AWS/Terraform)
- 1 Product Designer
- 1 QA Engineer

### External Services Budget

**Development Phase:**
- AWS (development): $50/month
- OpenAI API (testing): $20/month
- Test email service: $0 (SendGrid free tier)
- Error tracking: $0 (Sentry free tier)
- **Total:** ~$70/month

**Production Phase (100 meetings/month):**
- AWS infrastructure: $495/month
- Laravel Forge: $24/month
- Domain + SSL: $5/month
- Monitoring tools: $50/month
- **Total:** ~$574/month

---

## Risk Mitigation

### Technical Risks

**Risk 1: Audio Upload Failures**
- Mitigation: Offline buffering + auto-retry
- Fallback: Manual upload option

**Risk 2: Transcription Inaccuracy**
- Mitigation: Custom vocabulary + manual review
- Fallback: Secretary can edit transcripts

**Risk 3: AWS Cost Overruns**
- Mitigation: Cost anomaly detection + alerts
- Fallback: Rate limiting + feature gates

**Risk 4: Speaker Misidentification**
- Mitigation: Push-to-talk provides explicit attribution
- Fallback: Room capture with manual assignment

### Business Risks

**Risk 1: Low User Adoption**
- Mitigation: Generous free trial (30 days)
- Fallback: Direct sales to enterprise

**Risk 2: High Churn Rate**
- Mitigation: Excellent onboarding + support
- Fallback: Annual contracts with discount

**Risk 3: Competition from Big Tech**
- Mitigation: Focus on in-person meetings niche
- Fallback: White-label offering to partners

---

This completes Part 3. Ready for Part 4 (Technical Implementation Details & Code Examples)?
