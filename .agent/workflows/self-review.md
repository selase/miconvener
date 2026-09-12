---
description: Post-implementation self-review — audits your own work for overlooked issues and suboptimal patterns before declaring a task complete.
---

# Post-Implementation Self-Review

description: Systematically audits completed implementation work for correctness, security, robustness, and optimality. Must be run after every non-trivial implementation before marking work as done.

## When to Trigger

Run this workflow after completing any implementation that involves:
- Creating or modifying service classes, controllers, or models
- Adding new API endpoints or routes
- Writing code that generates other code (Python, SQL, shell commands)
- Modifying execution pipelines (Lambda, queue jobs, background processes)
- Creating new React/frontend components
- Any change touching 3+ files

Do NOT run for trivial changes (typo fixes, comment updates, config tweaks).

## Phase 1: Static Analysis Pass

steps:
  - instruction: "Run the project's static analysis and linting tools to catch mechanical issues first. For this project: `npm run build`, PHPStan if PHP was changed, ESLint if JS was changed. Fix any failures before proceeding to manual review."

## Phase 2: Audit for Overlooked Issues

Launch 3 parallel review agents. Each agent must READ THE FULL FILE CONTENTS of every file it reviews — do not summarize or skim.

### Agent A: Security & Input Validation Review

steps:
  - instruction: >
      Read every file that was created or modified in this implementation. For each file, systematically check:

      **Injection vectors:**
      - Is any user-controlled input interpolated into SQL, Python, shell commands, or HTML without escaping/parameterization?
      - Are string values from request input, database fields, or file metadata used in code generation (exec, eval, template strings) without sanitization?
      - Could a malicious filename, column name, alias, or key value break out of its string context?

      **Authorization & access control:**
      - Does every new endpoint enforce the correct permission check?
      - Are tenant isolation boundaries maintained? Can user A's request access user B's resources?
      - Are route parameters validated (exist, belong to correct parent, correct tenant)?
      - **Boundary Gating vs. Public Route Leakage:** Do any public routes or file streamers (e.g., `/media/{path}`) expose directories or resources intended to be gated by authenticated or domain-specific controllers (e.g., `event-materials/`)? Does authorization check the requester's actual entitlement, not merely the syntactic shape of a path?

      **Database Constraints vs. Scoped Queries:**
      - When generating or validating uniqueness for reference codes, slugs, or tokens, check the database migration: if the database index is global, does the probe use `withoutGlobalScopes()`? A tenant-scoped query (`self::query()`) creates false assurance and causes 500 collision errors across tenants.
      - Are code generation loops bounded (e.g., max 10 attempts throwing a descriptive exception) rather than unbounded `while(true)`?

      **Input validation gaps:**
      - Are there request fields accepted but not validated?
      - Are array bounds checked before access (e.g., $sources[1] without verifying count >= 2)?
      - Are nullable/optional fields handled with proper defaults when missing?
      - Could empty arrays, null values, or missing keys cause exceptions?

      **Data integrity:**
      - Could concurrent requests create duplicate or inconsistent state?
      - Are database operations that should be atomic wrapped in transactions?
      - Could a record be deleted between validation and use (TOCTOU)?

      Report every finding with: file path, line number(s), severity (CRITICAL/HIGH/MEDIUM/LOW), concrete exploit or failure scenario, and suggested fix.

### Agent B: Logic, Correctness & Edge Cases Review

steps:
  - instruction: >
      Read every file that was created or modified in this implementation. For each file, systematically check:

      **Logic errors:**
      - Do conditional branches cover all cases? Are there silent fallbacks that mask errors (e.g., returning a default instead of throwing)?
      - Are loop invariants correct? Could a loop execute zero times and leave variables uninitialized?
      - Do array/collection operations handle empty inputs? (array_intersect with 0 args, array_column on empty array, ->first() on empty collection)
      - Are comparison operators correct? (=== vs ==, off-by-one, null coalescing chains)

      **Consistency across files:**
      - If the same operation is performed in multiple places (e.g., sanitizing a string, building a payload), is it done identically? Different sanitization or formatting in different files will cause mismatches at runtime.
      - If a method signature changed, are all callers updated?

      **Error handling:**
      - Are external calls (S3, HTTP, filesystem, database) wrapped in try-catch or null-checked?
      - Could a failed operation leave the system in a half-updated state?
      - Are error messages actionable, or do they swallow context?

      **Edge cases:**
      - What happens with the minimum valid input? (e.g., exactly 2 sources, 1-column schema, 0-row dataset)
      - What happens with unusual but valid input? (unicode strings, very long values, special characters)
      - What happens when referenced entities are missing? (deleted upload, missing metadata key)
      - For generated code: would the output actually execute correctly in the target runtime?

      **Temporal State, Billing & Quota Invariants:**
      - Are charges or usage records billed at the point of intent rather than actual delivery? Staged or queued actions must NOT debit paid balances or bill costs until actual external delivery occurs.
      - If a quota or counter was optimistically decremented prior to an external dispatch (e.g. email send), does the catch block explicitly refund/rollback the quota slot on failure (floored at 0)?
      - Is `sent_at` or a completion timestamp populated on staged or un-sent records? Staged records must have `sent_at = null`.
      - If `sent_at` is nulled on staged records, do anti-abuse, rate-limiting, or cooldown checks safely decouple and filter on `created_at` so throttling protections remain active?

      **Variable lifecycle:**
      - Are all variables defined before use in every code path?
      - Are temporary resources (files, streams, connections) cleaned up in ALL paths, including error paths? Use try-finally patterns.

      Report every finding with: file path, line number(s), severity, concrete failure scenario (exact input that triggers it), and suggested fix.

