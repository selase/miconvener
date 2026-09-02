# Track 08: Phase 2 - "Feels Magical" Enhancements

**Phase:** Phase 2 (Weeks 9-14)
**Priority:** High (post-MVP)
**Dependencies:** MVP complete (Tracks 01-07)
**Status:** Not Started

---

## Objective

Add features that differentiate the product: secretary live markers, floor control, room capture fallback, advanced minutes editor, and custom vocabulary.

---

## Tasks

### T08.1 - Secretary Live Markers (Weeks 9-10)

**Effort:** 3 days
**Dependencies:** MVP complete

Enable the meeting secretary to place real-time markers during the meeting:

- [ ] Create marker placement API endpoint
- [ ] `POST /api/meetings/{id}/markers` - Place marker with type, label, timestamp
- [ ] Broadcast marker placement via WebSocket to all participants
- [ ] Link markers to nearest `TranscriptSegment` by timestamp
- [ ] Marker types: `decision`, `action`, `note`, `risk`, `parking_lot`, `important`
- [ ] Create marker toolbar UI component (PWA)
- [ ] Display markers on transcript timeline
- [ ] AI processing weights marked segments higher during extraction
- [ ] Update extraction prompt to use marker context

**Acceptance Criteria:**
- Secretary can place markers in <1 second
- All participants see markers in real-time
- Markers improve AI action item extraction accuracy
- Markers visible on transcript view with color coding

---

### T08.2 - Floor Control System (Weeks 9-10)

**Effort:** 3 days
**Dependencies:** MVP complete

Structured one-speaker-at-a-time mode:

- [ ] Add `floor_control` recording mode to meeting settings
- [ ] "Request Floor" button for participants
- [ ] Speaker queue management (FIFO with priority override)
- [ ] Visual queue display showing pending speakers
- [ ] Auto-release after configurable timeout (default: 5 minutes)
- [ ] Secretary/organizer override controls (grant/revoke floor)
- [ ] Only the participant with the floor can record
- [ ] WebSocket broadcasting for floor state changes
- [ ] Record floor control events for analytics

**Acceptance Criteria:**
- Only one participant can record at a time
- Queue displays correctly for all participants
- Auto-release prevents floor hogging
- Secretary can override at any time
- 80% cost reduction vs open mic mode

---

### T08.3 - Room Capture Fallback (Weeks 11-12)

**Effort:** 4 days
**Dependencies:** T08.1

When participants forget to press the button, capture room audio:

- [ ] Secretary device records ambient room audio
- [ ] Upload room audio segments to S3 (separate path)
- [ ] AWS Transcribe with speaker diarization enabled
- [ ] Create `TranscriptSegment` records with `is_attributed = false`
- [ ] Create "Unattributed Segments" review UI
- [ ] Audio playback per segment for identification
- [ ] Participant selector dropdown for manual assignment
- [ ] Bulk assignment tools
- [ ] Assigned segments merge into main transcript timeline

**Acceptance Criteria:**
- Room capture fills gaps in push-to-talk recording
- Unattributed segments clearly marked
- Secretary can efficiently assign speakers
- Merged transcript maintains correct chronological order

---

### T08.4 - Advanced Minutes Editor (Week 13)

**Effort:** 3 days
**Dependencies:** MVP complete

Rich editing experience for meeting minutes:

- [ ] Integrate TipTap editor (or similar rich text editor)
- [ ] Section templates: drag-and-drop reordering
- [ ] Track changes mode (show additions/deletions)
- [ ] Comment system for collaborative review
- [ ] Version history with diff view
- [ ] Approval workflow (draft -> review -> approved)
- [ ] Multiple PDF templates:
  - Standard (default)
  - Formal (board meetings)
  - Technical (engineering standups)
  - Casual (team retrospectives)
- [ ] Custom branding: tenant logo, colors, footer
- [ ] Export options: PDF, DOCX, HTML

**Acceptance Criteria:**
- Secretary can edit minutes with rich formatting
- Version history shows all changes
- Multiple templates produce professional output
- Tenant branding applied to all exports

---

### T08.5 - Custom Vocabulary (Week 14)

**Effort:** 2 days
**Dependencies:** T04.1

Improve transcription accuracy for domain-specific terms:

- [ ] Create vocabulary management UI (Livewire component)
- [ ] Add/edit/delete custom terms with:
  - `phrase` - the correct spelling
  - `sounds_like` - phonetic hints
  - `display_as` - preferred display format
- [ ] Upload vocabulary to AWS Transcribe as custom vocabulary
- [ ] Industry starter templates:
  - Medical (drug names, procedures)
  - Legal (case citations, terms)
  - Technology (product names, acronyms)
  - Finance (instruments, regulations)
- [ ] Apply vocabulary to all future transcription jobs
- [ ] Store per-tenant in `settings` JSON

**Acceptance Criteria:**
- Custom terms improve transcription accuracy
- Vocabulary syncs to AWS Transcribe
- Industry templates provide quick start
- Changes apply to future meetings only (no retroactive)

---

## Feature Gating

All Phase 2 features gated behind plan-level features:

| Feature | Starter | Professional | Business | Enterprise |
|---|---|---|---|---|
| Secretary markers | No | No | Yes | Yes |
| Floor control | No | No | Yes | Yes |
| Room capture | No | No | Yes | Yes |
| Advanced editor | No | Yes | Yes | Yes |
| Custom vocabulary | No | No | Yes | Yes |

---

## Testing Requirements

- [ ] Feature test: Marker placement and retrieval
- [ ] Feature test: Floor control state transitions
- [ ] Feature test: Room capture upload and assignment
- [ ] Feature test: Minutes editor versioning
- [ ] Feature test: Vocabulary CRUD and AWS sync
- [ ] Integration test: Markers influencing AI extraction
- [ ] WebSocket test: Real-time marker/floor broadcasts
