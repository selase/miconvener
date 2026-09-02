# Audio Processing Specification

## Overview

This spec covers two audio processing challenges:
1. **Noise Cancellation** - Clean audio before transcription (improves accuracy, reduces costs)
2. **Speaker Identification** - Identify who is speaking from a shared microphone (Phase 2 room capture)

---

## 1. Noise Cancellation

### Why It Matters

Phones in meeting rooms pick up: HVAC fans, keyboard typing, paper rustling, other conversations, door sounds, phone vibrations. AWS Transcribe accuracy drops significantly with noisy audio, leading to more AI cleanup tokens and worse action item extraction.

### MVP Recommendation: RNNoise (Client-Side WASM)

**Package:** `@jitsi/rnnoise-wasm`
**Model size:** 85 KB
**Cost:** Free (open source, BSD-like license)
**Runs:** Entirely on participant's phone (AudioWorklet)
**Used by:** Jitsi Meet in production

**How it works:**
1. `getUserMedia()` captures microphone audio
2. Audio routed through Web Audio API pipeline
3. RNNoise AudioWorklet processes each 10ms frame
4. 3 GRU (Gated Recurrent Unit) neural network layers
5. Outputs per-band gain values (suppress noise, keep speech)
6. Also outputs Voice Activity Detection (VAD) probability
7. Clean audio passed to MediaRecorder for chunking

**Integration:**
```javascript
// Alpine.js component in MeetingRoom Livewire view
const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
const audioContext = new AudioContext({ sampleRate: 16000 });
const source = audioContext.createMediaStreamSource(stream);

// Load RNNoise AudioWorklet
await audioContext.audioWorklet.addModule('/js/rnnoise-processor.js');
const rnnoise = new AudioWorkletNode(audioContext, 'rnnoise-processor');

// Clean audio pipeline
const destination = audioContext.createMediaStreamDestination();
source.connect(rnnoise).connect(destination);

// Record the clean stream
const recorder = new MediaRecorder(destination.stream, {
    mimeType: 'audio/webm;codecs=opus'
});
```

**Benefits:**
- Zero server cost (runs on phone)
- Reduces upload bandwidth (cleaner audio compresses better)
- Improves AWS Transcribe accuracy
- VAD output can skip silent chunks (save transcription cost)

### Upgrade Path

If RNNoise quality is insufficient:

| Solution | Quality | Cost | Complexity |
|---|---|---|---|
| **RNNoise** (MVP) | Good | Free | Low |
| **dtln-rs** (Datadog) | Better | Free (open source) | Medium |
| **Picovoice Koala** | Best (commercial) | Free tier 100 min/mo, then paid | Low |
| **Krisp JS SDK** | Enterprise-grade | Custom pricing (contact sales) | Low |

### Server-Side Option (Not Recommended for MVP)

Running noise cancellation in Lambda before Transcribe adds latency, cost, and complexity. Since the phone is the capture device, cleaning at the source is optimal. Server-side processing only makes sense for room-capture mode (Phase 2) where a single device captures the entire room.

---

## 2. Speaker Identification

### Why Push-to-Talk Eliminates This Problem (MVP)

In push-to-talk mode, each participant records on their own phone. The audio stream is tagged with their `participant_id` at upload time. **Speaker identification is inherently solved** - we know exactly who pressed the button.

Speaker identification only becomes relevant in:
- **Room capture mode** (Phase 2) - one device records multiple speakers
- **Open mic mode** - phones pick up cross-talk from nearby participants
- **Verification** - confirm the right person is on the expected phone

### Phase 2: Room Capture Speaker Identification

When the secretary's device records ambient room audio, we need to identify which participant is speaking at any given moment.

**Recommended: SpeechBrain ECAPA-TDNN**

| Attribute | Value |
|---|---|
| **Model** | `speechbrain/spkrec-ecapa-voxceleb` |
| **Accuracy** | EER 0.86% on VoxCeleb1 (state-of-the-art open source) |
| **License** | Apache 2.0 |
| **Runs on** | Python (Lambda or ECS container) |
| **Enrollment needed** | 20-30 seconds of speech per participant |
| **Output** | 192-dimensional embedding vector |
| **Comparison** | Cosine similarity (threshold ~0.25-0.35) |

