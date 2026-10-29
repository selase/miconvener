# S2a Venue Booking Approval and Availability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task using the native approach. Steps use checkbox (`- [ ]`) syntax for tracking. Use one fresh whole-change review at the end, not a new review loop per task.

**Goal:** let a verified prospective booker request a venue, let its authorised host approve a bounded hold, and prevent competing reservations from occupying the same space and time.

**Architecture:** retain `VenueBooking`, listing IDs, calendar records and existing routes as authoritative. Add an opt-in approval-first path and one listing-first transaction lock contract shared by all calendar writers. S2a creates no payable shared-commerce venue order; S2b handles payment migration and S2c handles bank-transfer evidence.

**Tech Stack:** existing Laravel/PHP, Eloquent/PostgreSQL, Inertia/React, native date handling, Pest and Vitest; no new dependency.

**Spec:** `docs/superpowers/specs/2026-10-22-unified-commerce-design.md` §§2/4/7/8; `docs/superpowers/specs/2026-09-20-event-store-handoff.md` approval/calendar requirements; current `docs/superpowers/marketplace-roadmap.md`. The design rulings below are recommendations for owner review, not approved product decisions.

**Status:** proposed on 29 October 2026. Documentation only. No implementation, production rollout, venue payment or merchant activation authorised.

## Global constraints

- Preserve existing venue listing/booking IDs, URLs, media, amenities, capacities, operations, event links and private staff-room flows. No duplicate calendar or destructive migration.
- Purpledot merchandise checkout stays live with its approved 3% tenant-specific agreement; this does not enable venue booking or copy that agreement into venue payments.
- Money remains integer minor units. `LedgerService` remains the ledger writer. Do not introduce venue commission arithmetic, change gateway credentials or move money in S2a.
- Preserve legacy paid bookings and callback processing; never block an already-taken payment merely because a new rollout flag is off. New S2a requests cannot initialise legacy checkout or become paid through a forged callback.
- Existing `manage venue` authorisation plus tenant/shop/kind ownership applies to host actions. Staff-room `pending_approval` continues to hold its slot; new public pending requests do not.
- Local QA is authorised; production opt-in remains a separate decision. New request feature defaults off. No broad enabling, real messages or live payment tests from this plan.
- Tick steps in the same commits as implementation, use `pint --dirty`, rebuild `public/build` for JS/CSS, and update the roadmap when state changes.

## Proposed decisions to review

1. **Approval-first only:** both advertised-price and price-on-request venues enter `pending_host_approval` in the opt-in path. No instant booking in S2a. An availability preview and an emailed acknowledgement are not confirmation.
2. **Requests do not reserve:** overlapping pending public requests are permitted; clearly say “Your dates are not reserved until the host approves.” To reduce spam, require verified email, existing request throttling and an idempotency key. An initially unavailable slot returns an availability error; a request that later loses availability stays visible to the host as a conflict.
3. **Host approval holds time:** `approved_hold` reserves the exact interval for **48 hours by default**; host may choose 1–168 hours. Approval rechecks availability under the shared lock. No automatic confirmation, payment or extension. A host can decline with a buyer-visible reason, release an unpaid hold, or explicitly renew an expired hold after a fresh conflict check.
4. **Expiry is immediate:** a new hold blocks only while `hold_expires_at > now()`. At the exact deadline it is expired, even if no scheduler has run. Repeating the same approval key returns the original result and cannot renew it. Paid/confirmed legacy bookings never expire through this rule; retain legacy null-expiry behaviour until S2b reconciliation.
5. **Adjacent slots are allowed:** use half-open intervals `[start, end)`, retaining the existing overlap predicate. Setup/cleanup time must be included in the requested interval; no automatic buffers or parent/child-space inventory in this slice. Explain that a hall subdivided into shared rooms needs a later resource model before parallel trading.
6. **Private email-first dossier:** reuse `PlatformAttendeeVerification`; the verified address must match the request email. A reference/UUID alone cannot expose a new request's contact, notes or actions. No membership or dues required. Keep internal notes separate from the buyer-visible decision. Existing legacy links are retained, not broadened to new requests.
7. **No new venue payments:** the approved screen says “Dates held until …; payment is not available yet.” Existing paid legacy records remain readable. Existing unpaid legacy continuations are handled compatibly; no migration or promise that their financial pipeline is fixed by S2a.
8. **Time and launch:** persist UTC, accept unambiguous ISO timestamps with an offset, display the venue policy timezone explicitly. Default that timezone to `config('app.timezone')`, configurable per venue with IANA validation. Requests require active tenant/shop, published venue, explicit bookable policy and a separate off-by-default `marketplace_venue_requests` feature; merchandise and supplier kinds are rejected. Anonymous browsing remains available.

