# Track 05: AI Processing Pipeline

**Phase:** MVP (Week 6)
**Priority:** Critical
**Dependencies:** Track 04 (Transcription)
**Status:** Not Started

---

## Objective

Use AI (Claude via AWS Bedrock) to clean up transcripts, extract action items, and generate professional meeting minutes. Leverage the existing LLM token management system for cost tracking.

---

## Tasks

### T05.1 - AI Provider Interface & Bedrock Implementation

**Effort:** 1.5 days
**Dependencies:** T01.2

- [ ] Create `AIProvider` interface in `app/Services/AI/`:
  ```php
  interface AIProvider
  {
      public function cleanupTranscript(string $rawText, array $options = []): string;
      public function extractActionItems(string $transcript, array $participants): array;
      public function generateMinutes(string $transcript, array $metadata): string;
  }
  ```
- [ ] Create `BedrockProvider` implementing the interface
- [ ] Configure AWS Bedrock client with Laravel config
- [ ] Use Claude Haiku for cleanup and extraction (fast, cheap)
- [ ] Use Claude Sonnet for minutes generation (better quality)
- [ ] Record token usage via existing `LlmUsageService`
- [ ] Add config values to `config/llm.php`:
  - `llm.bedrock.region`
  - `llm.bedrock.cleanup_model` (claude-haiku)
  - `llm.bedrock.extraction_model` (claude-haiku)
  - `llm.bedrock.minutes_model` (claude-sonnet)
- [ ] Handle rate limiting and retries for Bedrock API

**Acceptance Criteria:**
- Provider correctly calls Bedrock API
- Token usage tracked per tenant
- Errors handled gracefully (fallback to raw transcript)
- Config allows swapping models without code changes

---

### T05.2 - Prompt Templates

**Effort:** 1 day
**Dependencies:** T05.1

- [ ] Create `PromptTemplates` class with versioned prompts
- [ ] **Transcript Cleanup Prompt:**
  - Remove filler words (um, uh, like, you know)
  - Fix grammar and punctuation
  - Organize into paragraphs by speaker turn
  - PRESERVE original meaning (do not summarize or rephrase)
  - Handle domain-specific terms carefully
- [ ] **Action Item Extraction Prompt:**
  - Return strict JSON format
  - Extract: title, description, assignee_name, due_date, priority, source_quote
  - Be conservative (only extract clear commitments)
  - Handle ambiguous assignments gracefully
  - Match assignees to provided participant list
- [ ] **Minutes Generation Prompt:**
  - Professional tone, clear structure
  - Sections: Header, Attendees, Executive Summary, Discussion Points, Decisions Made, Action Items, Next Steps
  - Output as Markdown
  - Include action items table with owners and due dates
- [ ] Store prompts as versioned Blade templates or config for easy iteration

**Acceptance Criteria:**
- Cleanup preserves meaning while improving readability
- Extraction returns valid JSON with correct schema
- Minutes are professional and well-structured
- Prompts are easy to modify without code deploys

---

### T05.3 - Transcript Cleanup Job

**Effort:** 1 day
**Dependencies:** T05.1, T05.2

- [ ] Create `CleanupTranscriptJob`
- [ ] Fetch aggregated raw transcript from meeting
- [ ] Call `AIProvider::cleanupTranscript()`
- [ ] Store cleaned text in `transcript_segments.cleaned_text`
- [ ] Handle large transcripts by chunking (max ~8K tokens per call)
- [ ] Maintain speaker attribution across chunks
- [ ] Update meeting processing status
- [ ] Dispatch `ExtractActionItemsJob` on completion

**Acceptance Criteria:**
- Cleaned transcript is more readable than raw
- Speaker attribution preserved
- Large meetings (2+ hours) handled via chunking
- Token usage recorded for billing

---

### T05.4 - Action Item Extraction Job

**Effort:** 1.5 days
**Dependencies:** T05.1, T05.2

- [ ] Create `ExtractActionItemsJob`
- [ ] Fetch cleaned transcript
- [ ] Call `AIProvider::extractActionItems()` with participant list
- [ ] Parse JSON response and validate schema
- [ ] Create `ActionItem` records for each extracted item
- [ ] Match assignee names to `MeetingParticipant` records (fuzzy match)
- [ ] Link to source `TranscriptSegment` via text matching
- [ ] Set default due dates (1 week from meeting) if not specified
- [ ] Handle extraction failures gracefully (log, don't block pipeline)
- [ ] Dispatch `GenerateMeetingMinutesJob` on completion

**Acceptance Criteria:**
- Action items created with correct assignees
- Source quotes traceable to transcript segments
- Invalid JSON from AI handled gracefully
- Zero action items is a valid outcome (not an error)

---

### T05.5 - Meeting Minutes Generation Job

**Effort:** 1.5 days
**Dependencies:** T05.3, T05.4

- [ ] Create `GenerateMeetingMinutesJob`
- [ ] Prepare context: meeting metadata, cleaned transcript, action items, participant list
- [ ] Call `AIProvider::generateMinutes()` with full context
- [ ] Parse Markdown response
- [ ] Create `MeetingMinutes` record (version 1, status: draft)
- [ ] Generate HTML from Markdown for preview
- [ ] Generate PDF via DomPDF and upload to S3
- [ ] Notify secretary/organizer that minutes are ready for review
- [ ] Dispatch `DistributeTasksJob` on completion

**Acceptance Criteria:**
- Minutes are professional and comprehensive
- PDF renders correctly with proper formatting
- Secretary receives notification to review
- Multiple minute versions supported (regeneration possible)

---

### T05.6 - Action Item Management (Livewire)

**Effort:** 1 day
**Dependencies:** T05.4

- [ ] Create `ActionItemList` Livewire component
- [ ] Display extracted action items grouped by meeting
- [ ] Edit action items (title, assignee, due date, priority, status)
- [ ] Mark items as complete
- [ ] Filter by status (pending, in_progress, completed, overdue)
- [ ] "My Tasks" view for logged-in user
- [ ] Bulk status update

**Acceptance Criteria:**
- Action items editable by organizer and assignee
- Status changes reflected immediately
- Overdue items highlighted
- Filter and sort work correctly

---

## Testing Requirements

- [ ] Unit test: BedrockProvider with mocked AWS SDK
- [ ] Unit test: Prompt template rendering
- [ ] Unit test: Action item JSON parsing and validation
- [ ] Unit test: Assignee fuzzy matching logic
- [ ] Feature test: Cleanup job execution (mocked AI)
- [ ] Feature test: Extraction job with valid/invalid AI responses
- [ ] Feature test: Minutes generation job
- [ ] Feature test: PDF generation from minutes
- [ ] Integration test: Full pipeline (ProcessMeetingJob -> all downstream jobs)
