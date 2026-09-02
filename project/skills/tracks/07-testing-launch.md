# Track 07: Testing, Polish & MVP Launch

**Phase:** MVP (Weeks 7-8)
**Priority:** Critical
**Dependencies:** Tracks 01-06
**Status:** Not Started

---

## Objective

Comprehensive testing of the full meeting lifecycle, bug fixes, performance optimization, and launch preparation.

---

## Tasks

### T07.1 - End-to-End Meeting Lifecycle Test

**Effort:** 2 days
**Dependencies:** All tracks 01-06

Manual + automated testing of the full flow:

- [ ] Create meeting with 3+ participants
- [ ] Send invitations and verify email delivery
- [ ] Join meeting via invitation link
- [ ] Record push-to-talk audio (multiple participants)
- [ ] Verify audio uploads to S3 successfully
- [ ] End meeting and verify processing pipeline triggers
- [ ] Verify transcription completes
- [ ] Verify AI cleanup runs
- [ ] Verify action items extracted
- [ ] Verify meeting minutes generated
- [ ] Verify task distribution emails sent
- [ ] Verify PDF minutes downloadable
- [ ] Mark tasks as complete
- [ ] Verify reminder scheduler works

**Acceptance Criteria:**
- Full lifecycle completes in <10 minutes for a 5-minute meeting
- No errors in any step
- All data consistent across tables

---

### T07.2 - Critical Bug Fixes

**Effort:** 2-3 days
**Dependencies:** T07.1

Common issues to watch for:

- [ ] Audio upload failures under poor network
- [ ] WebSocket disconnection during meeting
- [ ] Transcript segment ordering with concurrent speakers
- [ ] Action item assignee matching when names differ from participant list
- [ ] Email delivery failures (SES bounces)
- [ ] S3 pre-signed URL expiration during long recordings
- [ ] Queue job failures and dead letter handling
- [ ] Meeting timeout (max duration exceeded)
- [ ] Concurrent meeting creation race conditions

---

### T07.3 - Performance Testing

**Effort:** 1 day
**Dependencies:** T07.1

- [ ] Test with 10 simultaneous participants
- [ ] Test with 60-minute meeting (sustained recording)
- [ ] Test with 3 concurrent meetings
- [ ] Measure API response times (target: <200ms p95)
- [ ] Measure WebSocket latency (target: <500ms)
- [ ] Measure audio upload throughput
- [ ] Check database query performance (N+1 queries, missing indexes)
- [ ] Verify queue processing keeps up (no backlog)

**Acceptance Criteria:**
- No degradation with 10 participants
- 60-minute meetings process successfully
- API latency under target thresholds
- Database queries optimized

---

### T07.4 - Security Audit

**Effort:** 1 day
**Dependencies:** T07.1

- [ ] Verify tenant isolation (no cross-tenant data access)
- [ ] Verify authorization on all endpoints (only organizer can start/end)
- [ ] Verify invitation tokens are single-use and time-limited
- [ ] Verify S3 pre-signed URLs scoped to correct paths
- [ ] Verify API rate limiting is effective
- [ ] Check for SQL injection in any raw queries
- [ ] Verify CSRF protection on all web forms
- [ ] Verify audio files not publicly accessible (S3 bucket policy)

**Acceptance Criteria:**
- No cross-tenant access possible
- All endpoints properly authorized
- S3 bucket private, only accessible via pre-signed URLs
- Rate limiting prevents abuse

---

### T07.5 - Feature Seeder & Demo Data

**Effort:** 0.5 days
**Dependencies:** T01.3

- [ ] Create `MeetingSeeder` with realistic demo meetings
- [ ] Include meetings in various states (draft, active, completed)
- [ ] Include sample transcripts, action items, minutes
- [ ] Create demo tenant with pre-populated data
- [ ] Update `DatabaseSeeder` to include meeting data

**Acceptance Criteria:**
- Fresh install has meaningful demo data
- All meeting states represented
- Demo walkthrough possible without recording real audio

---

### T07.6 - Launch Preparation

**Effort:** 1 day
**Dependencies:** T07.1-T07.4

- [ ] Production environment configuration
- [ ] CloudWatch monitoring and alerts:
  - Queue backlog > 100 jobs
  - API error rate > 1%
  - Transcription failure rate > 5%
  - S3 storage approaching budget
- [ ] AWS cost anomaly detection enabled
- [ ] Database backup schedule verified
- [ ] Error tracking (Sentry or Telescope in production)
- [ ] Rate limiting configured for production traffic
- [ ] SSL certificates valid
- [ ] DNS configured for custom domains

**Acceptance Criteria:**
- Production environment stable
- Monitoring alerts configured and tested
- Backup/recovery tested
- Ready for first users

---

### T07.7 - Documentation

**Effort:** 0.5 days
**Dependencies:** All

- [ ] API documentation for PWA developers (endpoints, auth, responses)
- [ ] Onboarding guide for new tenants (how to create first meeting)
- [ ] Admin guide for meeting management
- [ ] Troubleshooting guide (common issues and solutions)

**Acceptance Criteria:**
- API docs cover all endpoints
- Onboarding guide enables self-service setup
- Troubleshooting covers top 10 likely issues

---

## Testing Summary (All Tracks)

| Track | Feature Tests | Unit Tests | Integration Tests |
|---|---|---|---|
| 01 Foundation | Migrations, Routes, Features | Factories, Casts | Feature gate integration |
| 02 Meeting Core | CRUD, Lifecycle, API | Join code, State machine | Tenant isolation |
| 03 Audio Pipeline | Upload URLs, Confirmation | S3 key format | S3 upload flow |
| 04 Transcription | Job execution, API | JSON parsing, Ordering | AWS Transcribe mock |
| 05 AI Processing | All jobs, PDF generation | Prompts, JSON parsing | Full pipeline |
| 06 Distribution | Emails, Reminders, Workflow | Dedup, Grouping | End-to-end notifications |
| 07 Launch | Lifecycle, Performance | Security | Full system |