## Baseline findings and compatibility boundary

- `VenueBookingService::createBooking()` currently creates priced `pending_payment` bookings without a shared check/create transaction. Public `book()` may immediately initialise Paystack.
- `VenueInquiryController::acceptAndHold()` and `sendQuote()` update to blocking states without the staff-room listing lock; quote notification links lead to payment continuation. These must not bypass approval or the S2a payment pause for new-flow rows.
- `VenueCalendarController::storeBlock()` checks availability then creates a blackout without a shared lock. `InternalBookings::request()` already locks the listing; its later decisions currently lock the booking first. Cancellation and blackout removal also need consistent ordering.
- `blockingCalendar()` currently counts internal `pending_approval`, confirmed/blocked and unexpired legacy `pending_payment`; `overlapping()` already uses strict interval boundaries. Retain those semantics for historical rows and add new hold semantics explicitly, not by reusing the internal pending status.
- Legacy `confirmBookingPayment()` changes calendar occupancy and posts through the older marketplace ledger. It must join the lock contract. A late/conflicting legacy confirmation must preserve evidence and enter an explicit staff exception, not displace another booking. Full provider verification, payment-state repair and settlement belong to S2b; this slice must not claim financial certification.
- Existing `MyRoomsController`, internal request limits, staff-domain identity and event collaboration paths are regression surfaces. No new Community feature is required.

## Review Focus

- Two hosts approve competing requests, or approval races a staff booking/blackout: exactly one conflicting occupancy wins; loser remains reviewable without a misleading approval notice.
- Expiry at the exact boundary, renewal, lost responses and stale tabs: no permanent hold, accidental extension or resurrected declined/cancelled request.
- Late legacy payment versus a newly occupied slot: retain evidence, show a staff reconciliation exception, never silently double-book or initiate a refund/second charge.
- Foreign tenant, guessed reference, wrong verified email and hidden staff rooms: no disclosure, decision or availability leak.
- Timezone offsets, DST ambiguity, adjacent intervals and existing historical overlaps: deterministic validation; no automatic rewriting of old reservations.

## File and interface map

**Create:** landlord migration `add_public_approval_to_venue_bookings` (generate timestamp at execution), `app/Models/VenueBookingAction.php` and factory, `app/Services/Venue/VenueCalendarLocks.php`, `app/Services/Marketplace/VenueBookingApprovals.php`, `app/Services/Marketplace/VenueRequestRollout.php`, `app/Console/Commands/EnableVenueRequestsCommand.php`, `app/Mail/Marketplace/VenueApprovalNotice.php`, `app/Jobs/DeliverVenueApprovalNotice.php`, `resources/views/emails/marketplace/venue-approval-notice.blade.php`, and Form Requests `app/Http/Requests/Marketplace/StoreVenueBookingRequest.php`, `app/Http/Requests/Tenant/Venue/DecideVenueBookingRequest.php`.

**Modify:** `VenueBooking`, `StoreListing`; `VenueBookingService`, `VenueAvailabilityService`, `Venue/InternalBookings`; public `VenueBookingController`; tenant `VenueInquiryController`, `VenueCalendarController`, `VenueListingController`, `app/Http/Controllers/Public/SettlementWebhookController.php` and `StoreVenueListingRequest`; listing/booker/Inquiries/Calendar/Spaces React screens; existing feature admin/rollout mechanism and routes only where necessary. Preserve native notification classes where their copy is accurate; add a dedicated decision notice rather than sending a payment-quote email for an unpaid hold.

