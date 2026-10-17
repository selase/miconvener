# Community inbox: design

Date: 2026-10-17. Status: approved by the owner 2026-10-17 (section 13 as recommended, with two owner changes: followers are email-first with SMS as an added channel, and dues reminders and receipts are in scope). Author: Claude (design only; no application code). Path: architectural (new records, new access surface in `/my`).

Builds on `docs/superpowers/specs/2026-10-15-community-design.md` (sections 6.1 identity and access, 6.2 `/my` as an app, 6.3 following, 8 audiences and consent) and on what milestones 2 and 3 shipped (`CommunityMessage`, `CommunityMessageDelivery`, `CommunityMessages`, `AudienceResolver`, `MemberAudienceSource`, `FollowerAudienceSource`, `MessagingPreferences`, `CommunityFollower`, `Following`, `MemberAccess`, `CommunityAccessCredential`). Milestone 4 (`codex/community-4`) ships `/my` with "Messages arrive by email and SMS" and no unread badge, and proposes amending spec 6.2 accordingly (its decision 1). This design is the inbox that milestone deferred; whichever ships second updates spec 6.2.

## 1. Purpose

People should be able to open `/my` and read what their organisations sent them, see what is new, and mark it read, including when their email went to spam or their SMS was skipped for lack of credit.

Success looks like this:

- A member or follower sees, per organisation, the messages and notices addressed to them (announcements, dues reminders, payment receipts), newest first, with unread ones marked.
- Nobody ever sees a message addressed to someone else, including a family member on the same phone or the previous holder of a reassigned number.
- Reading a message marks it read for that reader; one message never shows twice to the same reader.
- An organisation that recalls or loses access to messaging does not leave messages readable.

## 2. Why delivery records cannot be the inbox

`community_message_deliveries` is one row per message, channel and address, unique on `(message_id, channel, address_hash)`. It answers "what did we try to send to this address and what happened". It cannot be an inbox because:

- It is keyed by address, not by recipient. Two members sharing an email or a phone share one delivery row.
- A phone number is a delivery destination, not a credential. Under spec 6.1, phone proof alone never unlocks existing private records; using delivery rows as inbox rows would turn a number into access to its delivery history.
- "Delivered" is not "read", and one person reached by email and SMS has two delivery rows for one message.

So the inbox gets its own recipient records, created as a message is sent, alongside (not instead of) deliveries.

## 3. Scope

**In the first version:**

- **Announcements:** organisation messages composed in Community > Messages (milestone 2) and follower announcements of new events (milestone 3, `purpose = following_event`), to members and followers.
- **Notices (owner decision 2026-10-17):** dues reminders (milestone 1c `dues_reminders`, including the arrears button) and dues payment receipts, to members. A notice appears as soon as it is queued for the member, whatever happens to its email or SMS (sent, skipped for lack of credit, failed), so the inbox is where a member always finds it.
- Per-organisation unread counts, a list, a message view and "mark all read" in `/my`.
- Organisation-side withdraw (announcements) and totals.

**Followers are email-first (owner decision 2026-10-17).** Email is the primary channel; there are no SMS-only followers. Following requires a verified email; SMS is an optional added channel on that same follow, and the follower's inbox is reached through the email. This amends spec 6.3 and milestone 3's address-scoped follow (one follow row per channel and address): see section 5.2 and section 14 for what changes. Members are unchanged: a member may still be reachable only by phone (spec 6.1) and reads their inbox through their member credential (section 5.1).

**Not in the first version:**

- Event blasts and event-attendee audiences (not members or followers; no recipient record).
- Replies or messages from a person to an organisation; push notifications; email digests.
- Backfill of anything sent before the inbox is switched on.
- Sending an announcement to an audience with no deliverable email or phone at all. `CommunityMessages::queue()` refuses a send when no address resolves, and this design keeps that rule.

## 4. Records

### 4.1 Inbox items

`community_inbox_items`: one row per message per recipient record.

| Column | Notes |
|---|---|
| `id` | uuid |
| `tenant_id` | the organisation |
| `kind` | `announcement`, `dues_reminder` or `dues_receipt` |
| `message_id` | for announcements: the `community_messages` row; content is read from it, not copied. Null for notices |
| `source_type`, `source_id` | for notices: the originating record (`dues_reminder` day key, or the paid charge/payment reference); unique per recipient so retries never duplicate |
| `subject`, `body` | for notices only: a snapshot written when the notice is queued (amounts, periods and due dates as they were), so later changes never rewrite history. Never contains a pay-link token |
| `recipient_type` | `member` or `follower` |
| `member_id` | set when `recipient_type = member`; foreign key, cascade on delete |
| `follower_id` | set when `recipient_type = follower`; foreign key, cascade on delete |
| `available_at` | the message's `started_at`; ordering key |
| `withdrawn_at` | set on withdraw, cancel or takedown |
| `created_at` | |

