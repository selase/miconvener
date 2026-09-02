# Meeting Minutes & Action Tracker - Project Skills

## Overview

This folder contains the structured implementation plan for building a Meeting Minutes & Action Tracker SaaS on top of the existing multi-tenant starter kit. The project is organized into **specifications** (what to build), **tracks** (how to build it), and **tasks** (individual work items).

---

## Project Summary

**Product:** Meeting transcription and action tracking for in-person meetings
**Innovation:** Phones as microphones (push-to-talk) instead of expensive room systems
**Cost per meeting:** ~$5.68 (AWS Transcribe dominant at 76%)
**Break-even:** 10 Professional customers ($199/mo)

---

## What the Starter Kit Already Provides

The existing codebase handles all SaaS infrastructure. **Do not rebuild these:**

- Multi-tenancy (shared/per-tenant DB isolation)
- Authentication (Breeze + Sanctum + 2FA)
- Authorization (Spatie roles/permissions + entitlements)
- Billing (Stripe + Paystack subscriptions)
- Usage metering (events, rollups, limits, alerts)
- Feature flags (boolean + metered with middleware gates)
- API key management (encrypted, scoped, IP-restricted)
- Webhooks (endpoints, delivery, retry)
- LLM token management (quota, BYOK, cost tracking)
- Admin dashboard, audit logging, health monitoring

---

## Specifications

| Spec                                                    | Description                                                                  |
| ------------------------------------------------------- | ---------------------------------------------------------------------------- |
| [Architecture](specs/architecture.md)                   | System architecture, starter kit mapping, key decisions, directory structure |
| [Database](specs/database.md)                           | 10 new tables, views, relationships, migration order                         |
| [Cost Model](specs/cost-model.md)                       | Per-meeting costs, pricing tiers, break-even analysis                        |
| [Models & AWS Inventory](specs/models-aws-inventory.md) | Complete list of all models and AWS services used                            |
| [Audio Processing](specs/audio-processing.md)           | Noise cancellation, voice recognition, and speaker identification            |
| [NativePHP Mobile Plan](specs/nativephp-mobile-plan.md) | Complete mobile app development plan for `xdata-audition-mobile/`            |

---

## Skills (Reusable Agent Specifications)

| Skill                                               | Description                                                                  |
| --------------------------------------------------- | ---------------------------------------------------------------------------- |
| [NativePHP Mobile Skill](nativephp-mobile-skill.md) | Self-contained reference for building NativePHP Mobile v3 apps (any project) |

---

## Tracks

### Phase 1: MVP (Weeks 1-8)

| #   | Track                                                   | Tasks   | Effort    | Status      |
| --- | ------------------------------------------------------- | ------- | --------- | ----------- |
| 01  | [Foundation & Infrastructure](tracks/01-foundation.md)  | 6 tasks | ~4 days   | Not Started |
| 02  | [Meeting Management Core](tracks/02-meeting-core.md)    | 5 tasks | ~6.5 days | Not Started |
| 03  | [Audio Recording Pipeline](tracks/03-audio-pipeline.md) | 6 tasks | ~6.5 days | Not Started |
| 04  | [Transcription Pipeline](tracks/04-transcription.md)    | 6 tasks | ~6 days   | Not Started |
| 05  | [AI Processing](tracks/05-ai-processing.md)             | 6 tasks | ~7.5 days | Not Started |
| 06  | [Task Distribution](tracks/06-task-distribution.md)     | 5 tasks | ~5 days   | Not Started |
| 07  | [Testing & Launch](tracks/07-testing-launch.md)         | 7 tasks | ~8 days   | Not Started |

**MVP Total: 41 tasks, ~43.5 dev-days**

### Phase 2: "Feels Magical" (Weeks 9-14)

| #   | Track                                                    | Tasks   | Effort   | Status      |
| --- | -------------------------------------------------------- | ------- | -------- | ----------- |
| 08  | [Phase 2 Enhancements](tracks/08-phase2-enhancements.md) | 5 tasks | ~15 days | Not Started |

### Phase 3: Enterprise (Weeks 15-20)

| #   | Track                                                 | Tasks   | Effort   | Status      |
| --- | ----------------------------------------------------- | ------- | -------- | ----------- |
| 09  | [Enterprise Features](tracks/09-phase3-enterprise.md) | 6 tasks | ~22 days | Not Started |

**Grand Total: 52 tasks across 9 tracks**

---

## Critical Path

The MVP critical path (tasks that block everything downstream):

```
T01.1 Database Migrations
  └── T01.2 Models & Relationships
        ├── T02.1 Meeting CRUD
        │     └── T02.3 Meeting Lifecycle
        │           └── T04.4 Transcript Aggregator
        │                 └── T05.3 Cleanup Job
        │                       └── T05.4 Extraction Job
        │                             └── T05.5 Minutes Generation
        │                                   └── T06.1 Task Distribution
        └── T03.1 S3 Pre-Signed URLs
              └── T03.3 Push-to-Talk UI
                    └── T04.2 Audio Processing Job
                          └── T04.3 Transcription Completion
```

---

## Parallel Work Streams

These tracks can progress in parallel once Track 01 is complete:

```
Week 1-2: Track 01 (Foundation) ─────────────────────────────
          Track 02 (Meeting Core) ───────────────────────────

Week 3-4: Track 03 (Audio Pipeline) ────────────────────────
          Track 02 (Dashboard UI) ──────────────────────────

Week 5:   Track 04 (Transcription) ─────────────────────────

Week 6:   Track 05 (AI Processing) ─────────────────────────
          Track 06 (Task Distribution) ─────────────────────

Week 7-8: Track 07 (Testing & Launch) ──────────────────────
```

---

## Key Architectural Decisions

1. **Frontend:** Livewire for dashboard, Livewire+Alpine PWA for meeting room (needs offline)
2. **Mobile:** Separate NativePHP project (`xdata-audition-mobile/`) - NOT bundled with backend (security + bloat)
3. **AI:** AWS Bedrock (Claude) with provider interface for swappability
4. **Speaker ID:** Session-based (push-to-talk = 100% accurate), not AI diarization
5. **Noise cancellation:** RNNoise WASM client-side (free, 85KB, runs on phone)
6. **Recording default:** Push-to-talk (67% cheaper than open mic)
7. **Real-time:** Laravel Reverb WebSocket for meeting events
8. **Feature gating:** Existing feature flag system with new meeting features registered
9. **Voice recognition:** Phase 2 only (SpeechBrain ECAPA-TDNN for room-capture speaker ID)

---

## How to Use This

1. **Start with specs/** - Understand architecture and database design
2. **Work through tracks sequentially** - Track 01 must be first, then 02-06 can overlap
3. **Each task has acceptance criteria** - Don't mark complete until criteria met
4. **Every change needs tests** - Testing requirements listed per track
5. **Run `vendor/bin/pint --dirty`** before committing any PHP changes
