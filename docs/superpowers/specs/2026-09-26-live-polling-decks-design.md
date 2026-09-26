# Live Polling Decks — Design Spec

**Date:** 2026-09-26 · **Status:** awaiting owner approval (revision 3) · **Path:** architectural

**Goal:** a Mentimeter-grade live polling experience inside MiConvener, so an organiser running a
congress never needs a second subscription to ask a room a question.

Facts about the current system were read from the code on 2026-09-26. Where this spec and the code
disagree later, the code wins — re-verify before relying on a claim.

---

## 1. What exists, and what is missing

**Exists and works:** three poll types (multiple choice, open text, quiz); one poll live at a time
per event; an organiser panel with bars, a moderation queue and a quiz leaderboard; a presentation
screen reached by a revocable token; results broadcast over Reverb, measured at ~0.8s end to end.

**Missing, and why each matters:**

| Missing | Consequence today |
|---|---|
| A deck — an ordered sequence | An organiser sets each poll live by hand, one at a time |
| Presenter controls | No next/previous, no close-and-reveal, no sense of position |
| A working QR on the wall | **The one shipped 2026-09-25 opens raw JSON** (§8) |
| Any check-in for online attendees | **Nobody at a virtual event can be marked present** (§6) |
| An email on speakers | A speaker cannot be recognised by who they are (§5) |
| Question types beyond three | No scale, rating, ranking, word cloud, number or multi-select |
| Charts per type | Bars are the only visualisation |

---

## 2. Decisions taken with the owner

1. **Built on polls, not surveys.** A Mentimeter deck is a sequence of *short* questions, each
   answered by a whole room at the presenter's pace. A survey is one form, many questions,
   self-paced, submitted once, read afterwards. The shape wanted is the shape polls already have;
   what is missing is the sequence and the types. Surveys keep their own job.
2. **Only registered, present people vote** — attendees, support staff and speakers alike. This
   replaces an earlier decision to allow anonymous voting. It buys one vote per person, results
   that can be trusted, and answers that can count toward attendance or CME. It costs reach, and
   §6 is about paying that cost honestly rather than pretending it is free.

   *Support staff vote by being registered*, comped, like any other attendee. Console users are
   not registrations and have no ticket, so a steward who should be able to answer a question is
   given one — which is also true of their badge, their lunch and their certificate. No code is
   needed for this: a zero-amount registration is a shape that already exists.
3. **Speakers are attendees.** A speaker checks in, wears a badge, eats lunch, attends other
   talks, wants the materials, gets a certificate and fills the post-event survey. That is the
   attendee portal, already built. Speakers therefore live in `/my` with a speaker section, not in
   a separate workspace, and are registered like everyone else — usually by the organiser on their
   behalf, and usually without paying.
4. **Results do not stream to voters' phones.** The wall is the shared surface; a phone in a hall
   is for answering. This keeps socket count flat as audiences grow (§7.1).
5. **Organisers and speakers may both author and present.** A deck belongs to a session; whoever
   has rights over that session may build and run it.

---

## 3. Scope

**Part 1 — the room:** decks, presenter controls, the portal's poll tab following the presenter,
check-in for online attendees, and the capacity work in §7.

**Part 2 — the questions:** the seven new types with their inputs and charts.

**Out of scope:**

- **A separate Speakers Workspace.** Decision 3 removes the need for one: rebuilding check-in,
  badges, materials, certificates and surveys beside where they already work would be duplication.
  What remains genuinely speaker-specific — confirming attendance, uploading slides, conflict-of-
  interest disclosure, keeping a bio current — becomes a section of `/my`, and is specified
  separately from this document.
- **Q&A with upvotes.** The Forum already does this, with moderation and banning. A Forum slide in
  a deck is the later answer, not a second implementation.
- **A date question type.** Surveys have one; no live-room question is answered by it.
- **Survey results dashboard.** Needed, unrelated, separately specified.

---

## 4. The deck

### 4.1 Model

