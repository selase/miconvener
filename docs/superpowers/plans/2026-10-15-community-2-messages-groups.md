# Community 2: Messages and groups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox tracking.

**Goal:** Let organisations maintain flat member groups and send accountable, consent-aware email/SMS announcements now or later, without changing event-specific records or dues histories.

**Architecture:** Community owns its groups, messages, recipients and channel preferences. Extract narrowly scoped audience normalization and transport services shared with Events; keep event selection, templates, receipts and tracking in their existing records. Use durable per-address delivery claims, bounded jobs and the existing prepaid-credit lock. Extend the organisation SMS limit to count messages and reminders together.

**Tech Stack:** Existing Laravel/PHP, PostgreSQL, Pest, Inertia/React, console components, Alpine where already used, existing mail/SMS adapters. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-10-15-community-design.md`, sections 2, 4, 8, 10, 13 and 13.1. Roadmap milestone 2. Baseline `a3880c2` on main, deployed; original Claude checkout remains on `community-1a`.

**Status:** Owner approved merge; PR27 merged as `b659e66` and automatically deployed2026-10-16 03:42:42 UTC. Exact tested/merged tree equality confirmed. Owner’s standing pilot approval applied2026-10-16: missing-only entitlement sync completed and milestone2 enabled for Purpledot alone. Future approved Community releases automatically enable Purpledot; no repeated activation request is required.

## Global Constraints

- "Shared services, not shared tables." Never make an Event model's `event_id` nullable.
- "No person table." Preferences identify one normalised channel address inside one tenant, never link email and phone transitively.
- "Groups are flat"; membership is many-to-many. Do not call groups Team, Leads or Contacts.
- "Re-evaluated at send time": scheduling stores a selector and estimate, not an irrevocable audience.
- "service messages ... and sign-in codes cannot" be switched off by marketing preferences. Free-form announcements are marketing; the composer cannot claim a service exemption.
- "SMS spending limit": one organisation-month budget for Community messages and reminders, in addition to prepaid allowance.
- Scheduling Starter+, sending to groups Growth+, groups themselves on every plan. Following is free, separate rollout, implemented in milestone 3.
- Existing `/pay/{token}`, callbacks, receipts, event blasts, contributions and `/my` access remain compatible.
- Keep uncertain reservations held; no blind refund or resend. Money remains integer minor units; no ledger writers or commission changes here.
- Every new permission belongs in PermissionsSeeder and RolePermissions; permission checks are server-side as well as UI.
- No production sends, activation, merge or deployment without the owner's applicable approval. Do not touch Claude's checkout or owner data for tests.
- Rebuild and commit `public/build` in the same commit as JS/CSS. Use `pint --dirty`. Tick steps in their implementation commits.

## Review Focus

1. A shared phone/email occurs in several members and later Followers: one generic message per address; no mixing personalised financial information.
2. Group removal, opt-out, contact edit, downgrade, permission revocation or cancellation after scheduling: recheck before transport, no stale access or spend.
3. Two workers race a reminder and a message for the last SMS credit/monthly slot: one reservation, no overspend, refund only its original sources.
4. Provider accepts but times out, or a worker dies after submission: show unknown, hold credits, never blindly replay; permit an audited operator resolution.
5. Existing Events use registration-specific tracking and service mail: incremental extraction must retain those contracts and every existing blast test.

## Decisions proposed for this plan's review

- Members: default audience is active members; pending, lapsed and exited appear as explicit status filters. Groups may contain any retained member but delivery always intersects the chosen status filter. Default group sends target active members.
- All composer messages are announcements. An arrears selector needs `read finance` and still respects marketing opt-outs; use the existing dues-reminder action for essential service notices. No free-form service-message bypass.
- Preferences default to permitted unless an organisation/address has opted out, matching the spec's marketing-off model. Membership import is not presented as consent evidence; composer requires staff acknowledgement of authority to contact the audience. No claim that this acknowledgement establishes consent under every jurisdiction. If explicit opt-in is required by the owner, change this policy before implementation, not silently during a task.
- Followers have no table yet. Define an audience-source contract and test overlap with a fake follower source; do not ship follower creation, join flows or pretend production follower support exists. Milestone 3 supplies the real source and its integration test.
- Group membership editing uses `manage members`; message history/compose uses `send community messages`. Reserved/unknown operator resolution uses application superadmin access and a required evidence/reason field, never tenant-controlled email.
- Message scheduling: accept organisation-local date/time plus explicit UTC offset, display timezone, store UTC. Reject invalid/nonexistent local times and require explicit offset for ambiguous times. Downgrades pause unsent scheduled/group messages, retaining them read-only; never silently convert a scheduled send to immediate.
- Per send, freeze selected addresses when the due job begins; before each delivery recheck eligibility for that address. New members arriving during execution are for the next message; removed recipients are skipped. This yields a bounded, auditable send.
- SMS limit remains the current application's credits-per-message model, not a new billing scheme. Enforce current SMS maximum length including unsubscribe link; block oversize text rather than silently truncating it.

## Current code map and boundaries

- `app/Models/EventBlast.php`: current event audiences and registration selection.
- `app/Jobs/Events/SendEventBlastJob.php`: registration/email tracking; SMS uses notification claims. Email is currently not replay-safe; scope its claim fix to this extraction.
- `app/Services/Dues/DuesReminders.php`: SMS reservation, exact refund, local-month usage and unknown outcomes. Keep its member/day guard.
- `app/Services/Billing/PrepaidCreditAllowance.php`: reservation receipts and tenant/credit advisory lock; reuse, do not duplicate balances.
- `app/Services/Notifications/NotificationGatewayService.php`: existing adapter/result and SMS length conventions.
- `app/Services/Community/MemberIdentity.php`: canonical member email/phone normalization; use address normalization, never its person-deduplication key for message dedupe.
- `resources/js/Layouts/consoleNav.js`, Community `People.jsx`, `Overview.jsx`: navigation and group/message entry points.
- `tests/Feature/Events/EventBlastTest.php`, `tests/Feature/Sms/BlastSmsTest.php`, `tests/Feature/Community/DuesRemindersTest.php`: compatibility gates after each relevant extraction.

## Task 1: Flat groups, permissions and entitlements

**Files:** Create `app/Models/CommunityGroup.php`, factory, landlord migration `create_community_groups`, `app/Http/Controllers/Tenant/CommunityGroupController.php`, `app/Http/Requests/Tenant/Community/StoreGroupRequest.php`, `UpdateGroupMembersRequest.php`, `app/Services/Community/MessageEntitlements.php`; modify Member relationships, PermissionsSeeder, RolePermissions, tenant routes; test `tests/Feature/Community/GroupsTest.php` and `MessageEntitlementsTest.php`.

**Schema:** UUID groups with tenant_id, name (max120), description (max1000), timestamps; unique tenant/name. Membership pivot tenant_id/group_id/member_id with unique group/member; cascade group deletion, preserve members/history. Every write checks both referenced records belong to current tenant, including bulk IDs. Archived groups retain history but cannot be selected for new messages.

**Interfaces:** `MessageEntitlements::canSchedule(Tenant): bool`, `canMessageGroups(Tenant): bool`; resolve explicit feature overrides first, otherwise standard plan fallback. Unknown/custom plans default off unless explicitly entitled; never fail open. `CommunityGroup::members(): BelongsToMany`; group management requires Community + `manage members`.

- [x] Write failing cross-tenant, duplicate-name, repeat attach, missing member, archive, authorization and Free/Starter/Growth/Enterprise matrix tests. Example:

```php
it('rejects another tenant member in a group', function () {
    [$tenant, $owner] = eventHost('groups');
    Artisan::call('community:enable', ['tenant' => $tenant->slug]);
    $group = CommunityGroup::factory()->create(['tenant_id' => $tenant->id]);
    $foreign = Member::factory()->create();
    $this->actingAs($owner)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('groups')])
        ->putJson('/community/groups/'.$group->id.'/members', ['member_ids' => [$foreign->id]])
        ->assertUnprocessable();
    expect($group->members()->count())->toBe(0);
});
```

- [x] Run `php artisan test --compact tests/Feature/Community/GroupsTest.php tests/Feature/Community/MessageEntitlementsTest.php`; confirm meaningful failure before implementation.
- [x] Generate classes/migration using discovered Artisan signatures; implement schema, scoped requests/routes and relationships. Add `send community messages` to seeder and built-in owner/admin role lists; do not grant to unrelated read-only roles. Add explicit scheduling/group-send feature metadata to standard package definitions using existing seeding patterns; deployment must add only these feature records/pivots, not reset live prices or commercial terms.
- [x] Pass focused tests and permission contract tests; run Pint. Commit `feat(community): add flat groups and message entitlements`, tick steps.

## Task 2: Preferences and normalized audiences

**Files:** Create `app/Services/Community/AudienceResolver.php`, `AudienceAddress.php`, `AudienceSource.php`, `MemberAudienceSource.php`, `EventAudienceSource.php`, `MessagingPreferences.php`; `app/Models/CommunityMessagePreference.php`; migration `create_community_message_preferences`; public preference controller + request + view; tests `CommunityAudienceTest.php`, `CommunityPreferencesTest.php`. Modify event job only in Task 5.

**Schema:** Preferences keyed unique(tenant_id, channel, address_hash), encrypted normalized address, marketing_disabled_at, timestamps. Address hash is HMAC with a dedicated derived key from application key (not raw SHA of guessable phones); public links use random256-bit tokens, store hash only. Tokens authorize only opt-out for that one tenant/channel/address, never member lists, pay links or `/my`. GET displays confirmation without mutation (mail scanners); POST idempotently disables marketing, throttled. Do not expire an unsubscribe link while an announcement may still be read. Preference changes never unsubscribe service notices.

**Interfaces:**

```php
interface AudienceSource {
    /** @return iterable<AudienceAddress> */
    public function addresses(Tenant $tenant, array $selector, string $channel): iterable;
}
// readonly AudienceAddress(string $address, string $channel); no inferred identity
// AudienceResolver::resolve(Tenant $tenant, array $selector, string $channel): iterable
// MessagingPreferences::allowsMarketing(Tenant $tenant, string $channel, string $address): bool
```

Allowed Community selectors are all_members, status and group_ids; arrears adds an open-overdue-charge intersection and finance permission. Reject unknown keys and foreign groups, no arbitrary model names/query fragments. Resolve channels separately; invalid addresses are counted/skipped, never sent raw. Use bounded source paging and persistent unique delivery-address keys rather than an unbounded in-memory set.

- [x] Add failing cases: same Ghana number in local/+233 format, case/space email variants, shared family phone, identical address in another tenant, empty/invalid address, group/status intersection, arrears without finance permission; member+fake follower overlap yields one address. Preference GET causes no write; wrong/tampered token rejects; repeated POST is harmless; opt-out affects marketing only.

```php
it('deduplicates normalized email only inside the selected tenant', function () {
    [$tenant] = eventHost('audience');
    Member::factory()->count(2)->create(['tenant_id'=>$tenant->id, 'email'=>'Ama@example.test']);
    Member::factory()->create(['email'=>'outsider@example.test']);
    $addresses = iterator_to_array(app(AudienceResolver::class)->resolve($tenant, ['kind'=>'all_members'], 'email'));
    expect(array_column($addresses, 'address'))->toBe(['ama@example.test']);
});
```

- [x] Run those files red, implement normalization/preference schema/token flow/source adapters, then green. Anonymous opt-out pages reveal no financial data and no other organisation's subscriptions.
- [x] Commit `feat(community): resolve audiences and honor channel preferences`, tick steps. Production follower integration remains explicitly milestone 3.

## Task 3: One monthly SMS budget and auditable reservations

**Files:** Create `app/Services/Community/CommunitySmsBudget.php`, `app/Models/CommunitySmsReservation.php`, migration `create_community_sms_reservations`; modify DuesReminders to delegate only budget reservation/finalization; tests `CommunitySmsBudgetTest.php`, infrastructure `CommunitySmsBudgetConcurrencyTest.php`; keep existing reminder tests unchanged.

**Schema:** Reservations tenant_id, source_type/source_id, credit_receipt_id, reserved_at UTC, budget_month (local YYYY-MM), credits, status reserved/accepted/refused, reason/provider reference; unique(source_type,source_id). Existing reminder reservations are counted alongside new rows until naturally aged out; do not count a migrated/new reminder twice. Keep unknown as reserved with explicit reason. Monthly usage is derived from reservation month (not today's date or mutable member charge). Historical sent reminders preserve their usage.

**Interfaces:** `reserve(Tenant, string $sourceType, string $sourceId, CarbonImmutable $at): ?CommunitySmsReservation`; `finish(Tenant, string $reservationId, SmsResult): void`; `remaining(Tenant, CarbonImmutable): ?int`. All budget checks and prepaid reserve/refund use `SmsAllowance::withLock`; lock ordering fixed allowance first, then source claim, never reverse. Reserve before submission, not for a future scheduled message. A month reset cannot return old-month allowance into new-month usage; retain existing receipt refund contract.

- [x] Write failing tests for reminder+message combined limit, last-slot race, unknown hold, refused exact refund once, provider throttle, monthly boundary/timezone, existing sent/reserved reminders and two simultaneous finalizers. Example assertion:

```php
expect($budget->reserve($tenant, 'community_message', $firstId, $now))->not->toBeNull();
expect($budget->reserve($tenant, 'community_message', $secondId, $now))->toBeNull();
expect(app(SmsAllowance::class)->remaining($tenant))->toBe($before - 1);
```

- [x] Run focused files red. Implement budget and delegate reminder spending; preserve reminder member/day uniqueness, scheduler date guard and send content. No ledger writes.
- [x] Run budget files, existing DuesRemindersTest, its concurrency test and prepaid credit tests green; commit `feat(community): share SMS budget across reminders and messages`.

## Task 4: Durable messages, scheduling and bounded delivery

**Files:** Create CommunityMessage, CommunityMessageDelivery + factories/migrations; CommunityMessageController, StoreMessageRequest, PreviewMessageRequest; `CommunityMessages` service; `DispatchCommunityMessage`, `DeliverCommunityMessage` jobs; CommunityMessage mail/view; due dispatcher command and Kernel schedule. Tests `CommunityMessagesTest.php`, `CommunityMessageDeliveryTest.php`, `CommunityMessageSchedulingTest.php`.

**Schema:** Messages UUID/tenant/actor/subject/body/channels/selector/estimate/status/scheduled_at UTC/started_at/finished_at/cancelled_at/timestamps; statuses draft, scheduled, queued, sending, completed, partial, paused, cancelled. Per-channel deliveries UUID/message/tenant/channel/address_hash/encrypted_address/status/reason/reservation/reference/claimed_at; unique(message,channel,address_hash). Record submitted/accepted as accepted, never claim provider delivery without evidence. Do not retain raw rendered bodies containing identifiers in logs.

**Interfaces:** `preview(Tenant, User, array $input): array`, `queue(Tenant, User, array $input): CommunityMessage`, `cancel(Tenant, User, CommunityMessage): void`; preview returns per-channel eligible/skipped/estimated-credit counts. POST send carries a UUID idempotency key unique per tenant, so double-click/request retry returns the same message. Save drafts without sending. Immutable content/selector once queued; edits require a new draft.

- [x] Write tests red for missing/revoked send permission; hidden arrears count without finance; zero audience; SMS disabled/provider missing/no credits; Free scheduling/group restrictions; invalid DST time; duplicate submission; cancel-before-send; downgrade pause; contact/group/status change; unsubscribe after estimate and after job claim; two workers/retry after timeout; email+SMS channels independently counted.

```php
it('honors an opt-out made after scheduling', function () {
    $message = $messages->queue($tenant, $owner, $scheduledEmailInput);
    $preferences->disableMarketing($tenant, 'email', $member->email);
    $this->travelTo($message->scheduled_at->addMinute());
    app(CommunityMessages::class)->dispatchDue($message->id);
    Mail::assertNothingSent();
    expect($message->fresh()->deliveries()->where('reason','unsubscribed')->count())->toBe(1);
});
```

- [x] Implement requests with subject max255/body max10000, validated selectors/channels/timezone; compose only marketing. Include unsubscribe action/link in email and SMS, within SMS459-character bound. Escape text in email templates, no arbitrary HTML/script rendering.
- [x] Due dispatcher uses DB lock/status compare-and-swap and bounded cursor continuation, not long queue delays alone. Maximum100 recipients/page; one email per delivery job and at most50 SMS entries/batch, timeouts30/60sec below retry_after90. Resolve current audience when due, persist unique address claims, and recheck each address before submission. Restore TenantContext and permission team context in finally. A cancellation stops untouched recipients, never promises to recall submissions.
- [x] Reserve email allowance atomically too; existing EmailAllowance uses the same prepaid base. Accepted email is metered once; definite pre-submission failure can refund; ambiguous email outcome is held with no automatic replay. Cancellation/suppression before transport refunds any confirmed unused reservation.
- [x] Return accurate partial/paused/skipped outcomes; jobs cannot mark a send fully complete while unclaimed/unknown recipients exist. Completion means processing finished, with channel counts and warnings, not delivery/read proof.
- [x] Pass focused tests; commit `feat(community): queue consent-aware messages with scheduling`.

## Task 5: Incremental Events reuse without history changes

**Files:** Modify SendEventBlastJob, EventBlastController only where shared services replace normalization/transport; add `app/Services/Community/AnnouncementTransport.php`; keep EventBlast/EventBlastRecipient/EventNotificationLog schemas and existing open tracking. Tests existing EventBlastTest, BlastSmsTest plus `EventBlastConsentTest.php` and email retry coverage.

**Interfaces:** Transport accepts a claimed delivery ID, destination, subject/text and channel; uses existing adapters, returns accepted/refused/unknown. Domain adapters own templates, tracking and idempotency; never force Event mail into Community records. EventAudienceSource calls EventBlast::audienceQuery for the original selector, preserving every event-specific option.

- [x] Write tests red: explicit tenant/channel marketing opt-out suppresses a free-form blast but not ticket/receipt mail; duplicate contact sent once per channel; retried blast does not repeat accepted email or re-meter it; registration tracking/pixel and SMS source-reference contracts remain intact.
- [x] Extract normalized-address selection first, run existing event blast tests. Extract transport second, rerun those tests plus notification/SMS gateway tests. No event milestone gate or new Community entitlement may block existing event tools. Preserve event blast scheduling contract unless an explicit spec requirement needs a documented change.
- [x] Commit `refactor(messages): share audience and transport with event blasts`. Any necessary existing-test change must explain actual behavior correction, not weaken assertions to hide regressions.

## Task 6: Operator resolution for unknown SMS outcomes

**Files:** Create superadmin CommunitySmsReconciliationController, request, resolution audit model/migration; console admin page using existing superadmin layout; `CommunitySmsReconciler` service. Tests `CommunitySmsReconciliationTest.php`.

**Interface:** `resolve(User $operator, Tenant $tenant, string $reservationId, string $outcome, string $evidence, string $reason): void`; only outcomes confirmed_accepted or confirmed_not_submitted; no replay button. Application `access-superadmin-dashboard` gate; source row locks under allowance lock. Persist operator/tenant/source/prior/new/evidence/reason/timestamp; audit contains no credentials or full private message body.

- [x] Write red tests: tenant admin denied even with same email as superadmin; missing evidence rejected; accepted preserves spend; confirmed refusal refunds exact receipt once; concurrent repeated resolutions are idempotent; cross-tenant source IDs denied. Existing reminder unknown rows can also be resolved and remain counted correctly.
- [x] Implement list/filter/detail plus explicit evidence confirmation; show provider reference and unknown age, not guessed delivery state. Provider status lookup uses only an existing documented adapter capability; absent that, manual evidence required, never infer refusal from timeout. No resend of the original delivery after resolution.
- [x] Pass tests, commit `feat(community): audit uncertain SMS reservation resolution`. This closes the operational gap explicitly recorded in milestone1c.

## Task 7: Console UI, targeted acceptance and release handover

**Files:** Modify consoleNav.js, People.jsx, Overview.jsx; create Community GroupsPanel.jsx, Messages.jsx, MessageComposer.jsx, MessageDetail.jsx; corresponding JS interaction tests. Existing console Button/Modal props only. Update EventPackageSeeder metadata and `.env.example` only if a genuinely new key is approved (none proposed). Update this plan and roadmap.

- [x] Write failing interaction tests: Groups tab empty state, attach/remove, modal Escape/cancel; composer preview/change clears stale estimate; disabled submit/loading/errors; plan upgrade explanation; timezone confirmation; confirmation audience/channels/credits; cancel; partial/unknown delivery detail; link to superadmin resolution gated. Generic announcement copy must not expose member debts or pay tokens on shared addresses.
- [x] Build accessible responsive forms with current design tokens/dark mode; groups on People, Messages navigation under Community, recent message summary on Overview. Hide future Followers UI; show real history even if scheduling/group entitlement later becomes read-only. Arrears option absent without finance access and backend rejects forged payload too.
- [x] Run affected JS tests, lint, Pint and build once after UI stabilizes; commit rebuilt assets with the same UI commit.
- [x] Browser acceptance on isolated QA:390px+desktop, light/dark, group editing, send preview, future schedule/cancel/downgrade, unsubscribe before due, shared address dedupe, no-credit/monthly-limit messages, unknown outcome resolution using fake adapters. No real messages or payments to third parties. Check console errors and actual rendered send outcome; email acceptance is not proof of inbox delivery.
- [x] Final CI gate configured on the feature pull request (the run and authoritative results are recorded there; must be green before integration): PHP four-worker suite, all JS tests, PHPStan zero/no baseline growth, Pint/lint/build, finance category verification; one fresh whole-branch reviewer. Use targeted tests during each task; no full suite per small edit. Fix new findings, repeat only affected checks until final CI.
- [x] Self-review every touchpoint: navigation, permissions deployment, feature metadata, retained history, scheduling/downgrade, consent, budget concurrency, unknown resolution, no new unused package, accessible states and all regression suites. Report remaining limits plainly.
- [x] Commit/push implementation and review evidence for the feature PR; its checks are the authoritative record of final CI results.
- [x] Obtain owner integration/deployment go-ahead: owner said "merge"; PR27 merged and automatic deployment verified.
- [x] Apply owner’s standing Purpledot pilot approval, complete the missing-only entitlement sync and activate Purpledot alone. Readback confirmed only purpledot enabled, with scheduling and group messaging available. Main auto-deploys. Pilot only Purpledot after explicit milestone2 activation; do not change other tenants or prices.

## Self-review of this plan

- Spec coverage: Groups/People in1+7; audience/consent in2+4+5; SMS budget in3; now/scheduled delivery in4; operational unknown handling in6; plan gates/permissions/read-only downgrade in1+4+7.
- Five Review Focus risks each have named tests in2–6. Identity access, Follow/Join, giving, `/my` redesign, documents and polls remain later milestones.
- Follower overlap is tested at the source boundary here; actual follower data integration and verified-identifier access are acceptance requirements of milestone3, not claimed complete in2.
- Existing reminder reserved rows remain budget inputs; ledger/payment/payout history is unchanged. The plan does not repeat the general package seeder on production.
- Outstanding owner review: proposed defaults/status audiences and marketing-preference policy above; scheduling behavior after downgrade; native versus per-task independent implementation/review. These are visible choices, not hidden implementation assumptions.
- Live authenticated smoke testing completed after owner sign-in: Overview, People/Money empty states, member/type dialogs, reminder preview (zero recipients, queue disabled), CSV import preview with two invalid rows and no confirmation, Finance date filtering and both actual CSV downloads (headers only, no dues records), light/dark field contrast, unsaved settings draft retention and Escape dismissal. No persistent member, type, charge, payment or reminder-setting changes; no sends. Purpledot still has zero members. This is not a live payment or outbound-delivery test.
- Live findings to fix before milestone2 implementation: People header action toolbar overflows at390px (main scrollWidth505 versus390); MemberFormModal controls lack associated labels (Name input labels=[]). Keep this a small UI follow-up with focused regression tests, not a broad suite/debugging exercise. Screenshot is private under storage/app/private/community-live-people-mobile.png. No browser console errors on the tested Community pages.

### Implementation notes

- Prerequisite browser fixes `eb4feb4`: shared Input/Select label and description associations; header wraps. Focused JS13/13, lint/build; compiled isolated-QA browser width390/scroll390, label count1, Add member visible. No live changes.
- Task1:13 focused tests/43 assertions and11 permission/package regression tests/39 assertions pass on dedicated `miconvener_community_2_testing`; Pint applied.

- Task2:10 focused tests/27 assertions pass. Temporary encrypted run-address rows provide bounded deduplication and hourly expiry cleanup. Multiple hashed opt-out tokens retain old links. These implementation refinements preserve the approved no-person model.

- Task3: shared budget/reminder53 tests and six real-worker race tests pass; plan reset/refund and legacy-reservation counting covered. No production activity. Tests use separate feature and infrastructure databases after correcting a local overlap.

- Task4: durable delivery/scheduling implemented with own `community_messages` rollout switch (off by default); group routes share that milestone switch.35 focused feature checks across separate runs plus2 actual competing email/SMS workers pass. Invalid DST offsets, replay, cancellation, changed consent/contacts/groups/permissions, low credits and interrupted claims covered. Due jobs are delayed to their UTC time with a15-minute recovery sweep, preserving existing scale-to-zero scheduling policy.

- Task5:47 Events/gateway/credit checks and29 shared-domain checks pass in recorded runs. Event email queues retain tracking and gain durable claims; queued mail rechecks consent. One SMS regression assertion intentionally now checks the existing personalised placeholder transport with distinct opt-out links, still one provider request. Event announcements count toward the shared SMS cap; existing service notifications do not use marketing opt-outs. Previous-release queued SMS resolves its registration from the legacy key.

- Task6: evidence-backed review covers SMS reservations, earlier reminder holds and metered Community/Event email. Twelve feature checks and one real concurrent-operator check verify gating, source ownership, exact-once refunds, summary updates and audit attribution. Masked destinations/source references make investigation practical. An immutable operator reference survives account deletion. No resend operation exists.

- Task7 UI: Groups tab with paged incremental member selection; Messages list/composer/draft editing/history/cancel; explicit timezone, fresh audience confirmation, credit estimates, plan explanations and operator-only review link. New send permission is tenant-assignable and shared with navigation. Narrow `community:sync-message-entitlements --dry-run` / command fills only missing rows, preserves commercial terms/overrides and never enables rollout.
- Verification in progress: affected Community287/287 (1599 assertions), extra UI/API7/7 (65 assertions), all JS295/295, lint/build, PHPStan zero. Browser isolated QA: group create/add/remove/Escape; log-only email2 accepted; draft edit, future schedule/cancel; dark390px dialog388/scroll388 and main390/scroll390, associated readable fields. No production activity.
- Review caught and fixed preview-request inheritance incompatible with Pint final classes, unintended form submission by action buttons, and drafts incorrectly requiring send authority. Regression tests cover each. Optional local `laravel/pao` formatter hid fatal output; `PAO_DISABLE=1` exposes full results and was used for the affected Community rerun.


### Final self-review report (2026-10-16)

**Touchpoints updated**

- [x] Seeders/permissions: permission definition and built-in role grants; custom-role ceiling; Inertia `auth.can`; missing-only entitlement sync. Existing deployment seeds permissions. Activation remains explicit.
- [x] Navigation: Community Messages; People Groups; recent authorised messages on Overview; operator entry from Message delivery and gated console link.
- [x] Tests: affected Community287/1599 assertions; UI/API7/65; all JS295; final review corrections61/223; later empty-completed-result regression10/10. Full CI status lives on the feature PR, not this snapshot.
- [x] Config: separate milestone switch and scheduled recovery command registered. No new environment variables or dependency.
- AGENTS.md: unchanged; existing finance/continuation rules still apply. These operational notes and roadmap hold the new commands.
- Memory: no new personal preference or separate memory artifact required.
- `.env.example`: unchanged; no new key.

**UI gap analysis**

- Discoverability, empty states and permission gating: present. Arrears history, cancellation responses and Overview also require finance access; send-only staff cannot recover counts through history.
- Loading/errors/retry: present; sending disabled while busy and after stale/failed estimates. Form labels and keyboard dismissal work.
- Mobile/dark: isolated QA390px main390/scroll390, composer388/scroll388; light text on dark fields with associated labels. Desktop tested at1366px.
- History: provider acceptance is not called inbox delivery; unknown credits remain held; paid history stays visible after downgrade. Empty completed sends now say no recipients were selected, rather than promising future selection.

**Solved during review**

- Fresh independent review:3 Important,0 Critical,0 Minor. All reproduced with failing tests, then corrected in one pass: Event SMS429 preserves provider delay and retries with exactly one eventual debit; Community/Event SMS batches recheck cancellation/eligibility immediately before transport and refund confirmed unsubmitted units; every arrears-history touchpoint obeys finance access. Focused correction suite61/61 passes.
- Preview request composes shared validation instead of extending a final class; explicit action-button types prevent accidental form submission; draft saves do not require send confirmation. Optional local Pao reporting was disabled for reliable test evidence.
- Browser operator path: ordinary QA owner denied; dummy global operator recorded synthetic evidence-backed non-submission and an immutable audit entry; no resend. Local legacy admin dashboard has an unavailable Reverb websocket; no message-workflow JavaScript exception was observed.

**Recommended for owner decision**

- Review the feature PR and approve integration/deployment, then explicitly activate milestone2 for Purpledot only. This session does neither. Keep the real-provider pilot and operator responsibility as release gates; no external SMS or real inbox delivery is claimed by fake/log-only testing.

**Not gaps / limits**

- Initial scheduled jobs use the selected UTC time; lost-job recovery can take15 minutes to preserve the existing scale-to-zero schedule.
- Real Followers integration belongs to milestone3; overlap is tested using the source contract here.
- Generic announcements never generate personalised dues balances or pay tokens. Essential dues/service notices keep their existing path.
- Browser QA includes create/add/remove/archive-capable group controls, draft edit, log-only email2 accepted, future schedule/cancel, a public opt-out before due processing (zero selected), shared-email estimate2 for three eligible member records, no-credit rejection, paid capability withdrawal with retained history, synthetic unknown resolution, mobile/dark and keyboard dismissal. Capability overrides were restored afterwards. Providers and production data were untouched.

**Deployment handover, after owner approval only**

1. Main auto-deploys. Review green PR checks and merge only after the owner agrees.
2. Normal deploy migrations/PermissionsSeeder install schema and role permission. Run `community:sync-message-entitlements --dry-run`, inspect, then `community:sync-message-entitlements`; it fills only missing rows for these two new entitlements and never rewrites prices, commissions, existing overrides or rollout switches.
3. Leave milestone2 off until explicitly approved; pilot `community:messages-enable purpledot` only then. Other tenants stay off. Confirm queue workers and the scheduled recovery command are healthy. Assign an application operator to investigate held outcomes using provider evidence.
4. Rollback new sending by disabling the milestone switch; historical/audit/preference records stay intact. Do not replay unknown submissions or reverse schema containing history.

### Implementation rulings (durable record)

- Ruling: Follow existing React/Tailwind console rather than legacy Metronic/Blade skill guidance — current code and approved spec bind this feature — cost if wrong: visual inconsistency with legacy screens only.
- Task1: RED routes404/classes absent; fixed mistaken test helper before GREEN13/13 + contracts11/11. Ruling: migration timestamp Oct18 follows existing Oct16/17 Community migrations — generated current-date order would precede member schema — no data change.
- Task2 Ruling: use encrypted temporary audience rows keyed by run/channel/hash, deleted in finally and pruned hourly after expiry — persistent uniqueness gives bounded memory for enterprise audiences and source overlap; cost if wrong: extra selection writes.
- Task2 Ruling: keep multiple random hashed unsubscribe tokens per address rather than replacing a single token — old emails must remain actionable; cost: small growing token table.
- Task4 Ruling: source selection uses a stored member cursor and started_at cutoff in pages of100; delivery uniqueness spans pages — avoids unbounded selection jobs and excludes later joins — cost if wrong: late source changes need milestone3 paging adapter integration.
- Task4 Ruling: a scheduled opt-out may yield zero selected delivery records (not an artificial skipped row for an ineligible address) — audience re-evaluated at send time as spec requires — cost: fewer per-address reasons for people removed before selection.
- Task4 Ruling: initial delayed job plus15-minute recovery sweep rather than a per-minute cron — preserves production scale-to-zero wake policy while normally queueing at selected UTC time — cost: recovery after lost scheduling may take15 minutes.
- Task4 Ruling: groups and messages share their own community_messages admin rollout override, default off, separate from Community1c — each milestone must dark-launch independently — cost: groups are not visible until milestone2 enabled.
- Task5 Ruling: event announcement SMS also uses the shared monthly budget, including known accepted legacy event SMS in current-month accounting — otherwise Events bypasses the organisation cap — cost: existing announcement traffic can use up the cap.
- Task5 Ruling: new event email claim table preserves EventBlastRecipient and EventNotificationLog schemas/tracking — durable replay prevention cannot safely use recipient existence alone — cost: one extra infrastructure table.
- Task5 Ruling: prepaid reserve defaults remain strict; opt-in allowMissingLegacyFeature is used only for existing Events email paths — preserve their existing absent-entitlement behavior, never explicit withdrawals — cost: unbackfilled legacy accounts keep the same permissive behavior until feature sync.
- Task5 Ruling: SMS payload regression assertion now requires per-address opt-out URLs through the existing Omnichannel template placeholder, still one provider request — spec requires actionable opt-outs; asserting old text would prohibit them — cost: link reduces space for announcement text within459 characters.
- Task6 Ruling: also resolve unknown metered Community/Event email through the same audited operator screen — holding email credits without an auditable resolution would strand them — cost: additional source kinds in the operator UI, no automatic resend.
- Task7 Ruling: add a narrow missing-only message entitlement sync command — normal deploy only seeds permissions and wholesale package seeding would overwrite commercial terms — cost: owner-approved deployment needs one extra command.
- Task7 Ruling: expose draft updates and incremental paged group membership — saved drafts and large groups otherwise have no usable completion path — cost: two additional guarded endpoints.
- Final: Ruling: production provider/deployment readiness not independently judged by reviewer — no real provider or production test is claimed; owner-approved pilot still required — cost: true external delivery remains unproven.
- Final: Ruling: full suite and browser acceptance not independently reproduced by reviewer — author evidence and final CI cover these; source review is read-only — cost: no separate browser tester.

Deferred minors: none.

- Final local evidence: affected correction regression61/223 assertions; full JS295 before the final one-case empty-result addition, then composer/detail10/10; PHPStan zero; Pint/lint/build clean; finance category verification exits0. One final full CI run is submitted through the feature PR. CI outcome is reported in the PR rather than triggering another full run solely to rewrite this documentation snapshot.

- Full CI first run:2705 passed; one existing guard correctly rejected missing `CommunityMessage` coverage in the all-mailables render matrix. Added the actual render case (not a guard suppression); focused matrix/render checks passed. This test-only correction and updated handover trigger the final CI retry. No application or production behavior changed.

- Release verification2026-10-16: deployment `depl-a2fe17c8-cad4-4bc3-8364-9eef240d4859`, SHA `b659e66ca5e51b3a32b1d81996a3511366dbb3d1`, succeeded. Seven required tables and send permission exist; rollout enabled count0. Homepage and tenant login returned200, browser homepage loaded without JavaScript errors, manifest’s production-built JS/CSS returned200 and JS contains the reviewed Messages screen and final empty-result copy. Production uses its own environment-dependent JS hashes. No runtime data changes, activation or external sends. Original Claude checkout remains clean at0173494. Release-status docs remain on the working branch for the next milestone merge, avoiding an extra documentation-only deployment.

- Standing rollout policy2026-10-16: owner said "Always enable for Purpledot." Recorded in AGENTS.md and roadmap. Production dry-run succeeded; `community:sync-message-entitlements` and `community:messages-enable purpledot` succeeded at03:53 UTC. Independent readback returned only[purpledot], Community/messages=true, schedule/groups=true. No live outbound sends or other tenant rollout changes. Authenticated browser acceptance remains separate because the existing test-browser session expired.