- Unique `(message_id, member_id)` and unique `(message_id, follower_id)` for announcements; unique `(kind, source_type, source_id, member_id)` for notices. A check that exactly one of `member_id` / `follower_id` is set and matches `recipient_type`, and that notices are member-only and have `subject`/`body` while announcements have `message_id`.
- Indexes `(member_id, available_at)` and `(follower_id, available_at)` (cross-organisation `/my` queries filter by recipient, not tenant), plus `(message_id)`.
- No phone, email or address is stored on an item. Access is decided by the recipient record (section 5).

### 4.2 Read receipts

`community_inbox_reads`: one row per message per reader.

| Column | Notes |
|---|---|
| `entry_key` | the announcement's `message_id`, or the notice item's own `id`; announcements read per message so a member item and a follower item for the same message read together (section 6.3) |
| `reader_key` | HMAC (section 5.3); never a raw email, phone or credential |
| `read_at` | |

- Unique `(entry_key, reader_key)`; deleted with the items they refer to.
- Unread for a reader = a visible item for that message and no read row for that reader and message.

## 5. Access rules

The inbox adds no new way to prove identity; it reuses spec 6.1 through `MemberAccess` and the history queries `/my` already uses.

### 5.1 Member items

A member item is visible in a session only when `MemberAccess::canView(session, member)` is true **on that request**:

- a current verified email exactly matching the member's current email; or
- a current phone proof plus a live credential session for that exact member (self or representative), with the member's current `access_version`, and no confirmed wrong-number hold on the number.

Inherited consequences:

- **Shared family numbers:** sharing a phone grants nothing; each member's items need access to that member.
- **Representatives:** see the represented member's items, labelled "For {member name} (representative)", read-only.
- **Contact changes:** any phone or email change bumps `access_version`; direct (self) phone credentials are revoked and representative credentials are suspended until reconfirmed (`Member::updated`). The old access sees nothing on the next request; the new holder of an old number gets nothing without an independent claim. A removed email loses exact-match access immediately.
- **Statuses:** members keep read access to their past items whatever their status. They receive new items only if the message's audience includes them; an organisation can deliberately message lapsed or exited members through a status audience, and they then receive items.
- **Opt-out after delivery:** an existing item stays readable after the member opts out of marketing; opting out affects only future messages.

### 5.2 Follower items

Every follow has a verified email (section 3). A follower's items are visible to a session holding a current verified email that exactly matches the follow's email, and only items with `available_at` at or after the follow's current `consented_at`. Re-following resets `consented_at`, so it never brings back items from before an unfollow. An unfollowed follow sees no items. Phone proof alone never opens follower items; SMS on a follow is a delivery channel only. Reassigned email inboxes keep the existing mailbox trust model (spec 6.1).

### 5.3 Reader key

`reader_key = HMAC(app key, "community-inbox-reader", subject)`, with the subject chosen in this order for the item being read:

1. `member:{member_id}:self` when the session has self access to the member (exact email, or a self credential). Read state is shared across the member's own email and phone access and survives credential rotation.
2. `member:{member_id}:rep:{representative phone HMAC}` when access is only through a representative credential. Stable across that representative's credential rotation; separate from the member's own state and from other representatives.
3. `email:{normalised verified email}` for follower items.

### 5.4 Every request re-checks

List, count, view and mark-read endpoints re-evaluate access, organisation state (section 8) and message state (section 6.2) on every request. Nothing is cached in the session, browser storage or the service worker.

## 6. Creating and showing items

### 6.1 When items are created

