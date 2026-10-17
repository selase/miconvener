# Community: design

Date: 2026-10-15 (revised after the owner's review and a second review by Codex). Status: ready for planning, milestone by milestone. Path: architectural (new console section, changes how people are identified in `/my`, generalises event-only tools).

Supersedes the scope of `2026-10-13-member-dues-design.md` from its milestone 3 onward. The dues engine built in milestone 2 (branch `member-dues-m2`, not merged) is kept and re-homed here; section 9 lists what changes.

## 1. Purpose

Organisations of every kind (unions, drivers' and teachers' associations, alumni groups, churches) need an ongoing relationship with their people, not only with event attendees. They want to keep a register, collect dues or giving, message people, share documents and ask their opinion, with money reported the same way ticket money is.

Success looks like this:

- An organisation can run its whole community from one console section without learning a second set of tools.
- A person sees everything they have with any organisation (events, dues, giving, messages, documents, polls) in one place, `/my`, which feels like a native phone app.
- Every cedi is reported in one Finance section, by category, with statements an organisation can hand to its members.
- Every entry point has one obvious job. Simplicity guides the design but never blocks a needed expansion.

## 2. The model

| Who | Where | What lives there |
|---|---|---|
| Organisation admin | **Events** | Things that happen on a date: tickets, agenda, check-in, live polls. Unchanged. |
| | **Community** | People and the ongoing relationship: followers, members, groups, dues, giving, messages, library, polls and surveys. New. |
| | **Venue** | Facility and venue management, for venue hosts. Existing items, grouped (section 14). |
| | **Finance** | All money in one place: events, dues and giving; reports; every payout. Extended. |
| Member, attendee, follower | **`/my`** | Everything about me across every organisation. Rebuilt as an app (section 6). |
| Anyone | Organisation page, event pages | Follow, Join, Give, Register. Extended. |

**Shared services, not shared tables.** Messages, library, polls and reports reuse the same delivery, storage and audience services wherever possible. Event tools keep their own records and event-specific rules (material release times and speaker/session rules, live poll eligibility and presentation). Organisation-level tools get their own records. Event models are never made organisation-wide by making `event_id` nullable. Extraction is incremental: one service at a time, each with the existing event tests green.

**No second events place.** Community does not get its own events. It connects to Events: a new event can be announced to followers and members; an event can be members-only or have a member price (milestone 6); Community's overview lists upcoming events and links into Events.

## 3. Decisions (owner, 2026-10-15)

| Topic | Decision |
|---|---|
| Name | The console section is **Community**, with its own sub-menus (section 4). |
| Following | Luma-style: anyone can follow an organisation from its page or any of its events. Free on every plan. |
| Membership | A register. A member has a membership type, a status and groups. |
| Dues | Optional per membership type. The milestone 2 dues engine is reused. |
| Periods | Daily, weekly, monthly, quarterly and yearly (section 7). |
| Giving | Voluntary money goes to **funds**: open-ended (Tithes, Offerings) or a **campaign** with a target and optional deadline. |
| Campaign progress | Target, raised, left to raise, percentage and givers, to admins and optionally on the public page. |
| Event contributions | Associated with funds without rewriting history (section 5.4). |
| Commission | Base 1% for dues and giving, negotiable per organisation and per stream, possibly higher; superadmin only (section 5.2). |
| Finance | One section for all money; the separate "Dues payouts" menu moves into Finance. |
| `/my` | The person's home: a native-feeling PWA on phones, a left-rail layout on desktop (section 6). |
| `/my` visibility | Exact verified-email records, explicitly followed organisations and individually authorised phone-member records; no blanket phone-match lookup (section 6.1). |
| Followers and SMS | Followers may be messaged by SMS; the organisation pays for it. |
| Polls and surveys | Live polls stay in Events. Community polls and surveys go to an audience. Votes come later. |
| Console navigation | Grouped: Events, Community, Venue, Finance, Organisation (section 14). |
| Pricing | Section 13, confirmed. |
| Milestone 2 | Re-homed under Community on its branch, then merged. |

## 4. Community sub-menus

**Overview · People · Money · Messages · Library · Polls.** Settings are a tab on Overview. An item the organisation has not switched on is hidden; nothing is hidden that the organisation has paid for or holds records in.

- **Overview:** members, followers, collections this month, arrears, upcoming events (links to Events), recent messages; settings tab (follow and join options, reminder schedule, timezone, branding, SMS spending limit).
- **People:** tabs **Members · Followers · Groups**. Groups are branches, chapters, stations, ministries; a person can be in many; groups are audiences everywhere. Never named "Team", "Leads" or "Contacts" (those exist for staff and venue enquiries).
- **Money:** tabs **Dues · Giving**. Dues: membership types, charges, waive, void, record cash. Giving: funds and campaigns, give pages, gifts, bulk cash entry. Reports and payouts are in Finance, linked from here.
- **Messages:** compose to an audience by email and SMS, send now or schedule (section 8).
- **Library:** documents for an audience (constitution, minutes, notices).
- **Polls:** polls and surveys to an audience, results and export.

## 5. Money

### 5.1 Two kinds

| | Dues (owed) | Giving (voluntary) |
|---|---|---|
| Examples | Union and association dues | Tithes, offerings, donations, building fund, funeral support, a project |
| Shape | A member owes an amount per period | A gift to a fund |
| Arrears | Yes | No (pledges later) |
| Ledger category | `dues` (exists) | `giving` (new, carries the fund) |
| Commission | Resolved per section 5.2 | Resolved per section 5.2 |

### 5.2 Commercial terms

One resolver, `CommercialTerms::for(Tenant, Stream)`, answers rate, cap and bearer for each stream: `tickets`, `contributions` (until cut over to giving), `giving`, `dues`.

- **Precedence:** a superadmin override for this organisation and stream, then the plan's default for the stream, then the platform default in config.
- **Defaults:** 1% on paid plans, 2% on Free, uncapped; bearer is the organisation for dues and giving (tickets keep their existing bearer and cap rules, through the existing `PlatformFeeResolver`, which becomes the tickets branch of the resolver).
- **Waivers:** an override of exactly 0 is an explicit waiver and is recorded as such; "no override" is different from "0".
- **Who sets it:** superadmin only, through a platform permission `manage commercial terms`; every change is audited (who, when, old, new, reason).
- **Frozen at checkout:** when a checkout starts, the rate, cap and bearer are stored on the payment attempt; confirmation uses the stored terms, so a plan or rate change cannot alter an in-flight payment. The commission amount is stored on the charge or gift.
- **Reports use recorded amounts**, never today's rate.
- Milestone 2 currently computes the dues fee from config at confirmation; milestone 1b replaces that with the resolver and frozen terms.

### 5.3 Collections, balances and cash

Three different numbers, never mixed:

| Number | Means | Includes recorded cash? |
|---|---|---|
| **Collected** (reports, campaign progress) | Confirmed money in, by category, fund, period | Yes, shown as its own line |
| **Collected online** | Money paid through MiConvener checkout (platform or own gateway) | No |
| **Available to pay out** | Platform-collected money held for the organisation, less payouts in flight | No, never |

- Recorded cash and bulk cash never increase the payable balance and post no ledger entry (as for dues today).
- Reports read the domain records (charges, gifts, registrations) for gross, fees, commission and net, because for own-gateway organisations the ledger holds only the commission. The ledger is used for balances and payouts.
- Refunds, reversals, voids and corrections reduce collected amounts in the period they happen and are shown as their own lines.
- The tenant Finance page (today 403 unless the plan handles ticket money) opens for any organisation that collects dues or giving.

### 5.4 Giving, campaigns and event contributions

- **Campaign progress:** raised = confirmed gifts (online and recorded cash, including bulk cash) minus refunds, reversals and corrections; left to raise = target minus raised, never below zero; percentage; progress bar.
- **Givers** means distinct identified givers (by verified email or phone, or the admin-entered giver), not transactions. Bulk cash counts toward raised but adds no giver.
- **At the target** the campaign stays open and shows "Target reached" and any amount over it, unless the organisation chose to close it automatically.
- **Public page** can show amounts or only the percentage.
- **Anonymity:** an anonymous gift hides the giver's name on public pages and from staff without `read finance`; staff with `read finance` see it, because statements and audits need it. The giver still sees it in `/my`.
- **Anyone can give,** members or not. Recurring giving is a reminder with a pay link (mobile money cannot be auto-charged).
- **Event contributions:** real settled money exists (the probe tenant holds live charges), and historical event payouts may already combine ticket and contribution money. So history is not rewritten: existing contributions keep their records, references, receipts, tributes, links, ledger category (`event`) and payouts; each is associated with a fund linked to its event so it appears in giving reports and `/my`. A **cutover date** is set: contributions made after it are giving transactions in the `giving` category. A before-and-after reconciliation (balances, payouts, `/my` histories, receipts) runs in tests and on production, with a documented rollback (drop the associations; no balances move).

## 6. People, identity and `/my`

### 6.1 Identity and access

- **Email first (owner decision, 2026-10-16).** Email is the primary sign-in and verification method. Phone is an independently verified additional method and a supported alternative for people without email; do not require everyone to provide a phone or an email. Present email first and explain the phone-only claim path.
- **No person table.** "Me" is a set of independently proven identifiers in this session. Exact normalised email matches retain access to records carrying that email. Phone matches alone never unlock existing private records. Store tenant/member-specific access grants and credentials rather than creating a global person or inferred identity graph.
- **No transitive access.** A record holding email A and phone B is visible through verified A, or through verified B plus a valid grant for that exact member. Verifying A never verifies B, reveals other records holding B, or links histories across organisations. Adding a second identifier always requires verification of that identifier. A grant for one member or organisation grants nothing elsewhere.
- **Phone verification and member claims are separate.** An SMS code proves current control of the number. It permits following, a join request and access to the person's own newly submitted request; it does not reveal names, membership existence, balances, receipts, messages or private documents of existing members. To claim an existing membership, independently verify the email already recorded for that member, or complete an organisation-assisted identity check. A name, birth date, membership number, age of a record, repeated SMS code or link sent to the same number is not independent proof.
- **Phone-only recovery.** After a claim is approved, establish a high-entropy private recovery credential bound to the exact tenant/member and claimant phone. New sessions require both current phone verification and that credential to restore the grant; SMS alone never restores it. Display the credential once, store only its verifier, permit revocation/rotation and provide organisation-assisted recovery if it is lost. A newly self-created membership can establish its own credential without claiming any pre-existing record. Approval grants access only to the selected member; it never verifies another contact on their record.
- **Shared family numbers and representatives.** Several members may share a phone and remain separate. Sharing it does not grant mutual access. A parent or representative must receive explicit, auditable approval for each member they may access; the UI labels the represented member and role. The representative can view that member's self-service records, not edit contacts, delegate access or transfer tickets merely by holding the grant. Their approved phone is recorded on the grant; it need not replace the member's own contact number.
- **Contact changes and disputes.** The old email loses exact-match access immediately when removed; a newly recorded email requires its own verification. Phone changes revoke the member's direct phone grants and pending claim approvals; the new number must be verified and independently claimed. Representative grants are separately revocable and reviewed on a member contact change, with conservative suspension until reconfirmed. An authenticated member or a verified number holder can report a wrong-number issue without seeing matching private records; uncertain reports enter an audited review queue and do not let a stranger delete or take over a membership. A confirmed dispute revokes affected access. Notify established old/new channels where possible using generic notices without financial/private details. Preserve all membership and financial history.
- **Dispute recovery.** An authorised organisation reviewer can lift a confirmed number hold after a fresh independent ownership check, with method and reason recorded. This permits a new individually approved claim; it never restores revoked credentials, previous family access or historical ownership automatically. Individual representatives can be revoked without removing other valid grants.
- **Session lifetime and revocation.** Proofs never last more than 30 days without re-verification. Preserve the existing `/my` email proof's shorter 12-hour lifetime in milestone 3; phone proofs also last 12 hours. Expiry does not slide. Access checks re-read current contact values and grant status on every protected request, including downloads. Sign-out or identifier removal clears corresponding session grants and personal caches; a claim/recovery rotation invalidates earlier grants. Session expiry alone is not a safeguard against reassigned numbers.
- **Import ambiguity.** Name-plus-phone is a possible-duplicate warning, not authority to merge people or transfer access. Ambiguous rows require explicit selection of the existing tenant member or confirmation that this is a different person; revalidate that decision at import confirmation. No automatic historical-record merges or access grants from imports.
- **Pay links** authorise only that member's open charges and receipts at that organisation. They never open `/my` and never reveal other records.
- **Sign-in codes:** email and SMS codes are throttled per identifier and per IP; SMS sign-in codes are paid by the platform and capped per number per day.

The email-first, claim, shared-number, reassignment and import rules above replace the earlier blanket phone-match access and name-plus-phone deduplication rules. They do not widen existing event/ticket, contribution, abstract or certificate access: milestone 3 retains their verified-email/explicit-registration-grant contracts. Shared or reassigned email inboxes retain the existing mailbox-access trust model; this milestone does not claim to solve mailbox ownership continuity.

### 6.2 `/my` as an app

Owner's reference designs (2026-10-15): event and ticket apps with a greeting header, card lists with large imagery, pill filters, wallet-style ticket passes with QR codes and download/share actions, and a bottom tab bar. They guide layout and feel, not colours; `/my` uses MiConvener's tokens and dark mode.

- **Phone:** bottom tab bar: **Home** (greeting; what needs attention: dues due, next event; messages continue by email/SMS), **Tickets** (passes with QR), **Communities** (organisations I follow or belong to, each with its name and logo), **Money** (dues to pay, giving, receipts and statements), **Me** (identifiers, notifications and preferences, sign out). Full-bleed cards, large touch targets, sheet dialogs, safe-area insets, installable with icon and splash.
- **Desktop:** the same five sections as a left rail with a two-column layout (list and detail). Nothing on the phone is missing on desktop.
- **Accessibility:** WCAG 2.2 AA; keyboard and screen-reader navigation; text scales without breaking layouts.
- **States:** skeleton loading, empty states, clear error states with retry, offline banner.
- **Inbox deferral (owner approved 2026-10-17):** the in-app inbox and read/unread counts are a separate follow-up. Milestone 4 keeps email/SMS delivery and organisation preferences, without deriving unread counts from delivery logs.
- **Offline boundary (ticket-only confirmed by owner 2026-10-17):** only ticket passes are cached for offline use. Financial histories, membership lists, messages and private documents are never cached by the service worker. All personal caches are cleared on sign-out and when the session's identifiers change.

### 6.3 Following and joining

- **Follow:** one tap from an organisation or event page after verifying an email (primary) or phone. Following is consent to hear from that organisation at the selected verified address; it neither creates membership nor reveals private member records. A second contact is never inferred or subscribed automatically. Every non-service message carries an unfollow link; unfollow also disables marketing to that organisation/address even if it is a member contact. Re-following an opted-out address requires explicit consent to resume announcements.
- **Join** (milestone 3): the organisation chooses **approval required** (default), **immediate**, or **invitation only** (admin adds members). Approval-required requests create a member with status `pending`, no charges and no member benefits. Immediate mode creates an active email-verified member through the existing cap/charge rules. Phone-selected applications always await staff review, even in immediate mode: their public status, recovery eligibility and reserved cap slot must not reveal whether the number appears in historic records. Every pending phone application reserves one member slot without creating or linking a member; approval atomically converts that reservation into a new membership, rejection releases it. Existing memberships still require the independent claim process. The organisation explicitly selects an active membership type for public joining; no type means joining is unavailable with a clear explanation. Approval and identity/access approval are distinct actions: approving membership does not automatically approve access to other existing records. Invitation-only disables public self-enrolment, not an administrator's ability to approve an existing member's identity claim. Joining does not silently opt a person into marketing.
- **Member statuses:** `pending` (requested, not approved; no charges), `active` (charged and eligible for member benefits), `lapsed` (set by an admin; no new charges; not eligible for member-only benefits), `exited` (left; no charges; history kept).

## 7. Dues schedules

Adding enum values is not enough; generation is redesigned in milestone 1b.

- **Anchors, in the organisation's timezone:** daily = each calendar day; weekly = the organisation's chosen weekday (default Monday); monthly = day 1; quarterly = 1 January, April, July, October; yearly = 1 January or the organisation's financial-year start month.
- **Joining mid-period:** the first charge is the next full period, unless the admin chooses to charge the current one.
- **Missed scheduler runs:** each run generates every period that should exist up to `generate_days_ahead`, so a missed day is caught up; the unique `(member_id, period_start)` index keeps it idempotent.
- **Type changes:** affect future charges only; existing charges keep their amount, currency and period.
- **Exit and rejoin:** no charges from the exit date; rejoining resumes from the next period, with no back-charges for the gap.
- **Three different "no charge" cases:** a membership type **without an amount** raises no charges at all; a member **override of 0** raises charges marked `waived` (the record shows the waiver); an **inactive** membership type raises no new charges and its members are listed for the admin to move.
- **Reminders aggregate per member,** not per charge, so a daily payer gets at most one reminder a day (configurable to weekly) listing everything open.

## 8. Audiences, messages and consent

- **Audience resolver** (shared by events and Community): resolves recipients inside one organisation only; deduplicates by normalised channel address (a person who is both member and follower gets one message).
- **Re-evaluated at send time:** a scheduled message's audience (group membership, opt-outs, statuses) is resolved when it is sent; scheduling shows an estimate.
- **Preferences** per organisation and identifier: marketing (announcements, newsletters) can be switched off; **service messages** (receipts, payment notices, dues reminders) and **sign-in codes** cannot. An unsubscribe made before a scheduled send is honoured.
- **Permissions:** sending requires a staff permission (`send community messages`); audiences that reveal money status (in arrears) also require `read finance`.
- **SMS spending limit:** a monthly organisation-level cap on SMS credits for messages and reminders, plus per-number and per-IP throttles for sign-in codes.

## 9. What changes in milestone 2's code

Milestone 2 (`member-dues-m2` at `61224d2`; 2377 tests green; clicked through end to end in Paystack test mode) is kept. Changes:

- `dues_classes` becomes **membership types** with an optional amount and period, all five periods, anchors per section 7.
- Drop the unique `(tenant_id, phone)` on members (shared numbers); normalise email and phone.
- Fee computation moves to the commercial terms resolver with frozen terms (section 5.2).
- Console routes move under `tenant.community.*`; **public URLs do not change** (`/pay/{token}` and its callback and receipt keep working, including links already sent).
- "Dues payouts" moves into Finance > Payouts (filtered by category).
- The single `member_dues` gate splits into three layers (section 13.1).

## 10. Milestones

Each is deployable, dark-launched behind its rollout switch, and independently reviewable.

1. **1a Community shell and re-homing.** Grouped console navigation (section 14); Community section; membership types (optional amount, no generation changes yet); routes re-homed with public URLs preserved; Dues payouts moved into Finance; gates split. No money logic changes.
2. **1b Charges and periods.** Commercial terms resolver with frozen terms; all five periods with anchors and timezone; `dues:process` generation with catch-up; joining, type change, exit/rejoin and the three no-charge cases; shared numbers.
3. **1c Import, reminders and Finance.** CSV import with preview, per-row errors and dedupe; aggregated reminders and the arrears button; Finance opened to non-ticket organisations; collections vs available-to-pay-out; dues collections and arrears reports and CSV.
4. **2 Messages and groups.** Groups; audience resolver; organisation-level messages with scheduling and preferences; SMS spending limit.
5. **3 People, Following and Join.** Identity rules (section 6.1) and SMS sign-in; follow; join with approval; followers tab; announcing new events to followers; `/my` Communities data.
6. **4 `/my` app.** The app shell and redesign (section 6.2) across Home, Tickets, Communities, Money, Me.
7. **5 Giving.** Funds, campaigns, give pages, bulk cash, giving reports and statements; contribution association and cutover (section 5.4).
8. **6 Member benefits in Events.** Members-only events and member pricing; eligibility (an `active` member authorised under section 6.1, never a phone match alone) checked at checkout and again at confirmation. Representative access alone does not establish that the representative is the eligible member.
9. **7 Library and polls.** Organisation-level library and polls/surveys answered in `/my`.

Votes, pledges and chapter hierarchies come after, each with its own design.

## 11. Out of scope for now

Payroll check-off, recurring card auto-charge for dues, partial and advance dues payments, pledges, votes, a community chat or forum, multi-level chapter hierarchies (groups are flat), automatic lapsing of members.

## 12. Risks

- **Identity leakage across organisations.** Mitigation: section 6.1; tests that verifying one identifier never reveals records holding another.
- **Breaking events while extracting shared services.** Mitigation: section 2; every event suite green per extraction.
- **Misstated balances from cash or history rewrites.** Mitigation: sections 5.3 and 5.4; reconciliation before and after.
- **Consent and data protection.** Members are added by organisations; service versus marketing messages are separated; privacy page names the organisation as controller and MiConvener as processor.
- **SMS cost.** Organisation spending cap; platform caps on sign-in codes.
- **Payment-processor classification** for dues and giving (Paystack): confirm before enabling for real organisations.

## 13. Plans and pricing (confirmed by the owner, 2026-10-15)

Following is free on every plan; Community revenue comes mainly from the plan (priced by member count) and SMS packs, because commission on small, frequent dues is too small to carry the product (a GHS 2 daily due earns GHS 0.02 at 1%).

| | Free | Starter (GHS 249) | Growth (GHS 499) | Enterprise |
|---|---|---|---|---|
| Followers and announcing events | Yes | Yes | Yes | Yes |
| Members | 50 | 500 | 5,000 | Negotiated |
| Groups | Yes | Yes | Yes | Yes |
| Dues and giving online | Yes, 2% | Yes, 1% | Yes, 1% | Negotiated |
| Messages (email; SMS from packs) | Send now | Send now and schedule | Schedule, and send to groups | All |
| Library | No | Yes | Yes | Yes |
| Polls and surveys | No | No | Yes (with live polling) | Yes |
| Reports and statements | Basic | Full | Full | Full |

- Add-on: extra members in blocks (for example +1,000 for GHS 50 a month).
- SMS packs are the main usage revenue for reminders and messages. Annual discount as for existing plans.

### 13.1 Gates, limits and downgrades

- **Three separate layers:** a **rollout switch** per milestone (admin-override feature rows, as `dues:enable` does today, then removed when generally available); **plan entitlements** (member cap, scheduling, group messaging, library, polls); **staff permissions** (`manage members`, `send community messages`, `read finance`, `manage payouts`). Following has its own rollout switch and no plan gate, so no Community gate can hide it.
- **Members counted toward the cap:** `pending`, `active` and `lapsed`; `exited` do not count.
- **Over the cap** (after a downgrade or cap change): nothing is deleted; existing members keep being charged and can pay; adding, importing and approving new members is blocked with a message naming the plan and the number.
- **Downgrades never remove** payment history, receipts, statements, existing liabilities, balances or payouts. Paid-for features turn read-only rather than disappearing (for example scheduled messages stay listed but cannot be created).

## 14. Console navigation groups

Groups collapse, remember their state, and hide when the organisation does not use them.

| Group | Items |
|---|---|
| (top) | Dashboard |
| **Events** | Events |
| **Community** | Overview, People, Money, Messages, Library, Polls |
| **Venue** (venue and facility hosts only) | Facility operations, Spaces, Calendar, Inquiries & leads, RFQs & proposals |
| **Finance** | Overview and reports, Payouts, Refunds |
| **Organisation** | Team, Roles, Billing, Settings, API keys, AI usage and configuration |
| (bottom) | My account |

## 15. Implementation handoff

For Claude, Codex or a person continuing this work.

- **Baselines:** `main` at `c9da26e` (milestone 1 of the dues design deployed); `member-dues-m2` at `61224d2` (milestone 2 built, unmerged, dark); this spec on `community-design`.
- **Decisions:** section 3. **Unresolved:** none blocking milestone 1a. To confirm when their milestones are planned: the default reminder cadence for daily payers (daily or weekly digest); whether lapsed members may buy at member prices.
- **Compatibility requirements:** existing `/pay/{token}` links, callbacks and receipts keep working; Paystack metadata `type = member_dues` keeps being accepted; existing event contributions, receipts and `/my` history unchanged by any migration; the probe tenant's live records untouched.
- **Per milestone, acceptance criteria and regression tests** are written into its implementation plan. Always green: Finance, Payments, Dues, Events and attendee-portal suites; `PayoutOtpFlowTest`; `finance:verify-categories` exits 0.
- **Rollout:** each milestone behind its own switch, enabled first for an internal tenant, then per organisation.
- **Operational checks after each deploy:** `finance:verify-categories`; `payouts:reconcile` runs cleanly; scheduler health; a test checkout in Paystack test mode.
- **Recovery:** switch the milestone off (no data loss); migrations reversible; money migrations come with a reconciliation report and a documented rollback.
- **Local test state on the owner's machine** (from the milestone 2 click-through): see the project memory file `member_dues_design_decisions.md`.
