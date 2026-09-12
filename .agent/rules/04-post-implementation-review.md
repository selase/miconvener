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
  - **Adversarial Auditing Mindset:** Do not simply verify that the happy path works. Audit whether security and business boundaries hold against bypasses, cross-tenant leaks, and edge cases.
  - **Boundary & Negative Testing:** Ensure gated resources have tests proving unauthorized/unconfirmed requests are rejected (401/403/404) and public routes do not leak gated paths.
  - **Global Schema Constraints vs Scoped Queries:** Cross-reference uniqueness checks against database migrations (use `withoutGlobalScopes()` if the index is global).
  - **Full Call-Site Grep:** When touching dual/companion systems (e.g. ledgers, event streams), grep the entire codebase for all existing writers to ensure 100% parity across webhooks, crons, and direct flows.
  - **Temporal & Billing Invariants:** Never bill for staged intent, refund quotas on dispatch failure, never set delivery timestamps on staged rows, and decouple cooldowns from delivery timestamps.
  - **The review workflow is at:** `.agent/workflows/self-review.md`
