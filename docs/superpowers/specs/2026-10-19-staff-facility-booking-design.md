# Staff facility booking: design

Date: 2026-10-19. Status: draft for owner review. Path: architectural (new booking kind, new visibility rule, new identity rule, plan entitlement).

## 1. Purpose

An institution on MiConvener (a university, a hospital, a church with halls) has rooms and spaces its own staff use without paying. Staff need to see when a room is free and ask for it; the people who run the facilities need one calendar that shows staff bookings and paying bookings together, so nothing is double-booked. Some rooms are only for staff and must never appear to the public.

## 2. Owner decisions (2026-10-19)

| Topic | Decision |
|---|---|
| On behalf of staff | A team member of the organisation can make a booking for a staff member who asked by email or in person. |
| What staff see | Available dates and times for the rooms they may request. |
| Private rooms | Some rooms and spaces are not bookable by the public, only by staff. Only staff see private rooms; everyone sees public rooms. |
| Who is staff | Staff are followers and members of the organisation. Members who are staff carry a "Staff" tag. |
| Email domain | Optional: if the organisation has its own email domain, people verified at that domain can count as staff. |
| Plan | Internal booking and private rooms are Enterprise only. |
| Pricing | The number of staff and the number of private bookable rooms are pricing dimensions (see `docs/pricing/enterprise-dossier.md`). |

## 3. What exists today

- Venue hosts list rooms and spaces (`StoreListing`, `is_bookable`) on the public marketplace; bookings (`VenueBooking`) go through quote or deposit, payment, confirmation. The host's calendar can block dates ("Maintenance / Blackout", free, with a reason). There is no visibility other than public, no staff concept, and no free internal booking.
- Community members (`Member`, with a membership type and status) and followers (`CommunityFollower`) exist per organisation. `/my` knows a person by a verified email or phone.

## 4. Design

### 4.1 Staff

- A member can be tagged **Staff** (`members.is_staff`), set one by one or in the member import. Staff are members, so they are also followers; tagging someone staff who only follows makes them a member with the tag.
- **Optional email domain.** An organisation may register its own domain(s), for example `ugmc.edu.gh`. Because a domain grants access, it must be proven first: a DNS TXT record, the same way own-domain email sending is set up today. Once verified, anyone who verifies an address at that domain in `/my` is treated as staff of that organisation; on their first booking request they are added as a member with the Staff tag (the organisation can remove the tag later).
- A person is staff only for the organisation that tagged them or owns the domain; being staff at one institution grants nothing at another.

### 4.2 Rooms: public or staff-only

- Each bookable listing gets an audience: **Public** (as today: marketplace, search, anyone books and pays) or **Staff only** (never on the marketplace, search, sitemaps or public pages; visible only to the organisation's staff and team).
- Each room also says whether staff bookings need approval or are confirmed at once (default: approval).

### 4.3 How staff book

- In `/my`, under the organisation, staff see **Book a room**: the staff-only rooms and the organisation's public rooms, each with a calendar of free and taken times. Taken times show as busy, never who booked them.
- A staff request records the room, date and time, purpose, department, expected number of people, and any setup needs. It is free: no payment step, no commission, no marketplace fee.
- The request is **pending** until a facility manager approves or declines it (or confirmed at once if the room does not need approval). Staff are emailed the outcome, and can cancel their own booking.

### 4.4 Booking on behalf of staff

- In the console, a team member with the new permission **manage room bookings** opens the room calendar, picks a free slot, and chooses the staff member (search members tagged Staff) or types a name and email for someone not yet tagged. The booking records who it is for, who entered it, and a note ("requested by email"). It can be confirmed immediately.

### 4.5 One calendar, no double booking

- Internal bookings are `VenueBooking` rows with a kind: **marketplace** (as today) or **internal** (free, linked to the staff member and the person who entered it). Availability checks and the host calendar consider both kinds and blocks, so a public renter can never take a slot a staff member holds, and the reverse.
- The calendar shows internal bookings in their own colour with the department and purpose; facility managers can filter by room, building and kind.

### 4.6 Enterprise only

- A new plan feature, **staff_room_booking**, is on for Enterprise only. Without it: no Staff-only audience, no internal bookings, no domain rule. An organisation that leaves Enterprise keeps its existing bookings; staff-only rooms stop accepting new requests until it returns or the rooms are made public.

### 4.7 Permissions

- **manage room bookings** (organisation permission): approve, decline, enter and cancel internal bookings, set room audience and approval.
- Tagging staff uses the existing member permissions. Registering an email domain uses organisation settings.

## 5. Out of scope

Charging departments internally (cost centres, internal rates), recurring bookings (every Tuesday for a term), equipment and catering orders beyond a free-text setup note, and booking by people who are neither staff nor members of the public marketplace.

## 6. Questions for the owner

1. **Public rooms:** when staff book a room that is also rented to the public, is it free for them too (recommended: yes, staff never pay), or only staff-only rooms are free?
2. **Approval default:** approval required for every staff request (recommended), or confirmed at once unless the room says otherwise?
3. **Domain staff:** should a verified domain address be enough on its own (recommended, after DNS proof), or must the organisation also tag them before they can book?
4. **Limits:** a maximum booking length, how far ahead staff may book, and how many open requests one person may hold?
5. **Recurring bookings** (a weekly lecture for a term): needed in the first version, or later?

## 7. Rough build plan

1. Plan feature `staff_room_booking` (Enterprise), permission `manage room bookings`, `members.is_staff`, listing audience and approval setting.
2. Organisation email domains with DNS proof, and the staff check (tag or verified domain).
3. Availability: one check across marketplace bookings, internal bookings and blocks; the shared calendar.
4. Console: room audience and approval; internal booking on behalf of staff; approve and decline; calendar colours and filters.
5. `/my`: Book a room, request, cancel, emails.
6. Privacy and leakage tests: staff-only rooms never appear on public pages, search, sitemaps or the marketplace API; staff of one organisation see nothing of another's private rooms; busy times reveal no booker details.