- **Materialised while sending.** `CommunityMessages::dispatchDue` resolves recipients page by page while a message is `sending`. In the same pages it creates one item per eligible member record and per eligible follower record (unique keys make re-runs and retries safe). Event-attendee sources produce no items.
- **Record-level resolution.** `AudienceResolver` resolves addresses per channel today; add a record-level resolution (`recipients(Tenant, selector)`) returning member and follower records with the same tenant and permission rules, deduplicated by record.
- **Eligibility within the send is channel-independent.** A follower is excluded when its address has opted out of marketing or unfollowed. A member is excluded only when every address on the record has opted out of marketing at this organisation (opt-outs are per channel and address in `MessagingPreferences`). A member whose SMS was skipped for credit, or whose email bounced, still gets an item.
- **Notices.** When `DuesReminders` records a reminder for a member (automatic or the arrears button), the first reminder row for that member and organisation-local day creates one `dues_reminder` item (whatever its channels and their outcome: sent, skipped, failed). Its snapshot lists the open charges included, the total and the oldest due date, with a "Pay in MiConvener" action that opens `/my` > Money rather than a pay link. When a dues payment is confirmed (`DuesPaymentConfirmer`, or an admin recording cash), one `dues_receipt` item is created per payment. Notices are service messages: marketing opt-outs do not affect them; `community_settings.reminders_enabled = false` stops reminder items along with the reminders.
- **Only while the inbox is on** (section 8). Nothing is backfilled when it is switched on later.

### 6.2 When items are visible

An item is visible only when all hold:

- for an announcement, its message status is `sending`, `completed` or `partial` (`draft`, `scheduled`, `paused` and `cancelled` show nothing); a notice is visible from the moment it is queued;
- `withdrawn_at` is null;
- the organisation passes section 8;
- the reader has access (section 5).

**Cancel and pause.** `CommunityMessages::cancel()` (allowed until `completed` or `partial`) also sets `withdrawn_at` on every item of that message, so a send cancelled after its first pages leaves nothing behind. A `paused` message (permission, plan or rollout change mid-send) hides its items until it is sent again; if it never resumes, the organisation withdraws it.

### 6.3 One message, one entry per reader

A notice is always its own entry. For announcements, a person can hold several items for one message: a member item and a follower item at the same organisation, or their own item and a represented member's item. Lists and counts show **one entry per message per reader context**: own items (self member and follower) merge into one entry; each represented member's item is a separate, labelled entry. Opening an entry writes read rows for every reader key that applies to the items merged into it, so a person who is both member and follower sees the message once and reads it once.

## 7. Read and unread

- **Read** means the person opened the message view; the client then POSTs to mark it read. Lists, previews and link prefetching never mark anything read.
- **Mark all read** per organisation marks every visible entry read for that reader.
- **Email and SMS do not mark the in-app copy read** (no tracking pixels or tracked links).
- **Counts** are per reader, per organisation, over visible entries only, shown as `99+` above 99.
- Mark-read and mark-all use the existing `/my` access throttle.

## 8. Organisation state, rollout and plan gating

- **Switch:** `community_inbox` (admin-override feature row, like `community:enable`), off by default. It takes effect only where milestone 4's `community_app` switch is also on, since the legacy `/my` has nowhere to show messages. Items are created only while both are on.
- **Visibility needs:** the organisation's `community_inbox` and `community_app` on, and the organisation active (not suspended or taken down). Switching any of these off hides the inbox for that organisation without deleting anything; switching back on shows existing items again. Switching off `community_messages` or `community_following` stops new items but leaves existing ones readable while the inbox is on.
- **Superadmin takedown** of a message sets `withdrawn_at` on its items.
- **Plan:** no extra plan gate (it reduces SMS cost for organisations and support load for us).

## 9. In `/my`

Fits milestone 4's app shell without a new tab:

