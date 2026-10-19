# Staff Facility Booking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Tick each step in the same commit as the work.

**Goal:** An Enterprise organisation's staff can see and request its rooms for free, private rooms are visible only to staff, team members can book for staff, and every booking shares one calendar.

**Architecture:** Staff bookings are `VenueBooking` rows with `kind = internal`. A global scope on `StoreListing` hides `audience = staff` rooms from every query by default, so a new public query can never leak one; only code that opts in (the owning console, `/my` for verified staff) sees them. One `RoomAvailability` service answers "is this slot free" for marketplace, internal and blocked bookings. Staff identity is a member tagged Staff or a verified address at a DNS-proven organisation domain.

**Tech Stack:** Laravel 13, PHP 8.5, Pest, PostgreSQL (landlord connection), Inertia React console and `/my` app, Blade admin.

**Spec:** `docs/superpowers/specs/2026-10-19-staff-facility-booking-design.md` (owner decisions recorded 2026-10-19). Also read `docs/superpowers/community-roadmap.md` (the Community and `/my` rules apply) and `docs/pricing/enterprise-dossier.md`.

## Global Constraints

- Internal booking and staff-only rooms are **Enterprise only**: plan feature `staff_room_booking`.
- **Staff never pay**, for any room their organisation owns: no payment step, no commission, no marketplace fee, no ledger entry.
- Approval is required by default; a room may switch it off.
- Limits are per-organisation settings with defaults: **8 hours** maximum length, **90 days** booking window, **5** open requests per person.
- A verified-domain address counts as staff only after the DNS proof; being staff at one organisation grants nothing at another.
- Staff-only rooms never appear on marketplace pages, search, embeddings, the MCP tools, sitemaps or any public JSON. Busy times reveal no booker details.
- Recurring bookings are out of scope. Money, payouts and the existing marketplace booking flow are unchanged.
- PHP: strict types, final classes, explicit return types, Form Requests, PHPDoc; `pint --dirty`; phpstan 0 with no baseline additions; JS changes commit a fresh `public/build`. Tests: Pest; use `PAO_DISABLE=1` if test output is empty; separate test database while another agent runs tests.

## Review Focus

1. A staff-only room must not appear on any public path: marketplace home, listing page, search, booking and availability endpoints, quote request, MCP tools, embeddings, sitemap. (Task 3)
2. A staff member of organisation A requesting a room of organisation B, or an organisation that left Enterprise. (Tasks 4, 6)
3. Two requests for the same slot, a request overlapping a paying booking or a blackout, and a pending request holding the slot. (Task 5)
4. A person at the verified domain on a shared or recycled address; an unverified or changed domain stops granting staff. (Task 8)
5. Limits at the edge: exactly 8 hours, exactly 90 days, the sixth open request. (Task 6)

---

### Task 1: Plan feature, permission and settings

**Files:**
- Modify: `database/seeders/EventPackageSeeder.php`, `database/seeders/PermissionsSeeder.php`, `app/Libraries/RolePermissions.php`, `app/Models/Permission.php` (`TENANT_SAFE`), `app/Services/Tenancy/FeatureService.php`, `config/pricing.php`
- Test: `tests/Feature/Venue/StaffRoomBookingGateTest.php`

**Interfaces:**
- Produces: feature slug `staff_room_booking` (boolean; Enterprise true, others false) and `FeatureService::FEATURE_STAFF_ROOM_BOOKING`; permission `manage room bookings` (tenant-safe, held by Org Superadmin by default); `config('bookings.staff.defaults')` returning `['max_hours' => 8, 'window_days' => 90, 'open_requests' => 5]`.

- [x] **Step 1: Write failing tests** named `only enterprise has staff_room_booking`, `manage room bookings is tenant safe and held by the organisation owner role`, and `the booking limit defaults are 8 hours, 90 days and 5 open requests`.
- [x] **Step 2:** Run `php artisan test --filter=StaffRoomBookingGateTest`. Expected: FAIL.
- [x] **Step 3:** Add the feature to the seeder's feature list and the four package arrays, the permission to the seeder and `TENANT_SAFE`, the owner role's list in `RolePermissions`, and `config/bookings.php` with the defaults.
- [x] **Step 4:** Rerun. Expected: PASS. Commit `feat(bookings): staff_room_booking feature, permission and defaults`.