```text
poll_decks
  id uuid
  tenant_id uuid            BelongsToTenant
  event_id uuid
  session_id uuid nullable  the talk this belongs to
  title varchar
  join_code varchar(8)      selects a deck at a multi-track event; never a credential
  status                    draft | live | ended
  current_poll_id nullable  where the presenter is standing
  present_token varchar     revocable, as events.present_token already is
  created_at / updated_at
```

`event_polls` gains `deck_id` (nullable) and `position`. A poll with no deck behaves exactly as it
does now; the existing single-poll flow is not disturbed.

### 4.2 Presenter controls

The presenter holds the pace: **next**, **previous**, **close voting**, **reveal** (quiz),
**reopen**, **end deck**.

Advancing closes the outgoing poll and opens the incoming one in one transaction, then broadcasts.
Voting on a poll that is not `current` is **refused by the endpoint**, not merely hidden — a phone
left on a previous question must not be able to answer it.

A presentation in progress is not interrupted by session expiry (§5).

### 4.3 There is no join code, and no voting page

Mentimeter needs a code because it has no accounts: a code is how a stranger finds your poll.
Decision 2 removes that problem. A registered attendee is already signed in to `/my`, their
workspace already knows which event they are at, and it already has a **Live poll** tab that
takes a vote today.

So the portal *is* the voting surface. What the deck adds to it is following the presenter:
showing the current question, replacing it when the presenter advances, and refusing a stale one
at the endpoint rather than merely hiding it.

The QR and short link on the wall become a **convenience deep-link** into that tab — faster than
"open miconvener.com/my and find your event" when a speaker has thirty seconds of the room's
attention. They grant nothing. Someone who follows one without being signed in gets the portal's
ordinary sign-in, and someone not registered for that event is told so.

`poll_decks.join_code` therefore exists only to disambiguate **which deck** — useful at an event
running parallel tracks, where a delegate's workspace may offer more than one live question. It is
a selector, never a credential.

### 4.4 The cost of decision 2, stated plainly

Requiring registration means **every voter must have signed in**, which puts the emailed sign-in
code on the critical path for a whole room. Two consequences worth deciding with open eyes:

- Most attendees will already be verified — they signed in to see their ticket — and proof lasts
  12 hours, so a delegate who opened their ticket that morning votes without friction.
- Those who have not will each need an email round-trip at the moment a speaker asks a question.
  A congress starting its first poll may send several hundred codes within minutes. The per-address
  caps are untouched by volume (one code each), and the venue-address problem is fixed (§7.4) —
  but **outbound mail throughput becomes the constraint**, and should be checked against the mail
  provider's limits before an event of that size.

If that friction proves worse than the integrity it buys, the fallback is decision 2 reversed for
nominated questions — an organiser marking a particular poll as open to anyone. That is not built
in Part 1, and is recorded here as the known escape hatch rather than a plan.

---

## 5. Speakers

### 5.1 Identity

Speakers verify exactly as attendees do: an emailed six-digit code, the same
`PlatformAttendeeVerification`, the same session marker. No second identity system.

This requires one change, and the owner has made it a requirement rather than an option:
**every speaker must have an email address.**

`speakers` has no email column today — it holds name, title, organisation, bio and photo. That is
why the token link exists; there was no address to send anything to, and nothing currently emails
a speaker their link at all. Adding it lets a speaker be recognised in `/my`, and separately lets
the product email them a deadline.

Because rows already exist without one, the column arrives nullable and the **form requires it**,
so no new speaker can be created without an address while existing ones stay editable. The
organiser console shows which speakers are missing one, since a speaker without an address cannot
reach their portal and that should be visible rather than discovered at an event. Making the column
itself non-nullable waits until the backfill is done.

A speaker is matched to their registration by that address, normalised the same way as everywhere
else in the portal.

### 5.2 Registration

Speakers are registered like anyone else, ordinarily by the organiser on their behalf, and
ordinarily without paying. Nothing new is needed for this: a registration with a zero amount
already exists as a shape. A speaker who genuinely is not attending — a recorded contribution, a
withdrawn name — simply has no registration and therefore no portal, which is correct.

