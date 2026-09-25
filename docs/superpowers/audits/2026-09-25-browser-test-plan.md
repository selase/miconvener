# Browser Test Plan — Attendee Portal, Console Requests, Real-Time

**For:** an agent with a real Chrome browser, testing **production** (`https://miconvener.com`).
**Date:** 2026-09-25
**Covers:** everything shipped after `15226a2` — the audit remediation, Turnstile, the probe
workspace, request seats, live poll refresh, and Reverb real-time.

Report each check as **PASS / FAIL / BLOCKED**, quote the actual text or status you saw, and say
which check a failure belongs to. Where a check says *measure the delay*, give the number — the
whole point of that check is the number, not that something eventually appeared.

---

## Before you start

### What you cannot do alone

Two things need the human, and neither has a workaround. Ask for them up front rather than
discovering it mid-run.

1. **Sign-in codes go to a real inbox** (`hiselase@gmail.com`). The portal will not let you in
   without one. Ask the human to relay the six-digit code each time you request one. A code lasts
   **15 minutes**, is **single use**, and a new request voids the previous one — so ask for it
   immediately, don't batch.
2. **The organiser console needs a login** you do not have. Ask the human to sign in to
   `https://miconvener-probe.miconvener.com` in the browser you are driving, then hand the session
   back. **Never ask for the password.**

### Is the fixture still live?

Part C and Part D depend on an event that is *currently running*. The fixture spans **two hours
before to six hours after** the moment it was last seeded, so it goes stale daily.

Open `https://miconvener.com/my` (verified) and look for **Probe Full Experience** under
**Live now**. If it is not there, or the workspace has no **Get help** tab, ask the human to run:

```
cloud command:run production --cmd="php artisan db:seed --class=ProbeWorkspaceSeeder --force"
```

It is idempotent — it slides the event to the new *now* and changes nothing else.

---

## Part A — No credentials needed

Do these first. They need no session at all, so a failure here is unambiguous.

### A1 · The portal is served only from the platform host

| Open | Expect |
|---|---|
| `https://miconvener.com/my` | **200**, an email field, heading *"Your MiConvener events"* |
| `https://www.miconvener.com/my` | redirects to `https://miconvener.com/my` |
| `https://miconvener-probe.miconvener.com/my` | redirects to `https://miconvener.com/my?organiser=miconvener-probe`, and the page names the organiser |
| `https://miconvener-production-utiytd.laravel.cloud/my` | **404** |

### A2 · A workspace refuses a stranger and remembers where they were going

Open `https://miconvener.com/my/events/01a084c3-fc31-72e4-94e6-cc6788c82c1b` **signed out**
(use a fresh incognito window).

- Expect a redirect to `/my?return_to=%2Fmy%2Fevents%2F01a084c3-...`
- The ticket must **not** render. If you can see a QR code or a ticket code, that is a **FAIL** and
  the most serious one in this document.

### A3 · The old ticket link no longer hands out the ticket

This is the fix most worth confirming. Ask the human for **any confirmation URL from an old ticket
email** — the shape is:

```
https://{organiser}.miconvener.com/e/{event-slug}/registrations/{uuid}
```

Open it **signed out**.

- Expect: a redirect to `https://miconvener.com/my/events/{uuid}`, which then redirects to
  `/my?return_to=...` asking who you are.
- **FAIL** if the page renders a ticket, a QR code, or an entry code at any point.

### A4 · Unknown addresses look identical to known ones

On `https://miconvener.com/my`, enter an address that certainly has no registrations, e.g.
`no-such-person-9f3@example.com`, and submit.

- Expect the **same** confirmation wording as a real address would produce — something like
  *"A sign-in code is on its way"*.
- **FAIL** on anything that hints the address is unknown ("no account", "not found", a different
  delay, a different layout). This is deliberate: the portal must not reveal who attended what.

---

## Part B — Attendee portal (needs relayed codes)

### B1 · Sign in

On `https://miconvener.com/my`, enter `hiselase@gmail.com`, submit, ask the human for the code,
enter it.

- Expect: **"Signed in as hi•••••@gmail.com"** — masked, not the full address.
- Expect: events grouped **by organiser**. At least **Purpledot** and **MiConvener Probe** should
  appear. Two different organisers under one address is the point of the whole portal; if only one
  appears, that is a FAIL worth reporting loudly.

### B2 · Turnstile

You are unlikely to trigger this — it fires from the **6th** code request from one IP in ten
minutes, and the threshold is deliberately back to its designed value. **Do not** try to force it
by spraying requests: each one sends real mail and damages sending reputation.

If you happen to see a Cloudflare widget, note whether it self-solves and whether the code still
arrives afterwards. Otherwise record **N/A**.

### B3 · The full workspace

Open **Probe Full Experience** → *Open workspace*.

Expect **exactly these tabs**, and say which are missing or extra:

`All events` · `My ticket` · `My day` · `Live poll` · `Q&A` · `Feedback` · `Get help` · `Downloads (2)`

Then, per tab:

