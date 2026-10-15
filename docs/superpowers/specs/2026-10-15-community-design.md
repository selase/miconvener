# Community: design

Date: 2026-10-15. Status: draft for review. Path: architectural (new console section, changes the people model, generalises event-only tools).

Supersedes the scope of `2026-10-13-member-dues-design.md` from its milestone 3 onward. The dues engine built in milestone 2 (branch `member-dues-m2`, not merged) is kept and re-homed here; section 9 lists what changes.

## 1. Purpose

Organisations of every kind (unions, drivers' and teachers' associations, alumni groups, churches) need an ongoing relationship with their people, not only with event attendees. They want to keep a register, collect dues or giving, message people, share documents and ask their opinion, with money reported the same way ticket money is.

Success looks like this:

- An organisation can run its whole community from one console section without learning a second set of tools.
- A person sees everything they have with any organisation (events, dues, giving, messages, documents, polls) in one place, `/my`.
- Every cedi is reported in one Finance section, by category, with statements an organisation can hand to its members.
- Nothing about the app becomes harder to reason about. Every entry point has one obvious job.

## 2. The model (the rule everything else follows)

| Who | Where | What lives there |
|---|---|---|
| Organisation admin | **Events** | Things that happen on a date: tickets, agenda, check-in, live polls. Unchanged. |
| | **Community** | People and the ongoing relationship: followers, members, groups, dues, giving, messages, library, polls and surveys. New. |
| | **Finance** | All money in one place: events, dues and giving; reports; every payout. Extended. |
| Member, attendee, follower | **`/my`** | Everything about me across every organisation. Extended. |
| Anyone | Organisation page, event pages | Follow, Join, Give, Register. Extended. |

**One engine per tool.** Messages, library (documents), polls and surveys, and reports are each built once. Used inside an event, the audience is that event's attendees. Used in Community, the audience is followers, all members, a group, members in arrears, or an event's attendees. An admin learns each tool once.

**No second events place.** Community does not get its own events. It connects to Events: a new event can be announced to followers and members; an event can be members-only or have a member price; Community's overview lists upcoming events and links into Events.

## 3. Decisions made (2026-10-15, with the owner)

| Topic | Decision |
|---|---|
| Name | The console section is **Community**, with its own sub-menus like Events (six items, section 4). |
| Following | Luma-style: anyone can follow an organisation from its page or any of its events. Followers hear about new events and can be messaged. |
| Membership | A register on top of people. A member is a person with a membership type, a status and groups. |
| Dues | Optional per membership type, so churches and free alumni groups fit. Dues engine from milestone 2 is reused. |
| Giving | Voluntary money goes to **funds**. A fund is open-ended (Tithes, Offerings) or a **campaign** with a target and deadline (Funeral support, New roof). Event contributions become a fund linked to an event. |
| Finance | One section for all money, reported by category; the separate "Dues payouts" menu from milestone 2 moves into Finance. |
| Person's base | `/my` gains a Communities tab. Pay links keep working without sign-in. |
| Polls and surveys | Live polls stay in Events. Community gets polls and surveys sent to an audience, answered in `/my` or from a link. Votes with eligibility rules come later. |
| Simplicity | The owner's explicit constraint: every entry point must be simple to reason about and never confusing. |

## 4. Community sub-menus

Six items, so the section stays as easy to scan as Events: **Overview · People · Money · Messages · Library · Polls**. Settings live in a tab on Overview. An item the organisation has not switched on (for example Money for a church that collects nothing online) is hidden.