### 5.3 The token link survives, narrowed

The existing `portal_token` keeps working for **slide upload only**. It is long-lived and
forwardable; something that has sat in an inbox since the call for papers should not be able to
put words on the main screen. Deck authoring and presenting require a verified address.

### 5.4 The 12-hour window gets one exception

Proof is fixed, not sliding. A speaker who verifies at 08:00 to rehearse would be asked for a code
mid-deck at 20:00, in front of a room. While a deck is `live` and the verified speaker is its
presenter, authorisation holds until the deck ends or the session's end time passes. Nothing else
extends.

---

## 6. Presence, and the hole in it

Decision 2 requires people to be *present*, and presence today means `checked_in_at`, which is
written only by staff at a door: search, scan, or mark present from the console. **Nothing on the
attendee side ever writes it.** Events are either `in_person` or `virtual`.

So as things stand, a virtual event can check nobody in, and a remote attendee at an in-person
event never can be. Requiring check-in to vote would silently exclude them.

**Self check-in closes it.** An attendee marks themselves present from their event workspace,
available while the event is running, and recorded exactly as a door scan is — same column, same
attendance record, with the source noted so an organiser can tell a scan from a self-report.

- **Virtual events:** self check-in is the mechanism. Opening the workspace during the event offers
  it; it is not silently automatic, because "I am here" should be something a person says.
- **In-person events:** the door remains the norm. Self check-in is available as an organiser
  setting for events that do not staff a desk, and is off by default.

This is worth building beyond polling: a virtual event currently produces no attendance record at
all, which matters for CME and for certificates that claim hours.

---

## 7. Holding a thousand people

Each figure below was read or measured on 2026-09-26.

### 7.1 Sockets

The Reverb cluster holds **100 concurrent connections**. Because results do not stream to phones
(decision 4), only presenter screens hold sockets — one or two per event — so the tier covers
dozens of simultaneous events and connection count stays flat as audiences grow. That property
matters more than the money.

Voters get their acknowledgement in the response to their own vote.

### 7.2 Broadcasts are coalesced, not per vote

`PollResultsUpdated` currently fires on **every vote**, synchronously, inside the request. A
thousand people answering within ten seconds is a thousand synchronous calls to Reverb with voters
waiting on them.

Broadcasts become interval-coalesced: at most one per poll per second, carrying the current
aggregate. A wall updating once a second reads as live to a room; a thousand times in ten seconds
reads identically and costs a thousand times more.

### 7.3 Counting moves to SQL

`PollResults::forDisplay` loads every response into memory and filters the collection. Counts
become a `GROUP BY option_id` aggregate, with an index on `(poll_id, option_id)` —
`event_poll_responses` currently indexes `tenant_id` and uniquely `(poll_id, respondent_token)`.

### 7.4 Rate limits — done, 2026-09-26 (`d6cb49b`)

Shipped ahead of this spec because it was a live defect:

- **No proxies were trusted**, so the address read from every request was the load balancer's —
  the same value for every visitor on earth, making each per-IP limit one global limit shared by
  the whole internet. Fixed to trust all proxies as Laravel Cloud documents, with a test that
  fails without it.
- **Passing the challenge now earns passage** past the burst ceiling. A hall is one address shared
  by everyone in it, so volume cannot tell a congress from a script; only the challenge can. The
  daily figure became a backstop sized for a venue. Mailbox caps are untouched.

Decision 2 makes this load-bearing rather than incidental: a room that must sign in to vote is a
room that must all request codes from the same venue address at once.

### 7.5 Compute

Production runs one `flex-512mb` instance with autoscaling off. **The owner will enable
autoscaling once development finishes**; recorded so the dependency is not forgotten, and needing
nothing from this spec.

---

## 8. The QR on the wall, and a live bug

The QR on the presentation screen points at `/e/{event}/poll`, **which returns JSON** — the data
endpoint the public event page's widget fetches, not a page. Anyone scanning it in a room today
sees a blob. Shipped 2026-09-25; the tests asserted the join URL *contained* `/poll` and never that
it was somewhere a person could vote.

