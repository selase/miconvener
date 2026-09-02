# Track 03: Audio Recording & Upload Pipeline

**Phase:** MVP (Weeks 3-4)
**Priority:** Critical
**Dependencies:** Track 01 (Foundation), Track 02 (Meeting Core)
**Status:** Not Started

---

## Objective

Enable participants to record audio via push-to-talk on their phones, upload chunks directly to S3, and handle offline scenarios gracefully.

---

## Tasks

### T03.1 - S3 Pre-Signed URL Generation

**Effort:** 0.5 days
**Dependencies:** T01.4

- [ ] Create `AudioController` with `getUploadUrl()` method
- [ ] Generate pre-signed S3 PUT URLs (5-minute expiry)
- [ ] S3 key format: `{tenant_id}/{meeting_id}/{participant_id}/{timestamp}_{chunk_index}.webm`
- [ ] Validate participant is in active meeting before generating URL
- [ ] Return upload URL + S3 key in response

**Acceptance Criteria:**
- Pre-signed URLs work for direct browser PUT upload
- URLs expire after 5 minutes
- S3 keys are unique and well-organized by tenant/meeting

---

### T03.2 - Audio Upload Confirmation

**Effort:** 0.5 days
**Dependencies:** T03.1

- [ ] Create `POST /api/meetings/{id}/audio-confirm` endpoint
- [ ] Accept: s3_key, chunk_index, duration_seconds, recorded_at
- [ ] Create `AudioSegment` record in database
- [ ] Validate S3 object exists before confirming
- [ ] Dispatch `ProcessAudioSegmentJob` to queue
- [ ] Broadcast audio upload event to meeting channel (real-time indicator)

**Acceptance Criteria:**
- Only confirmed uploads create database records
- Invalid S3 keys rejected
- Job dispatched for each confirmed upload

---

### T03.3 - Push-to-Talk Recording UI (Livewire + Alpine.js PWA)

**Effort:** 2.5 days
**Dependencies:** T03.1, T03.2

This is the core meeting room interface. Built as a Livewire component with Alpine.js handling the browser audio APIs. Served as a PWA-capable page (add-to-home-screen manifest).

- [ ] `MeetingRoom` Livewire component with Alpine.js `x-data` for audio logic
- [ ] Push-to-talk button with press-and-hold interaction (touchstart/touchend + mousedown/mouseup)
- [ ] Visual recording indicator (waveform or pulsing animation via Alpine.js)
- [ ] Audio capture using MediaRecorder API (WebM/Opus format)
- [ ] **Client-side noise cancellation** via RNNoise WASM (`@jitsi/rnnoise-wasm`):
  - AudioWorklet processes audio through RNNoise before recording
  - 85 KB model, runs entirely on the phone, zero server cost
  - Removes background noise, keyboard typing, fans, etc.
- [ ] Chunk audio into 30-second segments during long recordings
- [ ] Display recording duration counter
- [ ] Upload each chunk to S3 via pre-signed URL
- [ ] Show upload progress per chunk
- [ ] Retry failed uploads with exponential backoff
- [ ] PWA manifest for "Add to Home Screen" on mobile

**Acceptance Criteria:**
- Recording starts immediately on button press
- Audio quality: 16kHz mono (optimized for speech), noise-cancelled
- Chunks upload while recording continues
- Visual feedback throughout (recording, uploading, success, error)
- Works on iOS Safari, Chrome Android, desktop Chrome
- "Add to Home Screen" provides app-like experience on mobile

---

### T03.4 - Offline Audio Buffering

**Effort:** 1.5 days
**Dependencies:** T03.3

- [ ] Detect offline state using navigator.onLine + connectivity check
- [ ] Store audio chunks in IndexedDB when offline
- [ ] Queue upload confirmations for offline chunks
- [ ] Auto-sync when connection restored
- [ ] Display offline indicator in UI
- [ ] Show queued chunk count waiting for upload
- [ ] Handle partial uploads (resume from last successful chunk)

**Acceptance Criteria:**
- Audio recording works without internet connection
- All queued chunks upload when connectivity returns
- No data loss during offline periods
- User informed of offline state and pending uploads

---

### T03.5 - Voice Enrollment (Phase 2 - Room Capture Prerequisite)

**Effort:** 1 day
**Dependencies:** T03.3

Collect voice samples for speaker identification during room-capture mode (Phase 2). Not needed for MVP push-to-talk since each phone = one known speaker.

- [ ] 20-30 second voice sample recording during meeting join
- [ ] Apply RNNoise to enrollment audio (clean sample = better embeddings)
- [ ] Upload voice sample to S3 (separate path: `enrollments/{tenant}/{user}/`)
- [ ] Store enrollment URL on `MeetingParticipant`
- [ ] Server-side: extract speaker embedding via SpeechBrain ECAPA-TDNN (Lambda)
- [ ] Store embedding vector in database for Phase 2 room-capture matching

**Acceptance Criteria:**
- Enrollment completes in <30 seconds
- Can be skipped (not blocking meeting join)
- Voice sample stored per meeting (not persistent)
- Noise-cancelled before embedding extraction

---

### T03.6 - Meeting Room Real-time Features

**Effort:** 1 day
**Dependencies:** T03.3, T01.5

- [ ] WebSocket connection to meeting presence channel
- [ ] Show live participant list (who's in the meeting)
- [ ] Show who's currently recording (speaking indicator)
- [ ] Meeting timer (elapsed time since start)
- [ ] Handle WebSocket disconnection/reconnection gracefully
- [ ] Organizer controls: end meeting button

**Acceptance Criteria:**
- Participant list updates within 2 seconds
- Speaking indicators show in real-time
- Reconnection is automatic and seamless
- Meeting timer stays accurate across reconnections

---

## Testing Requirements

- [ ] Feature test: Pre-signed URL generation with auth
- [ ] Feature test: Audio upload confirmation flow
- [ ] Unit test: S3 key generation format
- [ ] Unit test: Offline buffer queue/dequeue (if testable server-side)
- [ ] Integration test: End-to-end upload to S3 (with localstack or mocked)
- [ ] Browser test: Push-to-talk recording (manual test plan)
