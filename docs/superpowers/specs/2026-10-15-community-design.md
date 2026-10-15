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
| Name | The console section is **Community**, with its own sub-menus like Events. |
| Following | Luma-style: anyone can follow an organisation from its page or any of its events. Followers hear about new events and can be messaged. |
| Membership | A register on top of people. A member is a person with a membership type, a status and groups. |
| Dues | Optional per membership type, so churches and free alumni groups fit. Dues engine from milestone 2 is reused. |
| Giving | Voluntary money goes to **funds**. A fund is open-ended (Tithes, Offerings) or a **campaign** with a target and deadline (Funeral support, New roof). Event contributions become a fund linked to an event. |
| Finance | One section for all money, reported by category; the separate "Dues payouts" menu from milestone 2 moves into Finance. |
| Person's base | `/my` gains a Communities tab. Pay links keep working without sign-in. |
| Polls and surveys | Live polls stay in Events. Community gets polls and surveys sent to an audience, answered in `/my` or from a link. Votes with eligibility rules come later. |
| Simplicity | The owner's explicit constraint: every entry point must be simple to reason about and never confusing. |

## 4. Community sub-menus

Overview · People · Members · Groups · Dues · Giving · Messages · Library · Polls & surveys · Settings.

- **Overview:** members, followers, collections this month, arrears, upcoming events (links to Events), recent messages.
- **People:** everyone the organisation has a relationship with (followers, members, past attendees who opted in). One list, filterable.
- **Members:** the register (milestone 2 screens, re-homed).
- **Groups:** branches, chapters, stations, ministries, classes of members. A person can be in many groups. Used as an audience everywhere.
- **Dues:** membership types and their amounts and periods, charges, waive, void, record cash (milestone 2).
- **Giving:** funds and campaigns, a public give page per fund, gifts list.
- **Messages:** compose to an audience, by email and SMS, send now or schedule, using the organisation's credits (generalised from event blasts).
- **Library:** documents for an audience (constitution, minutes, notices), generalised from event materials.
- **Polls & surveys:** to an audience, results and export.
- **Settings:** follow/join options, reminder schedule, branding on member-facing pages.

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

The tenant Finance page currently returns 403 unless the plan handles ticket money; it must open for any organisation that collects dues or giving.

## 6. People and `/my`

- A **person** is the platform-wide attendee identity already used by `/my` (central, cross-tenant). Followers and members link to it; the organisation never stores a second copy of someone's contact details.
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

- `members` links to a person identity instead of holding its own name, email and phone (or holds them only until the person is linked).
- `dues_classes` becomes **membership types** with an optional amount and period; a type without an amount raises no charges.
- "Dues payouts" nav moves into Finance as a payouts view filtered by category.
- Console routes move under Community (`tenant.community.*`), and the `member_dues` gate becomes a `community` gate.
- The weekly period (drivers' associations often collect weekly) is added to the period enum.

## 10. Milestones (re-cut; each deployable and dark-launched)

1. **People and Following.** Person link, follow button on organisation and event pages, followers list, People screen, `/my` Communities tab (read-only), SMS sign-in for `/my`. Re-home milestone 2 under Community with membership types.
2. **Messages and automatic dues.** Organisation-level messages (generalised blasts) with audiences and scheduling; `dues:process` charge generation; reminders; arrears button.
3. **Groups, import and reports.** Groups as audiences, CSV import, Finance reports and statements, Finance page open to non-ticket organisations, payouts unified.
4. **Giving.** Funds and campaigns, give pages, event contributions as event-linked funds, giving statements.
5. **Library and polls.** Organisation-level library and polls/surveys, answered in `/my`.

Votes, pledges and chapter hierarchies come after, each with its own design.

## 11. Open decisions

1. Commission on giving (1% like contributions, or different).
2. Whether milestone 2 merges dark before milestone 1 of this plan, or the re-homing happens on its branch first. Recommendation: re-home on the branch, then merge.
3. Whether followers who never attended an event can be messaged by SMS (cost and consent).
4. Whether a person may see, in `/my`, organisations they were added to as a member but never followed.

## 12. Risks

- **Confusion between People, Members and Followers.** Mitigation: one People list with clear filters; a member is always a person; a follower is a person who opted in.
- **Generalising event tools could break events.** Mitigation: the event tools become callers of the shared engine with the audience fixed to the event; every existing event test must stay green.
- **Payment-processor classification** for dues and giving (Paystack): confirm before enabling for real organisations (carried from the dues design).