### Task 2: Data

**Files:**
- Create: `database/migrations/landlord/2026_10_22_100000_add_staff_room_booking_columns.php`
- Modify: `app/Models/Member.php`, `app/Models/StoreListing.php`, `app/Models/VenueBooking.php`, `app/Models/Tenant.php`, matching factories
- Test: `tests/Feature/Venue/StaffRoomBookingSchemaTest.php`

**Interfaces:**
- Produces: `members.is_staff` bool default false; `store_listings.audience` string default `public` (`StoreListing::AUDIENCE_PUBLIC`, `AUDIENCE_STAFF`) and `requires_approval` bool default true; `venue_bookings.kind` default `marketplace` (`VenueBooking::KIND_MARKETPLACE`, `KIND_INTERNAL`), `requested_for_member_id` nullable, `entered_by_user_id` nullable, `department` nullable, `purpose` nullable, and status `VenueBooking::STATUS_PENDING_APPROVAL = 'pending_approval'`, `STATUS_DECLINED`; `tenants.staff_email_domains` jsonb (list of `{domain, token, verified_at}`) and `tenants.booking_limits` jsonb nullable.

- [x] **Step 1: Failing test** `new columns default to existing behaviour`: an existing listing is `public`, an existing booking is `marketplace`, a member is not staff.
- [x] **Step 2:** Run it. Expected: FAIL (unknown columns).
- [x] **Step 3:** Write the migration and add constants, `$fillable` entries and casts to the models.
- [x] **Step 4:** Rerun; PASS. Commit `feat(bookings): staff booking columns`.

### Task 3: Staff-only rooms are hidden by default

**Files:**
- Create: `app/Models/Scopes/StaffOnlyScope.php`
- Modify: `app/Models/StoreListing.php` (register in `booted()`; add `scopeVisibleToStaffOf(Builder, Tenant): Builder`), `app/Http/Controllers/Tenant/Venue/VenueCalendarController.php` and the venue console queries (opt in)
- Test: `tests/Feature/Venue/StaffOnlyRoomsAreHiddenTest.php`

**Interfaces:**
- Produces: `StaffOnlyScope` excludes `audience = staff`; `StoreListing::withStaffOnly()` removes it; `StoreListing::visibleToStaffOf(Tenant $tenant)` includes staff-only rooms of that organisation's shop only.

