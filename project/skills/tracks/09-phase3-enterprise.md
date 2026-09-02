# Track 09: Phase 3 - Enterprise Features

**Phase:** Phase 3 (Weeks 15-20)
**Priority:** Medium (post-Phase 2)
**Dependencies:** Phase 2 complete (Track 08)
**Status:** Not Started

---

## Objective

Scale to enterprise customers with real-time captions, calendar/PM integrations, team communication hooks, and compliance features.

---

## Tasks

### T09.1 - Real-Time Live Captions (Weeks 15-16)

**Effort:** 5 days
**Dependencies:** T04.1

- [ ] Integrate AWS Transcribe Streaming API
- [ ] WebSocket audio stream from client to Lambda
- [ ] Lambda proxies to Transcribe Streaming
- [ ] Captions broadcast back via Reverb WebSocket
- [ ] Display live captions overlay in meeting room UI
- [ ] Speaker labels in real-time
- [ ] Caption export to SRT/VTT format
- [ ] Accessibility compliance (ADA, Section 508)
- [ ] Configurable caption language
- [ ] Cost: $0.025/min streaming (4% more than batch)

**Acceptance Criteria:**
- Captions appear within 2 seconds of speech
- Speaker correctly identified
- Exportable to standard subtitle formats
- Only available on Enterprise plan

---

### T09.2 - Calendar Integrations (Weeks 17-18)

**Effort:** 4 days
**Dependencies:** MVP complete

**Google Calendar:**
- [ ] OAuth 2.0 flow for Google account connection
- [ ] Auto-create meetings from upcoming calendar events
- [ ] Sync meeting links back to calendar events
- [ ] Two-way sync: calendar changes update meeting
- [ ] Import attendee list from calendar event

**Microsoft Outlook:**
- [ ] Microsoft Graph API OAuth integration
- [ ] Same feature set as Google Calendar
- [ ] Handle Microsoft organizational accounts

**Common:**
- [ ] Calendar connection settings UI
- [ ] Sync frequency configuration
- [ ] Conflict detection (meeting already exists)
- [ ] Disconnect/reconnect flow

**Acceptance Criteria:**
- One-click meeting creation from calendar events
- Attendees auto-imported
- Changes sync both directions
- Works with organizational (enterprise) accounts

---

### T09.3 - Project Management Integrations (Weeks 17-18)

**Effort:** 4 days
**Dependencies:** T05.4 (Action Items)

Convert action items to tasks in external PM tools:

**Jira:**
- [ ] OAuth connection to Jira Cloud
- [ ] Map action items to Jira issues
- [ ] Project/board selection
- [ ] Assignee mapping (meeting participant -> Jira user)
- [ ] Bi-directional status sync

**Asana:**
- [ ] PAT or OAuth connection
- [ ] Map action items to Asana tasks
- [ ] Workspace/project selection
- [ ] Assignee mapping

**Linear:**
- [ ] API key connection
- [ ] Map action items to Linear issues
- [ ] Team/project selection

**Monday.com:**
- [ ] OAuth connection
- [ ] Map action items to Monday items
- [ ] Board/group selection

**Common:**
- [ ] Integration settings UI per tenant
- [ ] Auto-sync toggle (push on creation) or manual sync
- [ ] Store `external_id` and `external_provider` on ActionItem
- [ ] Webhook listeners for status changes from PM tools
- [ ] Conflict resolution (last write wins with audit log)

**Acceptance Criteria:**
- Action items appear in PM tool within 30 seconds
- Status changes sync both directions
- Assignee mapping handles mismatches gracefully
- Each tenant configures their own connection

---

### T09.4 - Team Communication Integrations (Week 19)

**Effort:** 3 days
**Dependencies:** T06.1 (Task Distribution)

**Slack:**
- [ ] Slack App with OAuth (bot + user tokens)
- [ ] Post meeting minutes summary to configured channel
- [ ] DM task assignments to individuals
- [ ] Slash commands: `/minutes [meeting-id]`, `/tasks`
- [ ] Interactive buttons for task status updates
- [ ] Meeting reminder notifications

