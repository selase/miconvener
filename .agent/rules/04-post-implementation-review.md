---
trigger: always_on
---

# Mandatory Post-Implementation Self-Review

description: Enforces a self-review audit after every non-trivial implementation.

instructions:

  - **After completing any non-trivial implementation** (3+ files changed, new service/controller/component, code generation, pipeline changes), you MUST run the `self-review` workflow BEFORE declaring the work complete, committing, or moving to the next phase.
  - **Do NOT skip this step.** Do NOT ask the user "should I run the review?" — just run it. The review is part of the implementation, not an optional add-on.
  - **Trivial changes are exempt:** typo fixes, config changes, single-line bug fixes, comment updates.
  - **Fix all MUST FIX and SHOULD FIX items** identified by the review before proceeding. Present GOOD TO KNOW items to the user for awareness.
  - **If the review finds zero issues**, state that explicitly — a clean review is a valid outcome.
  - **The review workflow is at:** `.agent/workflows/self-review.md`