**Enrollment Flow:**
1. During meeting join, participant reads a standard phrase for 20-30 seconds
2. RNNoise cleans the enrollment audio (critical - noisy enrollment = bad embeddings)
3. Audio uploaded to S3 (`enrollments/{tenant}/{user}/`)
4. Lambda extracts embedding via SpeechBrain ECAPA-TDNN
5. Embedding vector stored in database (192 floats = ~768 bytes)

**Runtime Identification:**
1. Secretary device records room audio
2. Audio uploaded to S3 as chunks
3. Lambda segments audio by voice activity
4. For each segment, extract embedding
5. Compare against all enrolled participant embeddings (cosine similarity)
6. Assign speaker label (or mark as "unattributed" if confidence < threshold)
7. Pass labeled segments to transcription pipeline

### Accuracy Expectations

| Enrollment Duration | Clean Audio Accuracy | Noisy Environment |
|---|---|---|
| 10 seconds | ~89-93% | ~80-85% |
| 20 seconds | ~93-96% | ~87-92% |
| 30 seconds | ~96-99% | ~90-95% |

**Critical insight:** Noise cancellation on the enrollment audio is essential. A clean 15-second enrollment outperforms a noisy 30-second one.

### Alternative Solutions Evaluated

| Solution | Type | Status | Why Not MVP |
|---|---|---|---|
| **Amazon Connect Voice ID** | Managed speaker verification | **Discontinued May 2026** | End-of-life, no new customers |
| **AWS Transcribe Diarization** | Speaker separation (unnamed) | Active | Labels speakers as "spk_0", "spk_1" - no names, no enrollment |
| **Resemblyzer** | Open source embeddings | Active | Older model (2019), less accurate than ECAPA-TDNN |
| **pyannote-audio** | Open source diarization | Active | Diarization only (open source), identification requires commercial pyannoteAI |
| **Picovoice Eagle** | Commercial on-device | Active | Cost, and we don't need on-device for room capture |

### Speaker Verification vs Speaker Identification

| | Verification (1:1) | Identification (1:N) |
|---|---|---|
| **Question** | "Is this John?" | "Who is speaking?" |
| **Speed** | Fast (one comparison) | Slower (N comparisons) |
| **Use case** | Auth, gate-keeping | Meeting transcription labeling |
| **What we need** | No | **Yes** |

---

## 3. Implementation Phases

### MVP (Tracks 01-07)
- Client-side RNNoise for noise cancellation
- No speaker identification needed (push-to-talk = known speaker)
- VAD from RNNoise can skip silent chunks (cost savings)

### Phase 2 (Track 08)
- Voice enrollment during meeting join (20-30 sec)
- SpeechBrain ECAPA-TDNN in Lambda for embedding extraction
- Speaker identification for room-capture segments
- Unattributed segment review UI for manual fallback

### Phase 3 (Track 09)
- Real-time speaker labeling for live captions
- Streaming noise cancellation for live caption audio
- Voice enrollment persistence across meetings (user profile level)

---

## 4. Cost Impact

| Component | Phase | Per-Meeting Cost | Notes |
|---|---|---|---|
| RNNoise (client-side) | MVP | $0.00 | Runs on phone |
| SpeechBrain Lambda (enrollment) | Phase 2 | ~$0.02 | One invocation per participant |
| SpeechBrain Lambda (identification) | Phase 2 | ~$0.10 | Per room-capture segment |
| Additional Transcribe (room capture) | Phase 2 | ~$2.40 | Full meeting duration, additional stream |

---

## 5. Package Dependencies

### JavaScript (npm - in main project)
```
@jitsi/rnnoise-wasm    # Noise cancellation WASM module
```

### Python (Lambda - separate deployment)
```
speechbrain            # Speaker embedding extraction (Phase 2)
torch                  # PyTorch runtime for SpeechBrain (Phase 2)
torchaudio             # Audio processing for SpeechBrain (Phase 2)
```

> **Note:** SpeechBrain + PyTorch is ~1.5 GB. For Lambda, use a container image (not ZIP deployment). Alternatively, use a lightweight ONNX-exported model (~50 MB) for faster cold starts.