**New contract:**
- `VenueCalendarLocks::listing(string $listingId): StoreListing` — landlord transaction required, row lock; no permission bypass. Caller resolves ownership first.
- `VenueCalendarLocks::booking(StoreListing $lockedListing, string $bookingId): VenueBooking` — booking lock after listing; verify listing association. Multiple listing locks, if ever needed, sort UUIDs.
- `VenueBookingApprovals::request(StoreListing $listing, array $validated, Session $session, string $operationKey): VenueBooking`.
- `VenueBookingApprovals::approve(VenueBooking $booking, User $actor, int $holdHours, string $operationKey): VenueBooking`.
- `VenueBookingApprovals::decline(VenueBooking $booking, User $actor, string $reason, string $operationKey): VenueBooking`.
- `VenueBookingApprovals::release(VenueBooking $booking, User $actor, string $reason, string $operationKey): VenueBooking`.
- `VenueBookingApprovals::renew(VenueBooking $booking, User $actor, int $holdHours, string $operationKey): VenueBooking` — only expired new-flow holds, never terminal requests.
- `VenueBookingApprovals::cancelByBuyer(VenueBooking $booking, Session $session, string $operationKey): VenueBooking` — matching verified email, new-flow unpaid request/hold only.

No method may treat possession of a model instance or a globally valid permission as tenant ownership. All transition inputs are rechecked under the lock.

## Task 1 — Additive policy, state and audit contracts

**Files:** new landlord migration/model/factory; `app/Models/VenueBooking.php`, `app/Models/StoreListing.php`; new `tests/Feature/Marketplace/VenueBookingApprovalSchemaTest.php`; existing staff-room schema/availability tests.

- [ ] Write failing tests for new-flow states, nullable `approval_flow_version`/`hold_expires_at`/`submission_key`, listing `public_booking_policy` (`approval_required=true`, default hold 48, maximum 168, timezone), and off-by-default feature behaviour. Existing rows remain legacy with no automatic opt-in. Implement `VenueRequestRollout::enabled(Tenant $tenant): bool` using the explicit tenant feature row, active tenant/shop and existing feature-cache invalidation pattern; no package fallback that accidentally enables requests. Provide `venue:enable-requests {tenant} {--off}` using the existing `EnableInboxCommand` feature-registration pattern. Test unknown tenant, on/off and isolation; no automatic deploy-time activation.
- [ ] Run the new file with `php artisan test --compact <file>` and confirm failures name the absent contract.
- [ ] Add immutable action evidence: booking/shop/tenant, actor or verified buyer identity, action, operation key, canonical request hash, previous/result status and hold deadline, decision reason, timestamps. Unique `(booking_id, operation_key)`; request creation unique `(shop_id, submission_key)` where non-null. Protect business/action evidence against update/delete; delivery-state/attempt metadata may change independently. No raw verification codes, payment links or arbitrary provider payloads. Refuse destructive rollback when new-flow/audit records exist.
- [ ] Add `pending_host_approval`, `approved_hold` and effective expired presentation. Extend blocking scope only for unexpired new holds; test `expiry == now` does not block, adjacent intervals do not overlap, existing staff pending and legacy null-TTL/payment semantics still pass.
- [ ] Run affected schema/availability tests, Pint; commit migration/model/tests and tick these steps together.

## Task 2 — Single calendar lock contract across every writer

**Files:** `VenueCalendarLocks`, `VenueAvailabilityService`, `VenueBookingService`, `Venue/InternalBookings`, `VenueInquiryController`, `VenueCalendarController`; new `tests/Infrastructure/Marketplace/VenueCalendarConcurrencyTest.php` and bounded worker fixture under `tests/Fixtures/marketplace/`.