| Tab | Expect |
|---|---|
| **My ticket** | QR image, entry code, status `checked_in`, **Seat C-14**, **Main Auditorium**, buttons for PDF / Save offline / Transfer |
| **My day** | **4 sessions** — one finished, one running now, a workshop and a closing still ahead, each with a room and track |
| **Downloads** | exactly **2** files: *Programme and floor plan* and the speaker deck. **The workshop handout must NOT be listed** — it is dated for later. Seeing three files is a FAIL of the release policy |
| **Live poll** | *"How are you joining us today?"* with 4 options |
| **Feedback** | a form with a rating, a select, a yes/no and a long-text field |
| **Q&A** | 4 threads, one pinned/answered |
| **Get help** | request types including Water / Refreshment and **First Aid / Medical** |

Also check the dashboard's own panels: **Certificates** (6 CPD hours), **Abstracts** (accepted
oral), **Attendance** (2 sessions).

### B4 · Downloads actually download

On **Downloads**, click *Programme and floor plan*. Expect a file to download, not an error page.
Note the remaining-attempts count if shown.

---

## Part C — Organiser console (needs the human signed in)

Navigate to the **Probe Full Experience** event in the console at
`https://miconvener-probe.miconvener.com`.

### C1 · A request shows its seat

As the attendee (Part B window), **Get help → Water / Refreshment → submit**.

In the console's **Requests** panel, the request should appear with:

- the attendee's name
- a pin badge reading **Seat C-14 · Main Auditorium**

A request with a name but no seat badge is a FAIL — the seat is the whole point of the change.

### C2 · Poll results move without a reload

Open the console's **Engagement** panel. It should show *"Results update as votes arrive"* with a
pulsing dot, because a poll is live.

As the attendee, vote in **Live poll**. **Do not touch the console.**

- Expect the bar to move on its own within **~8 seconds**.
- **Measure and report the delay.** If it never moves without a manual reload, that is a FAIL.

### C3 · The venue map lights the waiting seat

Open the **Venue** panel and select **Main Auditorium**. With the water request from C1 still open:

- Seat **C-14** should be **amber and pulsing**, not the ordinary assigned colour
- The legend should show **Waiting** and **First aid** entries
- Hovering C-14 should say the attendee is *waiting on a request*

Then in **Requests**, mark that request **Resolved**. Within ~8 seconds the seat should return to
its normal assigned colour.

---

## Part D — Real-time (the part most likely to be broken)

This is new infrastructure provisioned today: a Reverb cluster in London, attached to production.
The server half is verified; the **browser socket is not**, and that is what you are testing.

### D1 · Is the socket actually connected?

Before raising anything, in the console tab's DevTools console run:

```js
window.Echo ? 'echo present' : 'NO ECHO'
```

Then check the **Network → WS** tab for a connection to a host ending
`-reverb.laravel.cloud`, and report its status (101 Switching Protocols = good; pending, failed or
repeatedly reconnecting = bad). Report any console errors mentioning Pusher, WebSocket, or the
reverb host verbatim.

### D2 · Instant vs eight seconds

Two tabs side by side: attendee workspace, and console **Requests**.

Raise a **Water / Refreshment** request from the attendee tab and **time how long** the banner
takes to appear in the console.

- **Under ~1 second** → the socket is working. PASS.
- **Around 8 seconds** → the socket is *not* working and the poll is carrying it. Report as
  **FAIL (fell back to polling)** and include whatever D1 showed. The feature still functions; it
  just isn't real-time.

Report the number either way. "It appeared" is not a result for this check.

### D3 · Urgent behaves differently from ordinary

Raise a **First Aid / Medical** request.

Expect **all** of:

1. Console banner in **red**, with a warning triangle, reading *"First aid requested"*, naming the
   seat
2. The banner **does not fade** — it stays until Dismiss is clicked
3. Seat C-14 on the venue map turns **red** and pulses
4. An **email** to the organisation's address — ask the human to confirm it arrived. Subject
   contains *"Urgent: an attendee needs help"*, and the body names the attendee, **seat C-14**,
   **Main Auditorium**, and a time

Then raise an ordinary **Water** request and confirm **no email** is sent for it. Only urgent
requests mail anyone — if a water request sends email too, that is a FAIL, because it would train
people to ignore the mail that matters.

### D4 · Session occupancy

This panel has never worked in production — `window.Echo` did not exist on Inertia pages until
today — so it is untested rather than known-good. If the event has a check-in flow you can reach,
note whether the occupancy panel updates live. Otherwise record **N/A**.

---

## Reporting

For each check: **ID · PASS/FAIL/BLOCKED · what you actually saw**. Quote real strings and real
numbers. Screenshot anything that fails.

Flag these three above everything else, in this order:

1. **A2 or A3 showing a ticket** — the security fix did not hold
2. **A4 revealing whether an address is known** — attendee privacy
3. **D2 taking ~8 seconds** — real-time is not connected

A2, A3 and A4 are correctness. D2 is a degradation with a working fallback — worth reporting
plainly, not worth alarm.