- **Overview:** members, followers, collections this month, arrears, upcoming events (links to Events), recent messages; settings tab (follow/join options, reminder schedule, branding).
- **People:** one list with tabs **Members · Followers · Groups**. Members is the register (milestone 2 screens, re-homed). Groups are branches, chapters, stations, ministries; a person can be in many; groups are audiences everywhere. Named "People", never "Team" (Team stays the organisation's staff).
- **Money:** tabs **Dues · Giving**. Dues: membership types with an optional amount and period, charges, waive, void, record cash. Giving: funds and campaigns, a public give page per fund, gifts, and **bulk cash entry** (a Sunday collection counted in one sum with no donor). Reports and payouts are in Finance, linked from here.
- **Messages:** compose to an audience by email and SMS, send now or schedule, using the organisation's credits (generalised from event blasts).
- **Library:** documents for an audience (constitution, minutes, notices), generalised from event materials.
- **Polls:** polls and surveys to an audience, results and export.

## 5. Money

Every cedi coming in through Community is either **owed** or **given**.

| | Dues (owed) | Giving (voluntary) |
|---|---|---|
| Examples | Union and association dues | Tithes, offerings, donations, building fund, funeral support, a project |
| Shape | A member owes an amount per period | A gift to a fund; a campaign fund has a target and a deadline |
| Arrears | Yes | No (pledges may come later) |
| Ledger category | `dues` (exists) | `giving` (new, carries the fund) |
| Commission | 1%, uncapped, borne by the organisation (agreed) | Open decision (contributions are 1% today) |

Cash and transfer payments recorded by an admin carry no commission and post no ledger entry, as for dues today.

**Reporting, all under Finance:**

- Collections: money in by category (events, dues, giving), by fund and by period; gross, fees, commission, net.
- Arrears: who owes what, per group or membership type.
- Member statement: a person's dues paid and gifts for a period, downloadable (churches issue annual giving statements).
- Donors per fund, respecting anonymity.
- Payouts: every payout with its category.
- CSV export of each.

**Reports read the domain records, not the ledger.** For an own-gateway organisation the ledger books only the commission, because the money never passes through the platform; gross collections live on the charges, gifts and registrations. Reports therefore sum those records (gross, fees, commission, net) and use the ledger only for balances and payouts. This is already how the event finance page works (it reads event ledger entries or merchant transactions depending on the settlement mode).

**Giving specifics:** anyone can give, not only members (visitors at a church); gifts can be anonymous; recurring giving is a reminder with a pay link, because mobile money cannot be auto-charged; cash collected in bulk is entered as one sum with no donor and appears in collections but not on any member statement.

The tenant Finance page currently returns 403 unless the plan handles ticket money; it must open for any organisation that collects dues or giving.

## 6. People and `/my`

- **There is no person table, and none is added.** `/my` today has no accounts: a person proves an email address with a code, and `/my` finds their registrations and contributions in every organisation by that normalised email. Community follows the same rule: "me" is a verified email **or a verified phone number**, and `/my` finds the follows, memberships, charges and gifts that carry it. Members and followers keep their own name, email and phone, as milestone 2's members already do. This avoids identity merging, keeps phone-only members first-class, and matches how `/my` already works.
- `/my` gains **Communities**: for each organisation I follow or belong to, my dues and a pay button, my giving history and statements, messages, the library and open polls.
- **Phone sign-in.** `/my` signs in by an email code today. Many drivers and church members have only a phone, so `/my` needs an SMS code sign-in. The SMS integration exists.
- Pay links in reminders keep working without sign-in.
- Following is consent: messaging followers is opt-in and every message carries an unfollow link.

## 7. Polls, surveys and votes

- Live polls and poll decks stay in Events (they need a live session).
- Community polls and surveys go to an audience and are answered in `/my` or from a link, built on the existing poll and form engines lifted to organisation level.
- Votes (for example AGM elections) need one member one vote and eligibility rules such as paid-up members only. Later milestone; needs its own design.

## 8. Out of scope for now

Payroll check-off for unions, recurring card auto-charge for dues, partial and advance dues payments, pledges, votes, a chat or forum for communities, multi-level chapter hierarchies (groups are flat).

## 9. What changes in milestone 2's code

Milestone 2 (`member-dues-m2`, 8 tasks plus fixes, 2377 tests green, clicked through end to end in Paystack test mode) is kept. Changes, cheap because nothing is deployed:

- `members` keeps its own name, email and phone (no person table, see section 6); phone and email are normalised so `/my` can find them.
- `dues_classes` becomes **membership types** with an optional amount and period; a type without an amount raises no charges.
- "Dues payouts" nav moves into Finance as a payouts view filtered by category.
- Console routes move under Community (`tenant.community.*`), and the `member_dues` gate becomes a `community` gate.
- The weekly period (drivers' associations often collect weekly) is added to the period enum.

## 10. Milestones (re-cut; each deployable and dark-launched)

Ordered by what makes the product usable for a paying association soonest; Following comes once there is something to follow for.

1. **Community shell and usable dues.** Re-home milestone 2 under Community (section, gate, routes, membership types with optional amount, weekly period); `dues:process` charge generation; reminders and the arrears button; CSV import; payouts moved into Finance; Finance page open to non-ticket organisations with a dues collections and arrears report.
2. **Messages and groups.** Organisation-level messages (generalised blasts) with audiences (all members, group, arrears) and scheduling; groups.
3. **People and Following.** Follow button on organisation and event pages (opt-in), followers tab, announcing new events to followers, `/my` Communities tab, SMS sign-in for `/my`.
4. **Giving.** Funds and campaigns, give pages, bulk cash entry, giving reports and member statements. Event contributions stay as they are and appear in the same reports; converting them into event-linked funds is a later, separate step (existing receipts, tributes and `/my` history depend on them).
5. **Library and polls.** Organisation-level library and polls/surveys, answered in `/my`.

Votes, pledges and chapter hierarchies come after, each with its own design.

## 11. Open decisions

1. Commission on giving (1% like contributions, or different).
2. Whether milestone 2 merges dark before milestone 1 of this plan, or the re-homing happens on its branch first. Recommendation: re-home on the branch, then merge.
3. Whether followers who never attended an event can be messaged by SMS (cost and consent).
4. Whether a person may see, in `/my`, organisations they were added to as a member but never followed.
5. Plans and pricing for Community: which plan includes it, member cap (the dues design said Starter 200, Growth 2,000, Enterprise unlimited), any follower cap, and whether giving needs a higher plan.
6. Daily dues periods (some drivers' unions collect daily at stations) in addition to weekly.

## 12. Risks

- **Confusion between People, Members and Followers.** Mitigation: one People list with clear filters; a member is always a person; a follower is a person who opted in.
- **Generalising event tools could break events.** Mitigation: the event tools become callers of the shared engine with the audience fixed to the event; every existing event test must stay green.
- **Consent and data protection.** Members are added by the organisation, not by themselves. Dues reminders and receipts are service messages; anything else (announcements, newsletters) to members or followers needs an opt-out link, and the privacy page names the organisation as controller and MiConvener as processor.
- **SMS sign-in cost and abuse.** Each `/my` sign-in code by SMS costs the platform a credit; it needs per-number and per-IP throttles.
- **Name clashes.** The console already has "Inquiries & Leads" (venue enquiries) and "Team" (staff). Community must not reuse "leads", "contacts" or "team".
- **Payment-processor classification** for dues and giving (Paystack): confirm before enabling for real organisations (carried from the dues design).
