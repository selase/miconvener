---
trigger: always_on
---

# Agent Handoff Protocol
description: Standards for ensuring any new agent can resume work instantly.

instructions:
  - **State Check:** Every time you finish a task, update `STATE.md` with:
    - What was just accomplished.
    - Any new technical debt or "gotchas" discovered.
    - The exact file and line number where the next agent should start.
  - **Context Hygiene:** If you encounter 3 consecutive errors, perform a "State Dump" to `STATE.md` and ask the user to start a fresh session.
