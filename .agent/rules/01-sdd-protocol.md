---
trigger: always_on
---

# Spec-Driven Development Protocol
description: Enforces strict file-based project management.

instructions:
  - **Source of Truth:** You must NEVER start coding until `SPEC.md` reflects the current high-level goal.
  - **Implementation Planning:** For complex features, an `implementation.md` must be created to detail the technical approach before specific tasks are defined.
  - **Tracks & Tasks:** All work must be broken down and persisted in `conductor/tracks.md` (never delete or archive completed tracks) using the following hierarchy:
    - ## Track A: [Feature Name]
    - - [ ] Task 1
    - - [x] Task 2
    - - [-] Task 3 (Blocked reason)
  - **State Persistence:** Before finishing your turn, ALWAYS update the track in `conductor/tracks.md` to reflect progress (mark `[x]`).
  - **Verification:** A task is only `[x]` if you have verified it via the Browser or a passing **Pest PHP** Test.
