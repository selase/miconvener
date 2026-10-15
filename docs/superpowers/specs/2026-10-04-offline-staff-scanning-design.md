# Offline scanning on staff links — design

**Date:** 2026-10-04 · **Status:** awaiting owner review

## Purpose

Ushers on staff links keep admitting guests when the venue's internet drops or
was never there. Nobody waits at the door because of the network, every scan
reaches the server once a connection returns, and each is credited to the usher
who made it.

## Decisions (owner, 2026-10-04)

1. **Online first.** With internet, every scan is checked live by the server, as
   today — authoritative, and a ticket used at another door is refused at once.
   The stored guest list is only the fallback.
2. **Admit offline, protected at the door (option "A+").** Offline, a valid
   ticket admits with the holder's name shown large, so the usher can ask for
   the name or ID. The phone refuses its own repeats. A ticket admitted twice
   during an outage is recorded as "used twice"; nobody is expected to chase
   the second person.
3. **Multi-day events: one link, every day; entry is per day.** A staff link
   works from creation until 12 hours after the event's last day ends, so one
   link covers the whole event. Guests are admitted once **per day** (a calendar
   day in the event's timezone), not once per event — today a day-2 scan shows
   "already checked in", which this fixes for live and offline scanning alike.
4. **Not in this version:** door assignment, rotating QR codes, offline scanning
   from the console door scanner, room-by-room scanning on staff links.

## How a scan is decided

| Situation | What the phone does |
|---|---|
| Online | Sends the scan to the server and shows its answer. |
| Online, but no answer within 3 s | Treats the phone as offline for this scan (below). |
| Offline | Matches the scan against the stored pack. ✓ admit (name large), ✕ already used on this phone (when, by whom), ✕ not recognised. Admissions are queued. |
| Connection returns | Sends the queue, receives other doors' scans, refreshes the pack, and goes back to live checking. |

Manual search (name or ticket code) follows the same rule: server when online,
stored pack when not.

## Multi-day events

- **Day** = calendar day in the event's `timezone`. Single-day events behave as now.
- **First scan of the day admits** — green, "Day 2 · Kwame Mensah".
- **Repeat scan the same day** — amber, "Already in today, 09:14 at Gate A". The
  usher decides (stepped out for lunch, or a shared ticket).
- **Headcount** on the staff page and the console is **today's** entries; the
  console also shows a per-day breakdown (Day 1: 312 · Day 2: 287).
- **"Used twice"** = admitted twice on the **same day**.
- The registration's `checked_in_at` / status keep meaning *first arrival at the
  event*, so attendance, certificates, reports and the `ticket.checked_in`
  webhook (first arrival only) are unchanged. Daily entry lives in the door scan
  log.
- Offline, the phone applies the same rule from the pack plus its own scans of
  the day; the pack includes each guest's last admission today.
- Staff links: one link for the whole event; tested with a three-day event.

## Components

### Server

- **Offline pack** — `GET /staff/{token}/offline-pack`: for the link's event,
  every confirmed or checked-in registration as `{id, name, ticket_type,
  ticket_code, seat, room, checked_in_at, qr_hash}` plus a `version` cursor.
  `qr_hash` is `sha256(qr_token)`; the raw token never leaves the server, so a
  stolen pack cannot be turned into tickets. Only for links that can scan.
- **Sync** — `POST /staff/{token}/checkin/sync` with
  `{scans: [{client_scan_id, registration_id, scanned_at}], since}`. Each scan is
  applied once (unique `client_scan_id`), so a resend is harmless. Responds with a
  result per scan (`admitted` | `duplicate` | `refused`) and the check-ins made
  elsewhere since `since`.
- **Door scan log** — new table `event_door_scans`: tenant, event, registration,
  staff link (nullable), user (nullable), `client_scan_id` (unique, nullable for
  live scans), `event_day`, `scanned_at`, `was_offline`, `outcome` (`admitted` |
  `already_in` (live repeat the same day, turned away) | `duplicate` (offline
  admission that was a second that day) | `refused`). Every scan — live or
  synced — is logged here.
- **Check-in time is the scan time.** A synced admission sets `checked_in_at` to
  when the guest was scanned, not when the phone synced. The first admission
  wins; a later one for the same ticket is logged as `duplicate` and changes
  nothing else.
- **"Used twice"** — registrations with more than one admitting scan, listed on
  the console's Check-in section and included in the check-in export: ticket,
  holder, each door/usher/time.
- Existing behaviour is kept: webhooks (`ticket.checked_in` fires on the first
  admission, with the scan time), automation triggers, the staff link's
  `last_used_at`.

### Phone (staff link page)

- **Service worker** scoped to `/staff/{token}/` caches the page shell and its
  assets, so the link opens with no signal even after a reload.
- **Storage (IndexedDB)**, per link: the pack, its version, the scan queue, and
  the scans this phone has made.
- **Status line** in the header: "Live" · "Offline · 7 waiting to send" ·
  "Ready offline · 412 guests · updated 08:52".
- **Sync** runs on reconnect (`online` event), every 30 s while online, and after
  each live scan; it also refreshes the pack.
- The PIN, once unlocked on this phone, stays valid offline (the unlock is
  remembered with the pack).

## Safety and retention

- A switched-off link: the server refuses pack and sync (410); the phone wipes
  its stored data on that answer.
- Stored data is deleted 12 hours after the event ends — when the link itself
  stops working.
- The pack holds names, ticket codes, ticket types and seats; no emails or phone
  numbers.

## Error handling

- A sync that fails is retried; the queue is never discarded until the server
  has acknowledged each scan.
- A queued scan for a registration cancelled meanwhile is logged as `refused`
  and shown to the usher in a "needs attention" note.
- A pack older than 12 hours shows a warning in the status line.

## Testing

- **Backend:** a three-day event — one link works all three days, a guest is
  admitted each day, a same-day repeat is "already in today", headcount is per
  day; the pack holds hashes, never raw tokens; scan-capable links only;
  sync is idempotent; `checked_in_at` is the scan time; a second admission is a
  `duplicate` and appears in "used twice"; switched-off and expired links get
  410; webhooks fire once with the scan time.
- **Frontend (Vitest):** the decision table above (online / timeout / offline),
  queueing and acknowledgement, pack matching by QR hash and by search.
- **Browser (Playwright, live demo):** open Efua's link, cut the network, scan
  and search offline, restore, and see the scans arrive in the console credited
  to Efua.