- **Home:** a "New messages" card listing organisations with unread counts. It needs its own reader that works for email and phone-credential sessions (milestone 4's Home attention data comes from a verified-email reader).
- **Communities:** each organisation card shows its unread count and opens that organisation's message list (newest first, unread marked, "Mark all read"). Milestone 4's plan has no per-organisation detail page; the inbox milestone adds a messages list reachable from the organisation card (a sheet on phones, the detail column on desktop).
- **Message view:** organisation name and logo, subject, sent date, body (plain text with safe link formatting, as the email body), and the representative label when it applies.
- **Withdrawn or hidden messages** disappear from lists; a direct link shows "This message is no longer available".
- **Offline:** never cached by the service worker (spec 6.2).
- **Accessibility and states:** as spec 6.2.

Enumerating what a person can see reuses the history queries `/my` already has (`CommunityHistory::memberQuery()` and `followerQuery()` for exact-email matches across organisations) plus the member credentials held in the session; `MemberAccess::canView` is then applied per member.

## 10. Organisation side

- **Community > Messages**, per sent message: "In inbox: N" counts only recipient records with at least one email or phone, and "Read by M" appears only when N is at least 10, so a small group cannot reveal who read it. No per-person read list.
- **Withdraw** (new, after completion): sets `withdrawn_at`; deliveries already sent are unaffected. Requires `send community messages` and the Community switch, but not `community_messages`, so an organisation can still withdraw after switching messaging off.
- **Retention (new):** items and reads are pruned 24 months after `available_at` by a scheduled command. `community_messages` rows are not pruned by this design.
- **Erasure:** deleting a member or follower deletes their items (cascade). Read rows are pseudonymous personal data; erasing a person also deletes read rows for their reader keys.

## 11. Risks

- **Leaking a message to the wrong person.** Mitigation: items keyed to recipient records, access only through `MemberAccess` and exact follower-email rules, no address on items, no SMS-only followers, every-request re-checks, the tests in section 12.
- **Recalled or suspended content staying readable.** Mitigation: visibility tied to message status, withdraw/cancel/takedown and organisation state (sections 6.2, 8).
- **Write volume.** 5,000 recipients create 5,000 items inside the existing paged send. Reads are written only on opening.
- **Count cost.** Recipient-first indexes; per-organisation counts over the person's accessible members and email follows, then per-message dedupe.

## 12. Acceptance tests (for the implementation plan)

1. Two members share a phone: each sees only their own items.
2. Phone proof without a credential shows no member items; a confirmed wrong-number hold blocks credential access.
3. A member's phone or email changes: the old access sees nothing on the next request; a representative is suspended until reconfirmed.
4. A representative sees the represented member's items labelled; reading marks only the representative's state.
5. Exact current email sees items; a removed email loses access immediately.
6. Self access by email then by phone credential shares one read state; credential rotation keeps it.
7. Follower: items before `consented_at` hidden; unfollow hides all; re-follow shows only new items.
8. A member opted out on SMS only still gets an item; opted out on every address does not.
9. A member whose SMS was skipped for credit still gets an item.
10. Re-running a page of the send creates no duplicate items.
11. Cancel mid-send withdraws every item already created; paused and draft messages show nothing.
12. A member who is also a follower sees the message once and one read clears it.
13. Withdrawn, taken-down and organisation-suspended messages disappear from lists and counts; direct links show the unavailable notice.
14. Inbox switch on but `community_app` off: no items created; turning the inbox off hides items; turning it on again shows them.
15. Guessed item or message ids from another organisation or member return 404.
16. Mark-read is POST only and throttled; list views never mark read.
17. The service worker never caches inbox responses; sign-out clears personal caches.
18. A status audience to exited members creates items for them; their older items stay readable.
19. Deleting a member deletes their items; "Read by M" is hidden below 10 recipients.
20. A dues reminder skipped for SMS credit still creates one inbox notice; email plus SMS on the same day create one notice, not two; the arrears button adds no second notice that day.
21. A notice's snapshot does not change when the charge is later paid, waived or re-priced; it contains no pay-link token.
22. A confirmed online payment and a recorded cash payment each create one receipt notice; retries create none.
23. Reminders switched off create no reminder notices; marketing opt-out does not stop notices.
24. Following without a verified email is refused; adding SMS to a follow requires the email follow first; phone proof alone shows no follower items.

## 13. Decisions (owner, 2026-10-17)

1. **Scope:** announcements plus notices: dues reminders and payment receipts, shown as soon as they are queued whatever the email or SMS outcome (owner change to the recommendation).
2. **Followers:** email-first; no SMS-only followers; SMS is an added channel on an email follow (owner change; replaces the "leave SMS followers out" option).
3. **Organisation read statistics:** totals only, hidden below 10 recipients.
4. **Retention:** 24 months.
5. **Placement:** Home card plus per-organisation list from Communities; no sixth tab.
6. **Cancelled messages:** cancelling mid-send withdraws what was already in inboxes.

**To confirm when planning:** whether a member should also see the delivery outcome of a notice ("emailed", "texted", "SMS not sent"); this design shows the notice itself only, not its delivery outcome.

## 14. Where it fits

After milestone 4 (the `/my` app shell and `community_app`), as its own milestone ("4b Inbox"). It depends on milestones 1c, 2, 3 and 4 as deployed. The implementation plan is written against the code as it is then.

**Change to milestone 3 behaviour (email-first follows).** Today a follow is one row per channel and address, and a phone-only follow is possible when phone entry is on (it is off in production). The inbox milestone (or an earlier small change, if the owner prefers) must: require a verified email to follow; attach SMS to an email follow as an optional channel rather than a separate follower; refuse new SMS-only follows; and handle any existing SMS-only follow rows (expected to be none in production, since phone entry is off; verify before migrating, and ask the owner before converting or retiring any). Spec 6.3 is updated in the same commit.