**Microsoft Teams:**
- [ ] Teams App with Adaptive Cards
- [ ] Post minutes to configured channel
- [ ] Personal chat for task assignments
- [ ] Bot commands for meeting/task queries

**Generic Webhooks:**
- [ ] Leverage existing webhook system
- [ ] Meeting events: created, started, ended, minutes_ready
- [ ] Task events: assigned, completed, overdue
- [ ] Custom webhook builder UI
- [ ] Zapier-compatible webhook format

**Acceptance Criteria:**
- Messages post to correct channels
- Task updates interactive (buttons work)
- Slash commands respond quickly (<3 seconds)
- Generic webhooks enable custom integrations

---

### T09.5 - Compliance & Governance (Week 20)

**Effort:** 4 days
**Dependencies:** MVP complete

Enterprise compliance features:

- [ ] **Audit Log UI** - Searchable view of all meeting access and changes
  - Who viewed what, when
  - All edits to minutes with diffs
  - Action item status changes with timestamps
  - Data exports and downloads
- [ ] **Legal Hold** - Prevent deletion of specific meetings
  - Admin can place/remove legal holds
  - Held meetings bypass retention policies
  - Audit log tracks hold placement/removal
- [ ] **Data Retention Policies**
  - Configurable retention per data type (audio, transcripts, minutes)
  - Automatic purge after retention period
  - Grace period before permanent deletion
  - Retention policy per tenant
- [ ] **Data Export**
  - Full tenant data export (GDPR Right of Access)
  - Meeting-specific export (all related data as ZIP)
  - Export format: JSON + PDF + audio files
  - Export request audit logging
- [ ] **E-Discovery Support**
  - Cross-meeting search (full-text search across all transcripts)
  - Date range filtering
  - Participant filtering
  - Export search results
- [ ] **Compliance Reports**
  - GDPR compliance summary
  - Access frequency reports
  - Data retention compliance
  - User permission audit

**Certifications Roadmap (post-implementation):**
- SOC 2 Type II - $15,000-25,000/year
- HIPAA BAA - Healthcare market requirement
- ISO 27001 - International enterprise standard

**Acceptance Criteria:**
- Full audit trail for all meeting data access
- Legal hold prevents any deletion
- Data export includes all tenant data
- Cross-meeting search returns relevant results

---

### T09.6 - White-Label Support (Week 20)

**Effort:** 2 days
**Dependencies:** MVP complete

- [ ] Custom branding settings per tenant:
  - Logo (header and PDF)
  - Primary/secondary colors
  - Custom email sender name
  - Custom domain for meeting links
- [ ] Branded PDF minutes templates
- [ ] Branded email templates
- [ ] Remove product branding for white-label tenants
- [ ] Custom subdomain or CNAME support (leverage existing tenant domain system)

**Acceptance Criteria:**
- Enterprise tenants can fully brand the experience
- PDFs and emails show tenant branding, not product branding
- Custom domains work for meeting join links

---

## Feature Gating

All Phase 3 features are Enterprise-only unless noted:

| Feature | Starter | Professional | Business | Enterprise |
|---|---|---|---|---|
| Live captions | No | No | No | Yes |
| Google Calendar | No | Yes | Yes | Yes |
| Outlook Calendar | No | Yes | Yes | Yes |
| PM integrations | No | No | Yes | Yes |
| Slack/Teams | No | No | Yes | Yes |
| Generic webhooks | No | No | Yes | Yes |
| Compliance suite | No | No | No | Yes |
| White-label | No | No | No | Yes |

---

## Testing Requirements

- [ ] Integration test: Calendar OAuth flow (mocked providers)
- [ ] Integration test: PM tool task sync (mocked APIs)
- [ ] Feature test: Slack message posting
- [ ] Feature test: Legal hold enforcement
- [ ] Feature test: Data export completeness
- [ ] Feature test: Cross-meeting search accuracy
- [ ] Feature test: White-label branding application
- [ ] Performance test: Live caption latency (<2s)