- [ ] Write failing real-PostgreSQL worker races: legacy public create vs blackout; two host holds; public hold vs internal request; internal approval/cancel vs blackout; blackout removal vs new occupancy; legacy confirmation vs conflicting newly won slot. Add expiry/renewal boundary and two non-overlapping-slot controls.
- [ ] Run the focused concurrency file against an explicitly named isolated test DB, never the local browsing DB. Confirm failures demonstrate overlap or wrong lock order, not fixture setup.
- [ ] Route every occupancy creation/change/removal through listing → booking locking in a landlord transaction, including legacy host hold/custom-quote paths and internal decisions/cancellation. Reload times/state/ownership under lock and recheck conflicts before becoming blocking; public availability GET stays a non-reserving preview. Existing internal open-request limits must remain serialised, not weakened by moving locks.
- [ ] Enlist legacy confirmation in that contract without adding a new payment implementation. If confirmation conflicts, append idempotent `payment_calendar_conflict` action evidence keyed by provider reference, retaining received reference/amount/currency and the original booking; do not mark the venue confirmed, overwrite the winner, post a new sale, re-charge or refund. Commit that exception before returning an explicit reconciliation outcome to callers; ensure callback/webhook handlers cannot roll it back, report success as a reservation, or lose it on retry. Existing repeated confirmed-reference behaviour stays idempotent. Full common verification and exception resolution is an S2b release gate.
- [ ] Audit all booking writers with `rg` across controllers/services, including event linking, operations and deletion; document which only link/read and which acquire the contract. No direct slot-date edits for approved requests; cancel/re-request instead.
- [ ] Run races plus existing `VenueBookingTest`, `VenueCalendarTest`, `InternalBookingsTest`, `RoomAvailabilityTest`, operations/event-link tests. Commit and tick. No claim of concurrency safety from sequential tests alone.

## Task 3 — Approval-first requests, access and transitions

**Files:** `VenueBookingApprovals`, Form Requests, public `VenueBookingController`, tenant `VenueInquiryController`, routes; new `tests/Feature/Marketplace/VenueBookingApprovalsTest.php` and `VenueBookingApprovalAccessTest.php`.

- [ ] Write failing tests for verified public request, both pricing modes, pending non-blocking requests, host approve/decline/release/renew, buyer cancellation, policy and rollout revocation, foreign IDs, staff-only/merchandise/supplier kinds, wrong email and replay/hash mismatch. Snapshot venue policy/timezone on each request; subsequent policy edits do not silently extend or change its hold.
- [ ] Confirm these fail; implement the named interfaces with existing Gate/tenant-context and verified-email helpers. Host decision reasons are bounded 1–1000 characters; hours integer 1–168; request operation UUID required; reject unexpected pricing/tenant/status/payment fields. Keep the existing public booking throttle and add verified-email/listing mutation limits via existing limiter patterns (10 request submissions/hour/email and 30 decisions/minute/staff actor). Replays return the original action result; state drift gives an explicit conflict, never a renewed deadline.
- [ ] Select new flow only for enabled, active, published, explicitly bookable venue policies. New requests do not call `initializeDepositCheckout()`. Guard both controller checkout and direct service initialisation plus confirmation against new-flow rows; forged metadata cannot confirm/pay them. Keep legitimate legacy callback/webhook observations processable regardless of new rollout switch.
- [ ] Protect new dossier show/actions by matching verified email or authorised owning host; preserve safe return URL through verification. Public slot payload returns busy times only (no contact, request IDs, staff identity or hold reason). Recheck authorisation on every action and read; no anonymous bearer-only dossier for new requests.
- [ ] Use `VenueApprovalNotice` and `DeliverVenueApprovalNotice` after committed transitions, keyed to the immutable action ID. Delivery metadata starts queued; the job claims once before sending and records sent/failed/uncertain. Do not automatically resend a claimed/ambiguous send; expose staff state and the authoritative online decision. Acknowledgement and decision copy contain no Paystack link or “confirmed” claim. Failed mail must not roll back occupancy; show current decision online and staff delivery status. No automatic resend of ambiguous mail from a buyer refresh.
- [ ] Run new access/transition tests plus existing unbookable/staff-hidden/legacy checkout tests; Pint; commit and tick.

## Task 4 — Clear public, host and calendar UI

**Files:** public `Venues/Show.jsx`, `Bookings/Show.jsx`; tenant `Inquiries/Index.jsx`, `Inquiries/Show.jsx`, `Calendar/Index.jsx`, `Spaces/Form.jsx`; controller payloads, existing feature admin; targeted JS tests.

