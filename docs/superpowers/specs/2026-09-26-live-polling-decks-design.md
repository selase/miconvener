# Live Polling Decks — Design Spec

**Date:** 2026-09-26 · **Status:** awaiting owner review · **Path:** architectural

**Goal:** a Mentimeter-grade live polling experience inside MiConvener, so an organiser running a
congress never needs a second subscription to ask a room a question.

Facts about the current system were read from the code on 2026-09-26. Where this spec and the
code disagree later, the code wins — re-verify before relying on a claim.

---

## 1. What exists, and what is missing

**Exists and works:** three poll types (multiple choice, open text, quiz); one poll live at a time
per event; an organiser panel with bars, a moderation queue and a quiz leaderboard; a presentation
screen reached by a revocable token; results broadcast over Reverb, measured at ~0.8s end to end;
anonymous voting from the public event page.

**Missing, and why each matters:**

| Missing | Consequence today |
|---|---|
| A deck — an ordered sequence | An organiser sets each poll live by hand, one at a time, from a console tab |
| Presenter controls | No next/previous, no way to close voting and reveal, no sense of position |
| An audience join page | **The QR on the presentation screen opens raw JSON** (§7) |
| Question types beyond three | No scale, rating, ranking, word cloud, number or multi-select |
| Charts per type | Bars are the only visualisation |
| Speaker authorship | A speaker's portal uploads slides and nothing else |

---

## 2. Decisions taken in brainstorming

These were settled with the owner and are not reopened here.

1. **Built on polls, not surveys.** A Mentimeter deck is a sequence of *short* questions, each
   answered by the whole room at once at the presenter's pace. A survey is one form, many
   questions, self-paced, submitted once and read afterwards. The shape wanted is the shape polls
   already have; what is missing is the sequence and the types. Surveys keep their own job and
   get their own results dashboard separately.
2. **Anonymous by default, recognised when signed in.** A hall always contains people who are not
   in the registration system — walk-ins, a speaker's guest, someone who never verified their
   address. A poll a third of the room cannot answer is not worth asking. An attendee already
   signed in is recognised, which is what lets a quiz leaderboard or CME participation credit the
   right person.
3. **Organisers and speakers may both author and present.** A deck belongs to a session; whoever
   has rights over that session may build it and run it. "Organiser prepares, speaker presents"
   then needs no feature of its own.
4. **Speakers identify by emailed code**, the same passwordless mechanism attendees use — not the
   forwardable token link that currently guards slide upload.
5. **Results do not stream to voters' phones.** The wall is the shared surface; a phone in a hall
   is for answering. This is also what keeps socket count flat as audiences grow (§6).

---

## 3. Scope

**In scope — Part 1:** the deck, presenter controls, the audience join code and voting page, and
the capacity work in §6.

**In scope — Part 2:** the seven new question types with their inputs and charts.

**Out of scope, deliberately:**

- **The Speakers Workspace.** Speaker deck authoring assumes a surface that does not exist. The
  `Speaker` model holds name, title, organisation, bio and photo; the portal does three things.
  Bio and headshot upload, conflict-of-interest disclosure, deadlines and session Q&A are a
  separate project with its own spec. This spec designs *so that* decks can live there, and Part 1
  ships organiser-only authoring.
- **Q&A with upvotes.** Mentimeter has it; so does the existing Forum, including moderation and
  banning. A Forum slide in a deck is the later answer, not a second implementation.
- **A date question type.** Surveys have one. No live-room question is answered by it.
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
  join_code varchar(8)      unique per event while a deck is active
  status                    draft | live | ended
  current_poll_id nullable  where the presenter is standing
  present_token varchar     revocable, as events.present_token already is
  created_at / updated_at