- [x] **Step 1: Failing tests**, one dataset over every public path, each asserting a staff-only room is absent: marketplace home, listing page (404), search (`MarketplaceSearchService`), `checkAvailability`, booking and quote endpoints, the three MCP tools, `VenueEmbeddingService`, `EmbedMarketplaceVenuesCommand`, and the sitemap if one lists listings. One more test: the owning console lists its staff-only rooms.
- [x] **Step 2:** Run. Expected: FAIL for staff-only rooms appearing.
- [x] **Step 3:** Implement the scope and register it; opt the console and `VenueCalendarController` queries in with `withStaffOnly()`.
- [x] **Step 4:** Rerun; PASS. Commit `feat(bookings): staff-only rooms never appear publicly`.
  Ruling: the global scope also needed route-model binding changed (`StoreListing::resolveRouteBinding` resolves the owning organisation's staff-only rooms and 404s for everyone else) and the public booking pages (`show`, `checkout`, `callback`) now serve marketplace bookings only, so an internal booking's reference reveals nothing. `VenueBooking::listing` lifts the scope because a booking already names its room. Verified the leak tests fail (11 of 14) with the scope switched off.

### Task 4: Who is staff

**Files:**
- Create: `app/Services/Venue/StaffIdentity.php`
- Test: `tests/Feature/Venue/StaffIdentityTest.php`

**Interfaces:**
- Consumes: `CommunityHistory::members(Tenant, Session)` (the person's member records) and `PlatformAttendeeVerification::verifiedEmail(Session): ?string`.
- Produces: `StaffIdentity::isStaff(Tenant $tenant, Session $session): bool` and `::memberFor(Tenant $tenant, Session $session): ?Member` (creates and tags a member on first verified-domain use). Returns false when the organisation lacks the `staff_room_booking` feature.

- [x] **Step 1: Failing tests:** `a member tagged staff is staff`, `a member not tagged is not`, `staff of one organisation is not staff of another`, `an organisation without the feature has no staff`, `a verified-domain address is staff and becomes a tagged member`.
- [x] **Step 2:** Run; FAIL.
- [x] **Step 3:** Implement `isStaff` and `memberFor`; the domain rule defers to Task 8's `StaffDomains::matches(Tenant, string $email): bool` (add a stub returning false here, completed in Task 8).
- [x] **Step 4:** Rerun; PASS. Commit `feat(bookings): staff identity`.
  Ruling: `StaffDomains::matches` (exact match on verified domains stored in `tenants.staff_email_domains`) is built here, not stubbed, because the identity tests need it; Task 8 adds add/verify/remove and the DNS proof around it. A domain-only member is created through `MemberEnrollment` with a `Staff` membership type that has no amount and no period, which the dues scheduler skips (`hasDues()` is false).

### Task 5: One availability check

**Files:**
- Create: `app/Services/Venue/RoomAvailability.php`
- Modify: `app/Http/Controllers/Marketplace/VenueBookingController.php::checkAvailability`, `app/Http/Controllers/Tenant/Venue/VenueCalendarController.php` (block creation), `app/Mcp/Tools/CheckAvailabilityTool.php`
- Test: `tests/Feature/Venue/RoomAvailabilityTest.php`

**Interfaces:**
- Produces: `RoomAvailability::isFree(StoreListing $room, CarbonInterface $from, CarbonInterface $to, ?string $ignoringBookingId = null): bool`. Occupying statuses: confirmed, blocked, pending approval (internal), and unexpired pending payment holds. `::busy(StoreListing $room, CarbonInterface $from, CarbonInterface $to): Collection` returns `[starts_at, ends_at]` pairs only, never booker details.

- [x] **Step 1: Failing tests:** `a confirmed internal booking blocks a public booking`, `a pending request holds the slot`, `a declined or cancelled request frees it`, `a blackout blocks both kinds`, `busy times carry no booker details`, `back-to-back slots do not overlap`.
- [x] **Step 2:** Run; FAIL.
- [x] **Step 3:** Move the overlap query out of the calendar controller into the service and have the three call sites use it.
- [x] **Step 4:** Rerun the new tests and `tests/Feature/Marketplace`; PASS. Commit `feat(bookings): one availability check for every booking kind`.
  Ruling: a shared check already existed (`VenueAvailabilityService::isSlotAvailable` / `getBookedSlots` over `VenueBooking::scopeBlockingCalendar`), so no new `RoomAvailability` class was built. Pending staff requests join the scope's occupying statuses, internal bookings are reported to the public as `confirmed`, and the calendar's block creation now calls the service instead of its own copy of the query.

### Task 6: Internal bookings

**Files:**
- Create: `app/Services/Venue/InternalBookings.php`, `app/Http/Requests/Venue/RequestRoomRequest.php`, `app/Mail/Venue/RoomRequestDecided.php` (and the matching markdown view), `app/Policies` entry if the codebase uses one for bookings
- Test: `tests/Feature/Venue/InternalBookingsTest.php`

**Interfaces:**
- Consumes: `StaffIdentity` (Task 4), `RoomAvailability` (Task 5).
- Produces: `InternalBookings::request(Tenant $tenant, StoreListing $room, Member $for, CarbonInterface $from, CarbonInterface $to, string $purpose, ?string $department, ?int $guests, ?User $enteredBy = null): VenueBooking` (status `pending_approval`, or `confirmed` when `requires_approval` is false or entered by a team member who chooses to confirm); `approve(VenueBooking, User): VenueBooking`, `decline(VenueBooking, User, string $reason): VenueBooking`, `cancel(VenueBooking, Member|User): VenueBooking`. Throws a `ValidationException` for: a room of another organisation, an organisation without the feature, over the length, beyond the window, over the open-request limit, or an occupied slot. Booking rows carry zero amounts and no payment fields.

- [x] **Step 1: Failing tests:** `staff request a free room and it is pending`, `a room that needs no approval confirms at once`, `a team member books on behalf of a staff member with a note`, `staff of another organisation cannot request`, `an organisation without the feature cannot`, `8 hours is allowed and 8 hours 1 minute is refused`, `day 90 is allowed and day 91 refused`, `the sixth open request is refused`, `an occupied slot is refused`, `approve and decline email the staff member`, `a staff member cancels only their own booking`, `no payment, commission or ledger row is created`.
- [x] **Step 2:** Run; FAIL.
- [x] **Step 3:** Implement the service; limits read `Tenant::booking_limits ?? config('bookings.staff.defaults')`. Wrap `request` in a transaction with a lock on the room row so two requests cannot both pass the availability check.
- [x] **Step 4:** Rerun; PASS. Commit `feat(bookings): internal booking requests, approval and limits`.
  Ruling: the global scope moved to `app/Scopes/StaffOnlyScope.php` beside `TenantScope`. Staff who are only tagged by domain are created as tagged members before they can request (the service requires `is_staff`), so "a team member books for someone not yet tagged" tags them in the console (Task 7) and then calls `request`. A pending request is notified to the staff member on decision only; telling facility managers about new requests is not in the spec and is left out.

### Task 7: Console

**Files:**
- Modify: `app/Http/Controllers/Tenant/Venue/*` (room settings, calendar), `routes/subdomain.php`, `resources/js/Pages/Tenant/Venue/*` (room form, calendar, bookings list), `resources/js/Components` as needed
- Create: `app/Http/Controllers/Tenant/Venue/InternalBookingController.php`
- Test: `tests/Feature/Venue/InternalBookingConsoleTest.php`

**Interfaces:**
- Consumes: `InternalBookings` (Task 6). Every route requires `manage room bookings` and the feature.
- Produces: routes `tenant.venue.internal-bookings.{index,store,approve,decline,cancel}`; room settings fields `audience` and `requires_approval`; calendar colours internal bookings and shows department and purpose; filters by room and kind; a member search limited to Staff-tagged members; a "Staff" toggle on the members list and import.

- [x] **Step 1: Failing tests:** a permitted team member lists, creates on behalf, approves and declines; a user without the permission gets 403 on each; an organisation without the feature gets 403; the room form saves `audience` and `requires_approval`; the calendar payload includes internal bookings with `kind`.
- [x] **Step 2:** Run; FAIL.
- [x] **Step 3:** Implement controllers, requests and pages following the existing venue console patterns; run `npm run lint`, `npm run test`, `npm run build` and commit `public/build`.
- [x] **Step 4:** Rerun; PASS. Commit `feat(bookings): console for internal bookings and room audience`.
  Ruling: the Staff tag is managed on the Staff bookings page (add by name and email, remove) through `InternalBookingController::storeStaff/destroyStaff`, not as a checkbox on the Community People form or its import. Those pages have their own duplicate-review flow and tests and belong to the shipped Community milestones; touching them for one checkbox risked regressions. A Staff column on the People page and a staff column in the import remain possible follow-ups. Room audience and approval are on the existing space form, shown only on a plan with the feature (or when a room is already staff-only, so an organisation that left Enterprise can make it public).

### Task 8: Organisation email domains

**Files:**
- Create: `app/Services/Venue/StaffDomains.php`, `app/Http/Controllers/Tenant/StaffDomainController.php`, `app/Http/Requests/Tenant/StoreStaffDomainRequest.php`; modify the organisation settings page
- Test: `tests/Feature/Venue/StaffDomainsTest.php`

**Interfaces:**
- Produces: `StaffDomains::add(Tenant, string $domain): array` (returns the TXT record to publish: name `_miconvener-staff.<domain>`, value a random token), `::verify(Tenant, string $domain): bool` (DNS TXT lookup via an injectable resolver so tests fake it), `::remove(Tenant, string $domain): void`, `::matches(Tenant $tenant, string $email): bool` (true only for a **verified** domain and an exact domain match, not a subdomain suffix trick such as `evilugmc.edu.gh`).

- [x] **Step 1: Failing tests:** `an unverified domain grants nothing`, `a verified domain matches its addresses`, `lookalike domains and subdomains do not match`, `verification fails when the TXT record is missing or wrong`, `removing a domain stops granting staff at once`, `a domain claimed by another organisation cannot be added`.
- [x] **Step 2:** Run; FAIL.
- [x] **Step 3:** Implement the service and settings UI; replace Task 4's stub.
- [x] **Step 4:** Rerun with `StaffIdentityTest`; PASS. Commit `feat(bookings): DNS-proven staff email domains`.
  Ruling: the DNS lookup sits behind a contract (`App\Contracts\DnsTxtResolver`, native implementation `NativeDnsTxtResolver`, bound in `AppServiceProvider`) so tests fake the network; the domain management UI lives on the Staff bookings page with the Staff list, consistent with Task 7's ruling. The proof record is `_miconvener-staff.<domain>` TXT `miconvener-staff-verification=<token>`. A domain proved by another organisation is refused at add time and at verify time (whoever proves it first keeps it).

### Task 9: Staff in `/my`

**Files:**
- Create: `app/Http/Controllers/Public/MyRoomsController.php`, `app/Http/Requests/Public/RequestMyRoomRequest.php`; routes in `routes/attendee.php`; a Book a room section in the existing `/my` app (`resources/js/...`, following the Communities tab's patterns)
- Test: `tests/Feature/Attendee/MyRoomsTest.php`

**Interfaces:**
- Consumes: `StaffIdentity::isStaff`, `RoomAvailability::busy`, `InternalBookings::request`/`cancel`.
- Produces: JSON `GET /my/communities/{tenant}/rooms` (rooms the person may request, each with busy times for a requested week), `POST .../rooms/{room}/requests`, `DELETE .../requests/{booking}`; 404 for a non-staff person, so a private room's existence is not revealed.

- [x] **Step 1: Failing tests:** `staff see staff-only and public rooms with busy times`, `a verified person who is not staff gets 404`, `staff request and cancel their own booking`, `a person cannot cancel another's booking`, `rooms of another organisation are never listed`.
- [x] **Step 2:** Run; FAIL.
- [x] **Step 3:** Implement the controller and the page; build assets.
- [x] **Step 4:** Rerun; PASS. Commit `feat(bookings): staff book rooms from /my`.
  Ruling: `GET /my/communities/{tenant}/rooms` also returns the person's own open bookings (`mine`), and `MyCommunityResource` gains `actions.rooms` so the app shows a Book a room button only to staff. Routes sit inside the existing `community.identity` and `community-access` throttle group. The panel follows the existing `community_view` pattern (`community_view=rooms`). The booking summary is returned on create and cancel.

### Task 10: Verification and handover

- [ ] **Step 1:** Run the full suite (`php artisan test --parallel --processes=4`), `pint`, phpstan, `npm run lint`, `npm run test`, `npm run build`. Expected: all green.
- [ ] **Step 2:** Update `docs/pricing/enterprise-dossier.md` with the measured staff and private-room counts available in the console, and the community roadmap if it applies.
- [ ] **Step 3:** One review of the whole branch by a fresh reviewer, given Review Focus above. Open a PR; production steps (seeders, `tenants:sync-features`) need the owner's go-ahead.