It becomes a deep-link into the attendee's own Live poll tab (§4.3). No new page is needed — the
portal already votes — so this is a link correction, not a feature.

**Lesson recorded:** asserting the shape of a URL is not asserting that the URL works.

Voting is limited **per registration**, not per address; per address is precisely what would
punish a WiFi crowd. The public vote endpoint currently has no limit at all, which decision 2
resolves as a side effect: an identified voter can be counted.

---

## 9. Question types

Ten types. Three exist; seven are new. Each needs a phone input and a wall chart, designed
together — a type is not finished because it can be answered.

| Type | Phone | Wall | Status |
|---|---|---|---|
| Multiple choice | tap one | horizontal bars | exists |
| Open text | free text | wall of answer cards | exists |
| Quiz | tap one, against a timer | bars, answer revealed on close, leaderboard | exists |
| Multiple select | tap several | bars as % of **people**, not votes | new |
| Yes / No | two large buttons | split bar | new |
| Rating | 1–5 stars | average, plus distribution | new |
| Scale | slider between labelled ends | line with average marked, spread shown | new |
| Number | numeric entry | average, median, range, histogram | new |
| Word cloud | one to three words | cloud, repeats grow | new |
| Ranking | drag into order | ranked bars by average position | new |

**Multiple select** reports percentages of respondents, not of votes: five options each chosen by
everyone is 100% five times, and a chart summing to 500% is a lie.

**Word cloud** normalises case and trims punctuation before counting, caps entries per respondent,
and moderates as open text does — an unapproved word never reaches the wall.

**Ranking** scores by average position and states the respondent count, because an average
position over four voters is not a result.

---

## 10. Privacy

Decision 2 makes every vote attributable in the database. That raises the stakes on what leaves it.

The presentation screen is opened by a token, on a machine with no login, in front of a room. What
it serves is **counts, never people** — no respondent token, no registration, no name, no answer
attributable to anyone. Open text carries a display name only where the question asked for one,
and only once approved. The broadcast carries the same aggregate, and rides a public channel
precisely because there is nobody on a projector to authorise.

The organiser's own view is also aggregate. Individual answers are not exposed per person, because
a poll answered in a room is not a survey response and attendees will not know the difference
unless told. **If an organiser should ever be able to see who answered what, that is a product
decision to take deliberately, not a consequence of having made voting identified.**

**Charts for number, scale and rating suppress detail below five respondents**, showing a count
and "not enough responses yet". A histogram of salaries in a room of forty is an aggregate; in a
room of three it is a disclosure.

---

## 11. Delivery

**Part 1 — the room**
1. Self check-in, with the virtual case first (§6) — unblocks everything else
2. `email` on speakers, and speaker recognition in `/my` (§5.1)
3. Deck model, `deck_id`/`position`, join codes
4. Presenter controls, with voting refused on non-current questions
5. The portal's Live poll tab follows the presenter, and refuses stale questions
6. Coalesced broadcasts, SQL counting, the index (§7.2, §7.3)
7. Presenter hold on session expiry (§5.4)

**Part 2 — the questions**
8. Yes/No, rating, scale — closest to what exists
9. Multiple select, number — new aggregation shapes
10. Word cloud — normalisation and moderation
11. Ranking — new input and scoring

Each item ships on its own and is reviewable alone. Part 2 does not begin until Part 1 has been
used in a real room: the thing most likely to be wrong is the pacing, and no number of question
types fixes that.

---

## 12. Testing

Beyond the usual: **asserting that a page renders is not asserting that it works** — §8 is the
standing example, and the QR was green in tests while broken on the wall.

- Every new type: a test that the wall payload carries no identifying field.
- The three suppressed charts: a test at the five-respondent boundary.
- The presenter's hold: a test that travels past 12 hours mid-deck.
- Coalescing: a test that a hundred votes produce fewer than a hundred broadcasts — the number is
  the point.
- Self check-in: a test that a virtual event can mark someone present, since today it cannot.
- Voting: a test that a second vote from the same registration on another device is refused.