### Agent C: Performance, Architecture & Optimality Review

steps:
  - instruction: >
      Read every file that was created or modified in this implementation. For each file, systematically check:

      **Performance:**
      - Are there N+1 query patterns? (method that runs a query called inside a loop, or called multiple times when once would suffice)
      - Are large datasets loaded into memory unnecessarily? Could a query or stream be used instead?
      - Are there redundant computations? (same expensive operation done twice when the result could be cached in a variable)

      **Architecture & Dual-System Parity:**
      - Does the implementation follow the project's established patterns, or does it introduce inconsistencies?
      - Is code that's duplicated across files extracted into a shared method/service?
      - Are responsibilities correctly separated? (controllers thin, business logic in services, models for data access)
      - Are new dependencies between modules justified, or could the coupling be reduced?
      - **Companion Model / Dual-System Parity (Full Call-Site Grep):** When introducing or updating a companion system (e.g., a double-entry ledger alongside a legacy ledger, an audit log, or an event stream), perform a repository-wide grep for all existing writers/mutators of the domain entity. Are all code paths (direct checkout, webhooks, crons, manual actions) hooked into the new system with identical idempotency guards?

      **Optimality:**
      - Is this the simplest solution that solves the problem? Could any code be removed without losing functionality?
      - Are there unnecessary abstractions or over-engineering for the current requirements?
      - Are there existing project utilities or Laravel helpers that could replace custom code?
      - Could any synchronous operations be made async where appropriate?

      **Test coverage & Adversarial Negative Tests:**
      - Are all new public methods tested?
      - Do tests cover both happy path AND failure modes (invalid input, missing data, permission denied)?
      - **Adversarial Negative Tests:** For any feature that gates something, is there a test proving the gate holds against unauthorized, unconfirmed, or cross-tenant requests, not just that the door opens?
      - Are edge cases tested? (minimum inputs, boundary values, empty collections)
      - Are there test gaps — functionality that exists but has no corresponding test?
      - Do tests actually assert meaningful behavior, or do they just check "no exception thrown"?

      **Dead code:**
      - Are there methods defined in services that have no route, no caller, and no test? Flag them.
      - Are there imports that are unused?
      - Are there feature flags or conditional branches that can never be reached?

      Report every finding with: file path, line number(s), severity, rationale, and suggested improvement.

## Phase 3: Synthesize & Triage

steps:
  - instruction: >
      Collect findings from all three agents. Deduplicate overlapping issues.
      Discard false positives by checking each finding against reality:
      - Does the code path actually execute? (Don't flag dead code as a security issue.)
      - Is the "user-controlled input" actually controlled by the system? (S3 keys set by backend != user input.)
      - Does PHP's copy-on-write semantics invalidate a "shared reference" concern?
      - Is the "missing validation" actually caught by Laravel's request validation layer?

      Classify remaining findings into three tiers:

      **MUST FIX (blocks shipping):**
      - Will cause runtime errors, data corruption, or security vulnerabilities
      - Concrete failure scenario is reproducible

      **SHOULD FIX (do before next phase):**
      - Correctness issues that won't crash but produce wrong results in edge cases
      - Security hardening (defense in depth)
      - Missing error handling that would make debugging painful

      **GOOD TO KNOW (track for later):**
      - Performance improvements
      - Test coverage gaps for non-critical paths
      - UX polish
      - Architectural suggestions for future refactors

      Present the triaged list to the user with clear descriptions, then fix all MUST FIX and SHOULD FIX items.

## Phase 4: Verify Fixes

steps:
  - instruction: "After fixing all MUST FIX and SHOULD FIX items, run the full test suite for the affected area plus `npm run build` to confirm no regressions were introduced by the fixes themselves."
