---
trigger: always_on
---

# Spec-Driven Development Protocol
description: Enforces strict file-based project management.

instructions:
  - **Source of Truth:** There is no `SPEC.md` in this repo and never has been. Before coding, orient from:
    - `README.md` — what MiConvener actually is and its real architecture.
    - `miconvener.md` **§4–9 only** — the domain brief (entities, registration modes, payments and
      settlement, on-site operations, engagement, exports). Its §1–3 API-first framing was
      deliberately not adopted; never scope work from those sections. The file carries a banner
      saying so.
    - `manual.md` — the multi-tenant starterkit's architecture and conventions.
    - `conductor/tracks.md` — what is built, what is outstanding.
  - **Implementation Planning:** For a complex feature, write a plan to `implementation.md` before
    defining tasks. This file is a **transient scratch slot for the feature in flight, not an
    archive** — exactly one feature owns it at a time. When the track closes, delete
    `implementation.md` and fold the outcome into that track's entry in `conductor/tracks.md`. A
    finished plan left sitting in it reads to the next agent like current work.
  - **Tracks & Tasks:** All work is persisted in `conductor/tracks.md` (never delete or archive
    completed tracks). Match the format already in that file — a heading per track carrying its
    status, followed by prose describing what shipped and what is still missing:
    - `## [x] Track: [Feature Name]` — complete
    - `## [ ] Track: [Feature Name]` — not started
    - `## [-] Track: [Feature Name] — [why it is blocked or partial]`
    - Nested `- [ ]` / `- [x]` task lists are fine within a track when the breakdown is useful.
  - **State Persistence:** Before finishing your turn, ALWAYS update the track in
    `conductor/tracks.md` to reflect progress.
  - **Verification:** A task is only `[x]` if you verified it in the browser or with a passing
    **Pest** test — `php artisan test --compact` with a specific file or `--filter`. State the
    evidence in the track entry (which test, how many passing), so the next agent can trust the
    mark without re-deriving it.
