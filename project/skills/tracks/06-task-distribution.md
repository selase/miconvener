# Track 06: Task Distribution & Notifications

**Phase:** MVP (Week 6)
**Priority:** High
**Dependencies:** Track 05 (AI Processing)
**Status:** Not Started

---

## Objective

Distribute action items to assignees via email, schedule reminders, and provide a notification system for meeting events.

---

## Tasks

### T06.1 - Task Distribution Job

**Effort:** 1 day
**Dependencies:** T05.4

- [ ] Create `DistributeTasksJob` (final step in processing pipeline)
- [ ] Group action items by assignee
- [ ] Send personalized email to each assignee with their tasks
- [ ] Include meeting context (title, date, organizer)
- [ ] Include link to view full minutes
- [ ] Include link to mark tasks as complete
- [ ] Create `MeetingNotification` records for each distribution
- [ ] Handle unregistered participants (email-only, no app link)

**Acceptance Criteria:**
- Each assignee receives one email with all their tasks
- Email includes actionable links
- Non-user participants get a simplified email
- Distribution recorded in notification log

---

### T06.2 - Email Templates

**Effort:** 1 day
**Dependencies:** T06.1

Create Mailable classes with Blade templates:

- [ ] `MeetingInvitationMail` - Invitation with join link
- [ ] `MeetingStartedMail` - Meeting has begun notification
- [ ] `TaskAssignmentMail` - Your action items from meeting
- [ ] `TaskReminderMail` - Upcoming/overdue task reminder
- [ ] `MinutesReadyMail` - Minutes available for review
- [ ] `MinutesDistributedMail` - Approved minutes sent to all participants

**Each email should:**
- Use consistent branding from tenant settings
- Be mobile-responsive
- Include plain text alternative
- Have unsubscribe option

**Acceptance Criteria:**
- All emails render correctly in major clients (Gmail, Outlook)
- Links point to correct tenant URLs
- Tenant branding applied (name, logo if available)

---

### T06.3 - Task Reminder Scheduler

**Effort:** 1 day
**Dependencies:** T06.1

- [ ] Create `ScheduleTaskRemindersCommand` (runs daily via scheduler)
- [ ] For each pending action item with a due date:
  - Send reminder 3 days before due date
  - Send reminder 1 day before due date
  - Send overdue notification 1 day after due date
- [ ] Create `TaskReminder` records to prevent duplicate sends
- [ ] Check `TaskReminder.sent_at` before sending
- [ ] Register command in `app/Console/Kernel.php`

**Acceptance Criteria:**
- Reminders sent at correct intervals
- No duplicate reminders
- Completed/cancelled tasks skip reminders
- Command runs efficiently (batch queries)

---

### T06.4 - In-App Notifications

**Effort:** 1 day
**Dependencies:** T06.1

- [ ] Leverage Laravel's notification system
- [ ] Create notification bell/dropdown in tenant dashboard layout
- [ ] Show unread count badge
- [ ] Notification types:
  - Meeting invitation received
  - Meeting starting soon (15 min before)
  - New tasks assigned to you
  - Task reminder (approaching due date)
  - Minutes ready for review
- [ ] Mark as read functionality
- [ ] Mark all as read
- [ ] Link to relevant meeting/task from notification

**Acceptance Criteria:**
- Notifications appear in dashboard header
- Unread count updates without page refresh (Livewire poll)
- Clicking notification navigates to relevant page
- Old notifications auto-archive after 30 days

---

### T06.5 - Minutes Distribution Workflow

**Effort:** 1 day
**Dependencies:** T05.5, T06.2

- [ ] Secretary reviews generated minutes (edit if needed)
- [ ] "Approve & Distribute" button on minutes editor
- [ ] On approval:
  - Set `MeetingMinutes.status` to 'approved'
  - Set `approved_by` and `approved_at`
  - Send `MinutesDistributedMail` to all participants
  - Attach PDF or include download link
  - Update meeting status to 'completed'
- [ ] "Request Changes" option (keeps minutes in draft, notifies AI regeneration needed)

**Acceptance Criteria:**
- Only secretary/organizer can approve minutes
- All participants receive approved minutes
- PDF attachment or download link works
- Meeting marked complete after distribution

---

## Testing Requirements

- [ ] Feature test: Task distribution job sends correct emails
- [ ] Feature test: Email template rendering (no errors)
- [ ] Feature test: Reminder scheduler creates correct reminders
- [ ] Feature test: Minutes approval and distribution workflow
- [ ] Unit test: Reminder deduplication logic
- [ ] Unit test: Task grouping by assignee
- [ ] Mail test: All Mailable classes render without errors