```

`event_polls` gains `deck_id` (nullable) and `position` (integer). A poll with no deck keeps
working exactly as it does now — the existing single-poll flow is not disturbed.

### 4.2 Presenter controls

The presenter holds the pace. Controls: **next**, **previous**, **close voting**, **reveal**
(quiz only), **reopen**, and **end deck**.

Advancing sets `current_poll_id`, closes the outgoing poll and opens the incoming one in one
transaction, then broadcasts. Voting on a poll that is not `current` is refused by the endpoint,
not merely hidden — a phone left on a previous question must not be able to answer it.

**A presentation in progress cannot be interrupted by session expiry** (§5). Ending the deck, or
the session's end time passing, releases that hold.

### 4.3 Audience join

A deck displays a **join code** — six to eight characters, unambiguous alphabet (no `O`/`0`,
`I`/`1`), unique among live decks. The wall shows the code, a short URL and a QR.

Joining needs no account. A voter gets a signed, http-only **participant cookie** holding an
opaque token, which is what `respondent_token` becomes for anonymous voters. One device, one vote
per question. A signed-in attendee's registration is recognised instead, so their answers can
count toward a leaderboard or attendance — while the organiser still receives no identifying
token, exactly as today's poll respondent tokens work.

Clearing storage earns another vote. That is Mentimeter's accepted trade and is stated here so
nobody later mistakes it for a defect.

---

## 5. Speaker identity

Speakers verify by emailed six-digit code, reusing `PlatformAttendeeVerification` wholesale:
atomic attempt reservation, single use, 15-minute expiry, cached dummy hash, session regeneration.
A speaker marker is stored separately from the attendee marker; holding one does not confer the
other.

**The 12-hour window gets one exception.** Proof is fixed, not sliding, so a speaker who verifies
at 08:00 to rehearse would be asked for a code mid-deck at 20:00, in front of a room. While a deck
is `live` and the verified speaker is its presenter, authorisation holds until the deck ends or
the session's end time passes. Nothing else extends.

The existing `portal_token` link keeps working for slide upload. It does **not** grant deck
authoring: a forwardable link that has been sitting in an inbox since the call for papers should
not put words on the main screen.

---

## 6. Holding a thousand people

A congress may exceed a thousand attendees. Four things break at that size; each was measured or
read on 2026-09-26, not assumed.

### 6.1 Sockets — the reason voters' phones do not stream

The provisioned Reverb cluster holds **100 concurrent connections**. If every phone subscribed,
one 1,000-person event would need the 2,000 tier.

Because results do not stream to phones (decision 5), **only presenter screens hold sockets** —
one or two per event. The 100 tier then covers dozens of simultaneous events, and connection
count stays flat as audiences grow. This is the property being bought, more than the money.

Voters get their acknowledgement in the response to their own vote. No socket.

### 6.2 Broadcasts must be aggregated, not per vote

`PollResultsUpdated` currently fires **on every vote**, synchronously, inside the request. A
thousand people answering within ten seconds of a question opening is a thousand synchronous HTTP
calls to Reverb, with voters waiting on them.

Broadcasts become **interval-coalesced**: at most one per poll per second, carrying the current
aggregate. A vote records, schedules a broadcast if none is pending, and returns. A wall updating
once a second reads as live to a room; a wall updating a thousand times in ten seconds reads the
same and costs a thousand times more.

### 6.3 Counting moves to SQL

`PollResults::forDisplay` loads every response into memory and filters the collection. At a
thousand rows, recomputed per broadcast, that is the same work repeatedly.

Counts become a `GROUP BY option_id` aggregate. `event_poll_responses` gains an index on
`(poll_id, option_id)`; it currently indexes `tenant_id` and uniquely `(poll_id, respondent_token)`.
Open text keeps its existing capped fetch of the most recent approved answers.

### 6.4 Compute

Production runs one `flex-512mb` instance with **autoscaling off**. A thousand phones posting
within seconds is the spikiest traffic this product will ever serve, on the configuration least
able to absorb it. **Enabling autoscaling before a large event is a deployment precondition, not
code** — recorded here so it is decided rather than discovered.

### 6.5 Rate limits — done, 2026-09-26

Shipped ahead of this spec as `d6cb49b`, because it was a live defect rather than a new feature:

- **No proxies were trusted.** The client address read from a request was the load balancer's,
  identical for every visitor, so every per-IP limit was one global limit shared by the whole
  internet. Fixed to trust all proxies as Laravel Cloud documents, with a test that fails without it.
- **A solved challenge now earns passage** past the burst ceiling. A hall is one address shared by
  everyone in it, so volume cannot distinguish a congress from a script — only the challenge can.
  The daily figure became a backstop sized for a venue. Mailbox caps are untouched.

---

## 7. The audience voting page, and a live bug it fixes

The QR on the presentation screen points at `/e/{event}/poll`, **which returns JSON**. It is the
data endpoint the public event page's widget fetches, not a page. Anyone in a room who scans it
today sees a blob of JSON. Shipped by me on 2026-09-25; the tests asserted the join URL *contained*
`/poll` and never that it was somewhere a person could vote.

Part 1 replaces it with a phone-first voting page at `/join/{code}`: one question at a time, large
targets, no chrome, following the presenter. Until Part 1 lands the QR should point at the public
event page, where the existing widget can at least take a vote.

**Lesson recorded:** a test that asserts the shape of a URL has not asserted that the URL works.

---

## 8. Question types

Ten types. Three exist; seven are new. Each needs a phone input and a wall chart, and the two are
designed together — a type is not done because it can be answered.

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

**Multiple select** reports percentages of respondents, not of votes, because five options each
chosen by everyone is 100% five times and a bar chart that sums to 500% is a lie.

**Word cloud** normalises case and trims punctuation before counting, and caps entries per
respondent. Moderation applies as it does to open text: an unapproved word never reaches the wall.

**Ranking** scores by average position, and the wall states the respondent count, because an
average position over four voters is not a result.

---

## 9. Privacy

The presentation screen is opened by a token, on a machine with no login, in front of a room. What
it serves is **counts, never people** — no respondent token, no name, no answer attributable to
anyone. Open text carries a display name only where the question asked for one, and only once
approved. This holds for the broadcast too, which rides a public channel precisely because there
is nobody on a projector to authorise.

The same rule governs every new chart. A histogram of salaries in a room of forty is an aggregate;
a histogram of salaries in a room of three is a disclosure. **Charts for number, scale and rating
suppress detail below a floor of five respondents**, showing a count and "not enough responses yet"
instead.

---

## 10. Delivery

**Part 1 — decks and the room**
1. Deck model, `deck_id`/`position` on polls, join codes
2. Presenter controls, with voting refused on non-current questions
3. Audience join page and participant cookie — fixes §7
4. Aggregated broadcasts, SQL counting, the index (§6.2, §6.3)
5. Presenter hold on session expiry (§5)

**Part 2 — the seven types**
6. Yes/No, rating, scale — the three closest to what exists
7. Multiple select, number — new aggregation shapes
8. Word cloud — normalisation and moderation
9. Ranking — new input and new scoring
10. Speaker authoring, once a Speakers Workspace exists to hold it

Each numbered item ships on its own and is reviewable alone. Part 2 does not begin until Part 1 is
deployed and used in a room, because the thing most likely to be wrong is the pacing, and no
number of question types fixes that.

---

## 11. Testing

Beyond the usual: **an assertion that a page renders is not an assertion that it works** — §7 is
the standing example. Every new type gets a test that the wall payload carries no identifying
field, and the three suppressed charts get a test at the floor boundary. The presenter's hold on
expiry gets a test that travels past 12 hours mid-deck. Broadcast coalescing gets a test that a
hundred votes produce fewer than a hundred broadcasts — the point of it is the number.