- [ ] Write failing UI tests: “Request venue”, “Awaiting host approval”, exact hold deadline/timezone and expired state, approval controls only for authorised host, conflict/error feedback, no pay action for new requests, retained paid-legacy receipt/operations links. Cover empty state, loading/double-click and server-rejected stale decisions.
- [ ] Add policy controls in the existing venue form (approval-first, default hours, explicit timezone); no instant-booking selector until separately designed. Reuse Inquiries/Calendar navigation and existing console components. Expose separate host Approve dates / Decline / Release / Renew controls with reason/hold inputs, not the legacy “Accept & hold” payment shortcut for new rows.
- [ ] Mark pending public requests as non-reserving in the host calendar and active holds as tentative with expiry. Public availability says unavailable without exposing why. For opted-in new requests, advertised pricing is labelled indicative; suppress legacy due-now/service-fee/payment prompts until S2b, without changing the stored legacy price calculations. Show a persistent host “Payment/calendar conflict — reconciliation required” banner from conflict actions on legacy records, with no automatic retry-payment/confirm/refund button. Do not label approval as a paid or confirmed booking; expired/rejected/cancelled rows have no payment CTA. Show email verification and clear back/return links.
- [ ] Use local QA fixtures for priced and price-on-request venues, competing requests, private staff rooms and legacy paid bookings. Browser-check desktop/390px, keyboard/focus, dark mode, expiry with a stale open tab and online authoritative refresh. Never seed production or change Purpledot merchandise flags for this test.
- [ ] Run affected Vitest files and console component usage checks; ESLint/Pint; rebuild and commit `public/build` with UI and ticked plan steps.

## Task 5 — Acceptance, one review and reviewable release package

**Files:** targeted tests from Tasks 1–4; `docs/superpowers/marketplace-roadmap.md` and this plan.

- [ ] Run affected suites once after final changes, including availability/approval, internal booking identity/limits, merchant boundaries, operations/event linkage, legacy payment conflict/replay and merchandise checkout isolation. Rerun only failures or changed surfaces; don't loop the whole suite per task.
- [ ] Repeat the real-worker races independently from transactional feature tests (no simultaneous DB truncation). Prove winner/loser and notification/evidence outcomes, not just one HTTP error. Verify current public availability and staff calendar agree at the exact expiry boundary.
- [ ] Perform self-review (“Is there anything I have overlooked? Is the solution optimal? Let me take a step back.”), then one fresh whole-change review. Include money/legacy boundaries and every writer/reader, not only new endpoints; fix findings with targeted regressions.
- [ ] Update permissions/feature discovery only if needed: reuse `manage venue`, no new pricing/payment authority. Register the new off-by-default feature with the named rollout command; verify it appears in the existing `Admin/FeatureController` CRUD after registration and can be explicitly disabled. No new feature seeder, package entitlement or env flag. No dependency/config change without scope approval.
- [ ] Record outcomes and unresolved limits; open a reviewable PR and run one final CI gate. No merge/deploy/production opt-in until separately authorised. Plan S2b with full verifier/attempt/ledger recovery, approval-to-checkout handoff, deposit/balance policy and settlement before venue payment activation. S2c remains pro-forma/bank-transfer evidence/manual verification.

## Plan self-review and owner review points

Coverage checked against the unified-commerce preservation contract and original approval/calendar handoff. Corrected an initial scope risk by including legacy payment confirmation in the occupancy lock audit and explicitly requiring durable conflict evidence; a lock alone cannot fix late captures. Kept public pending requests distinct from existing staff pending requests so public enquiry spam cannot indefinitely hold rooms. Exact expiry and idempotent renewals are assigned to Tasks 1–3; foreign identity and notification failure to Task 3; display/legacy navigation to Task 4; actual concurrency and one-review cadence to Tasks 2/5.

**Please review the proposed defaults:** non-reserving public requests; 48-hour host holds with a seven-day maximum; email-first private access; no new payment in S2a; and separate production venue activation. No implementation has begun. Recommended execution after approval: native approach, with one fresh final review.
