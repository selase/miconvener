---
description: Scans the starter kit, explains architecture, and creates the initial spec.
---

# Project Initialization
description: Scans the starter kit, explains architecture, and creates the initial spec.

steps:
  - instruction: "Analyze the current file structure and summarize the installed starter-kit features in `PROJECT_STATUS.md`."
  - instruction: "Review `STARTERKIT.md` to ensure deep understanding of existing pre-built infrastructure. Acknowledge that you must NEVER rebuild the following: Multi-Tenancy System, Billing & Payment System, Usage Metering & Analytics, LLM Token Management, Feature Flags & Entitlements, API Key & Webhook Systems, and 2FA Authentication."
  - instruction: "Create a blank `SPEC.md` and ask the user: 'What is the big goal for this new project?'"
  - instruction: "Once the user replies, update `SPEC.md` focusing strictly on domain-specific features, explicitly noting how they integrate with the Starter Kit (e.g., utilizing `TenantContext`, `EntitlementService`, `UsageService`, `LlmUsageService`)."
  - instruction: "Create a technical planning document `implementation.md` detailing the technical approach for the new features."
  - instruction: "Using the `implementation.md` as a guide, generate a preliminary set of tasks in `conductor/tracks.md` following the SDD Protocol hierarchical format (Track -> Tasks)."
