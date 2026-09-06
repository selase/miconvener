---
trigger: always_on
---

# Agent Handoff Protocol
description: Standards for ensuring any new agent can resume work instantly.

instructions:
  - **There is no `STATE.md`.** Handoff state lives in two places that already exist, and both are
    read by `.agent/workflows/resume.md`:
    - `conductor/tracks.md` — the track you touched: its status, what shipped, what is still
      missing, and the verification evidence.
    - **The git history** — this repo's commit subjects are written as full sentences describing
      the behaviour that changed (`fix: stop reporting a payout as sent when the provider parked
      it awaiting an OTP`). Keep writing them that way; they are the narrative record.
  - **State Check:** Every time you finish a task, update that track entry with what was
    accomplished, any technical debt or gotcha discovered, and where the next agent should pick up.
    Prefer naming a file and symbol (`EventFinanceController::sendPayout`) over a line number —
    line numbers rot on the next edit.
  - **Durable gotchas go in the manual, not just the track.** A non-obvious trap that will bite the
    next person regardless of which track they are on belongs in `manual.md` §2.4 "Gotchas"
    alongside the existing ones.
  - **Context Hygiene:** If you hit 3 consecutive errors, stop. Write what you learned into the
    track entry and ask the user to start a fresh session rather than continuing to burn context.
