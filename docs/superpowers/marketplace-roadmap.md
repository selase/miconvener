# Marketplace audit and reconciled roadmap

**Date:** 2026-10-22. **State:** proposed for owner review; no implementation approval.

**Purpose:** finish the [Marketplace Architecture & Strategy Handoff](specs/2026-09-20-event-store-handoff.md), with an organisation merchandise store as the first product priority. Keep the original vision, reuse functioning code, and stop treating a model, mocked payment test or public page as proof of a complete trading workflow.

## 1. Audit boundary and evidence standard

- Baseline: fetched `origin/main`, `8127a4fc5255cadd36941f8d771955507c4d6b6a`. This documentation is isolated on `codex/marketplace-roadmap`; the ticket-layout PR #48 remains separate.
- Examined the handoff, Build 1 design, current models/migrations, routes, requests, services, public/tenant screens, tests, finance invariants, Community roadmap and staff-facility booking plan.
- The historical `feat/marketplace-build-1`, `-2`, `-3` and remote Build 3 branches contain no marketplace model/service/controller commits outside this main baseline. This does not certify unrelated untracked files in every checkout. The original Claude checkout was read-only and clean when checked; no branch/worktree was removed or rewritten.
- **Implemented foundation** means identifiable code and existing automated coverage. **Partial** means a missing connection or conflict with the handoff. **Missing** means no complete path was found in the inspected code. **Unverified operationally** means merchant participation, live provider behavior or release acceptance was not established by this audit.
- This is a repository audit plus a bounded local test run, not a new production inventory, penetration test or live booking/payment test. No production settings, listings, credentials or messages were changed.
- Verification result is recorded in §9. No percentage-complete estimate: the original three builds contain work of very different sizes.

## 2. Summary and correction to the older description

Build 3 is **not wholly unbuilt**. Supplier RFQs, itemised proposals, buyer reviews, paid verification, search boosts and featured placement already have code and tests. However, non-venue listing creation/discovery is incompletely connected, and merchandise has no retail order system. Finishing this work is reconciliation and completion, not rebuilding three phases from scratch.

Build 1 is the strongest foundation. Build 2 has substantial operations code but does not consistently deliver approval-first booking, the stated dual-payment workflow, real-time venue chat or the event-creation handoff. Passing the existing suite cannot prove these missing or conflicting acceptance cases.

## 3. Handoff reconciliation

Evidence identifiers link to the code/test inventory in §8. All rows still require appropriate release acceptance before a new trading capability is promoted.

| Handoff promise | Repository assessment | Remaining work / disposition | Evidence |
|---|---|---|---|
| Tenant-native merchants; one branded shop per organisation | Implemented foundation | Preserve Shop/Tenant ownership; confirm onboarding suits associations as well as commercial venues | E1 |
| Venue listings, media, capacities, included/excluded amenities | Implemented foundation | Preserve; check completeness of real merchant content separately | E1, E2 |
| Public marketplace, merchant storefront and venue detail | Implemented foundation; venue-oriented | Add type-aware links/sections before exposing non-venue products | E2, E3 |
| Location/radius, capacity, amenity and price-visibility filters | Implemented foundation | Preserve structured search; provider-backed embedding acceptance remains separate | E2, E10 |
| Blue-tick business verification | Implemented foundation, including paid annual lifecycle | Verify real businesses and evidence handling; payment is not proof a merchant is genuine | E4 |
| Approval-first venue booking | Partial; conflicts with handoff | Price-on-request follows enquiry/quote; published-price bookings can go directly to pending payment/checkout. Make approval an explicit public-booking policy | E5, F1 |
| Availability, deposits, calendar holds/blackouts | Implemented foundation with concurrency gap | Prove simultaneous public booking/hold/payment expiry behavior, not only sequential overlap rejection | E5, E6, F2 |
| Paystack online payments and automatic commission split | Partial; legacy implementation differs | Marketplace services initialise a platform gateway; do not call its split configuration. Ledger allocation exists, but that is not evidence of direct merchant settlement | E7, F3 |
| Direct bank transfer, pro-forma PDF, receipt upload/manual verification | Missing complete marketplace workflow | Design this specifically; online dossier/amount display and other modules' transfer features do not satisfy it | E5, E7 |
| Venue tasks integrated with event operations / eight pillars | Implemented foundation | Preserve cross-organisation boundaries, checklist/task integration and inspections | E6 |
| Private real-time venue coordination chat | Partial | Persisted host/planner messages, attachments and read state exist; no venue-message broadcast/subscription path found. Unrelated service-request Echo subscriptions do not satisfy this promise | E6 |
| Multi-event venue hub, calendar, messages/tasks | Implemented foundation; acceptance incomplete | Verify linked/unlinked events, host/planner access, actual scale and incoming-message refresh | E6 |
| Lightweight booker portal | Implemented foundation | Booking dossier and payment continuation exist; define access/recovery rules and complete post-booking lifecycle | E5 |
| Walk-in/offline booking intake plus one-click event workspace launch | Partial / launch missing | Internal staff requests exist as a separate flow; no complete public/offline-client-to-event conversion found | E6, E11 |
| Equipment and service suppliers | Partial, more than schema only | RFQ/proposal/payment endpoints exist, but creation validation, listing-kind vocabulary, detail routing and storefront links need joining up | E3, E8, F4 |
| Print / association merchandise | Listing-kind constant only; retail workflow missing | First priority: products/variants/stock, orders, verified purchasing, collection, refunds and reconciliation | E1, §5 |
| Reciprocal host–buyer reputation | Partial | Buyer-to-shop review after paid/accepted quote and aggregate score exist; no reciprocal host-to-buyer system found | E8 |
| Composite ranking | Partial | Structured/semantic relevance, verification, paid boost and rating signals exist; no reconciled cross-category ranking contract or reciprocal reputation model | E9, E10 |
| Paid boosts and featured placement | Implemented foundation | Preserve add-on purchase/time-window behavior; assess sponsored disclosure and fair ranking before wider promotion | E9 |
| AI marketplace concierge / semantic discovery (beyond original handoff) | Implemented foundation | Retain existing MCP tools, partner access controls, staff-room exclusions and embedding refresh; expand by type only after catalog rules are approved | E10 |

## 4. Findings that change the delivery plan

### F1 — Approval-first is not universal

`VenueBookingService::createBooking()` selects `pending_payment` for ordinary priced bookings. `VenueBookingController::book()` can immediately start checkout. The host's hold/quote screens do not by themselves prevent that path. Treat universal public approval as **unfinished**, while preserving the existing owner-approved browsing-only posture. Staff-room approval has its own policy and must remain separate.

### F2 — Sequential availability checks are not concurrency protection

Public creation checks availability and then creates a booking without a shared lock/transaction around the check-and-create operation. Host hold/quote transitions also need simultaneous-request scrutiny. `InternalBookings` already locks the listing; reuse the single availability semantics but prove all public/internal/blackout writers serialize consistently. Existing overlap tests are not a concurrent transaction acceptance test.

### F3 — Do not copy the old payment services into merchandise checkout

Observed discrepancies and missing guarantees to resolve before taking new payments:

- Handoff describes gateway split settlement; the two marketplace checkout services use a plain platform gateway. PaystackGateway supports split configuration elsewhere, but that does not mean these checkouts use it.
- `MarketplaceFees` has its own configured percentage and buyer-plan fee schedule. It does not use the current event→tenant→package→platform fee cascade. The event-focused `PlatformFeeResolver` cannot simply receive a merchandise order: agree a sale-context extension preserving superadmin control, explicit zero waivers, caps and stored terms.
- Booking browser callback checks metadata/amount, whereas the shared booking confirmer lacks equivalent full status/currency/expected-amount/account validation. Quote confirmer trusts metadata/amount more broadly; its browser path indexes metadata as an array, unlike the webhook's normalisation. Validation must be common to callback and webhook.
- Neither marketplace path provides the durable payment-attempt/terms-snapshot and full reconciliation model now used by newer money flows. Querying a reference before updating is insufficient proof against concurrent duplicate or extra captures.
- `MarketplaceLedger::post()` catches posting errors; payment state can advance while ledger posting fails, and reference-based early returns can skip a later repair. Require atomic posting or durable retry/reconciliation with a visible staff exception state.
- Marketplace ledger category exists. A complete marketplace seller payout, balance/refund and OTP/reconciliation workflow was not found in these paths; an `organizer_payable` credit is not proof the seller has been paid.

**Release gate:** adopt a documented commerce finance contract and regression/sandbox tests before merchandise payments, and before public booking payments are newly enabled. This does not authorise changing existing production commissions or settlement modes. The old handoff's 10%/split assumptions are historical, not a new commercial instruction.

### F4 — Supplier foundations are disconnected from catalog UI

`VenueListingController` reads `listing_kind`, but `StoreVenueListingRequest` does not validate/return it; normal creation therefore defaults to venue. Search service supports a kind filter but its public request omits it. Detail controller explicitly restricts to venue; storefront cards send every listing to a venue detail URL. The detail JSX checks `equipment`, while the model's rental value is `rental` (`KIND_EQUIPMENT` is only an alias). Existing RFQ tests create rental records directly and POST to endpoints, so they do not prove a seller can create, publish and sell a rental through the UI.

### F5 — Explicit public data and identity boundaries are needed

The storefront passes the Shop model directly, including attributes such as verification-document metadata/business registration/tax fields that are not all hidden. Define explicit public payloads before adding commerce profiles; confidential merchant evidence must stay staff-only. RFQ `event_id` currently validates existence rather than planner ownership; prove foreign-event attachment is rejected. Review customer-private quote/order/review access: a reference URL alone should not be treated as a verified member account. These are code-level concerns, not claims of a production breach.

### F6 — Missing fulfilment/lifecycle work matters as much as checkout

The handoff lacks enough detail on stock, sizes, expiry, duplicate captures, order cancellation, collection, returns/refunds, seller settlement and support ownership. Agree those rules as part of the merchandise design. Do not repurpose a venue booking, membership dues charge, voluntary gift or event ticket QR into a product order.

## 5. First priority: organisation merchandise store

These are **recommended defaults for review**, not decisions already approved by the owner.

### Proposed first release

- Reuse the organisation's existing Shop/branding/tenant identity and marketplace discovery conventions. Add a distinct product/order domain under the existing application, rather than forcing inventory into venue booking records or creating a second independent storefront system.
- One selling organisation per order; physical goods only initially. Photos, descriptions, size/colour variants, integer minor-unit prices and stock per variant; draft/published/archive states. Default to collection from the organisation. Delivery integrations, multi-seller baskets, digital licensing and pre-orders/backorders are deferred.
- GHS for the initial pilot; no assumed foreign-currency conversion. Confirm settlement mode, fee policy, tax/invoice presentation and refund rules before money work. Preserve each tenant's existing gateway settings.
- Public and members-only products. Members-only access requires the existing verified identity and active membership in the selling organisation. Following is not membership, and staff-room audience rules are not member product rules. No dues requirement and no implication that a donation is a purchase.
- Public store can work for an approved shop even if Community is off. Within Community, expose Store only when the store feature is enabled; public organisation page and member app links must respect product visibility and tenant scope.
- Native email-first identity/verification for checkout/order access, using existing proof/recovery patterns. Keep phones optional; shared/reassigned numbers must not reveal order history. No new parallel sign-in system.
- Server-priced checkout snapshot and bounded stock reservation (propose 10 minutes; final provider timing belongs in the design). Revalidate stock/access when creating a quote; honour an eligible accepted quote only within its agreed window. Separate order, payment-attempt and fulfilment states.
- Failed/abandoned checkout releases stock. Duplicate callback/webhook cannot issue another order, reserve twice or send multiple receipts. Late/extra payments must enter reconciliation/refund review rather than oversell or lose money.
- Member order history, receipt and collection instructions; staff product/stock/order screens, ready-for-collection and collected states with auditable handover proof. Do not reuse entry QR codes for collection.
- First release includes unpaid cancellation and a staff-controlled full-refund/cancellation path for paid but uncollected orders, with provider outcome and ledger reconciliation. Agree returns/partial refunds/collected-order exceptions before expansion; publish support and collection/refund terms.
- Separate commerce rollout and permissions for products, orders, finance/refunds and collection; no automatic grant via `manage venue`. Use existing permission propagation conventions. A store launch must not turn on venue bookings, supplier quote payments or other organisations' flags.

### Delivery slices, in priority order

**S1a — Catalog and member entry points.** Design/preview first, then products/variants, permissions, visibility and stock administration. No payment activation. Exit: association with no dues can publish a shirt with sizes; another organisation cannot see its private products; public listings and staff-only rooms are unaffected.

**S1b — Orders, stock reservations and payment integrity.** Approve the commerce finance contract, add durable checkout attempts and lifecycle/reconciliation, implement single-seller purchase/receipts/history and refund support. Exit: sandbox-proven last-item race, double-submit, failure/retry, duplicate/out-of-order callback/webhook, expired quote, late/extra payment and failed ledger-post cases; balances reconcile. Payment provider tests need separately agreed isolated sandbox credentials/scope, never a production credential swap.

**S1c — Collection and bounded pilot acceptance.** Staff workflow, collection proof, messages, order support and finance visibility. Exit: authorised Purpledot pilot can complete one sandbox purchase from a no-dues member, receive/read the receipt and collect once; cancellation/refund and access revocation are tested. Live trading requires a separate go-ahead and actual merchant/support readiness. Do not create new permissions/features/data merely because this roadmap is accepted.

## 6. Reconciled completion sequence after the store

| Priority | Milestone / original scope | Deliverable and acceptance gate | Proposed state |
|---|---|---|---|
| 1 | S1a–c: organisation merchandise (original Build 3 expansion) | Complete the slices above; original venue booking controls remain unchanged | Proposed; design and implementation plan next |
| 2 | S2: venue transaction completion (Build 2) | Approval-first public flow; shared lock/availability rules; expiry/cancellation/late payment; common validated confirmer; reconciled ledger and seller settlement; decide split vs platform collection; bank-transfer/pro-forma/evidence/manual review designed as its own slice. Exit: sandbox lifecycle plus concurrency/privacy acceptance | Proposed; blocked from trading by finance and merchant readiness gates |
| 3 | S3: supplier catalog completion (Builds 1/3) | Type-aware listing forms, rental/service details, search/category/storefront links, consistent kind vocabulary; connect existing RFQ/proposals. Exit: seller creates→publishes→buyer discovers→requests→seller quotes→sandbox payment, without hand-created database listings | Proposed; reuse E8 rather than rebuild |
| 4 | S4: operations and booker handoff (Build 2) | Complete authenticated venue message delivery/live updates and private attachments; preserve multi-event tasks/inspections/calendar; walk-in external booker intake; explicit consent/identity and idempotent booking→event creation with authorised tenant ownership. Exit: two organisations collaborate; wrong tenant denied; retry cannot create another event | Proposed; design separate from staff-room booking |
| 5 | S5: trust and growth completion (Build 3) | Reciprocal review eligibility/consent/moderation/disputes; declared cross-category ranking; sponsored placement disclosure and add-on lifecycle/visibility verification. Exit: only eligible completed transactions review; no retaliation/unauthorised edits; expired promotion loses ranking | Proposed; existing reviews/promotions retained |
| Always | Merchant and launch readiness | Verify actual participating merchants, accurate listings, support ownership, operational policies and agreed payment journey before promoting transactions | Pending owner confirmation; browsing stays available |

Do not release merchandise, services and venue changes as one large PR. Each slice gets its own approved spec/plan, failing tests first, proportionate checks, one scoped self-review and one fresh whole-change review. Use affected local suites and one final CI gate rather than repeatedly running the entire suite for unchanged code. JS/CSS changes include rebuilt `public/build`; no full asset build is needed for this documentation-only audit.

## 7. Owner decisions and recommendation

Approve or amend these before preparing the S1 build plan:

1. **Pilot scope:** recommend physical merchandise, one organisation per order, collection only, GHS, no dues dependency. Public and active-member-only visibility supported; defer discounts, shipping, pre-orders and digital goods.
2. **Commercial/settlement contract:** decide store fee/bearer/cap policy and which existing tenant settlement modes are supported for the pilot. Recommend no automatic adoption of the old 10%, no forced gateway change, and no checkout until reconciliation/refund/payout responsibilities are explicit.
3. **Operational policy:** approve the collection, cancellation/refund/support rules and identify the pilot's real operator/products. Existing standing Purpledot approval for Community does not itself approve merchandise trading, sandbox payments or a broader marketplace rollout.
4. **Sequence:** recommend S1→S2→S3→S4→S5, completing shared finance/privacy prerequisites inside S1. Urgent defects affecting currently active use can be separately prioritised; no new exposure is authorised here.

Next artefacts after roadmap review: an organisation-store design with desktop/mobile preview, then a task-by-task native implementation plan against the approved design. Later phases receive their own plans when reached. This roadmap is intentionally not an instruction to start coding.

## 8. Evidence index

Paths are relative to the repository. They identify implementation/test evidence, not a claim each feature is deployed or ready for production.

- **E1 — Ownership/catalog:** `app/Models/{Shop,StoreListing,StoreListingAmenity,StoreListingMedia}.php`; `tests/Feature/Marketplace/StoreModelAndMigrationTest.php`; landlord store migrations.
- **E2 — Discovery/venue display:** `app/Http/Controllers/Marketplace/{MarketplaceController,MarketplaceListingController}.php`; `app/Services/Marketplace/MarketplaceSearchService.php`; `tests/Feature/Marketplace/PublicMarketplaceDiscoveryTest.php`.
- **E3 — Catalog seams:** `app/Http/Requests/Tenant/Venue/StoreVenueListingRequest.php`; `app/Http/Controllers/Tenant/Venue/VenueListingController.php`; `app/Http/Requests/Marketplace/MarketplaceVenueSearchRequest.php`; `resources/js/Pages/Public/Marketplace/{Storefront,Venues}/Show.jsx`.
- **E4 — Verification:** `app/Http/Controllers/Admin/MarketplaceVerificationController.php`; `app/Http/Controllers/Tenant/Venue/VenueProfileController.php`; `app/Console/Commands/ExpireShopVerificationsCommand.php`; `tests/Feature/Marketplace/{SuperadminVerification,ShopPromotions}Test.php`.
- **E5 — Public booking:** `app/Http/Controllers/Marketplace/VenueBookingController.php`; `app/Services/Marketplace/{VenueBookingService,VenueAvailabilityService}.php`; `app/Http/Controllers/Tenant/Venue/VenueInquiryController.php`; `resources/js/Pages/Public/Marketplace/Bookings/Show.jsx`; `tests/Feature/Marketplace/{VenueBooking,UnbookableVenue}Test.php`.
- **E6 — Operations:** `app/Http/Controllers/Tenant/Venue/{VenueOperationsController,VenueCalendarController}.php`; `app/Http/Controllers/Tenant/EventVenueCollaborationController.php`; `resources/js/Pages/Tenant/Events/panels/FacilityCollaborationView.jsx`; `resources/js/Pages/Tenant/Venue/Operations/Show.jsx`; `routes/channels.php`; `tests/Feature/Tenant/{VenueOperations,VenueCalendar,VenueInquiry}Test.php`.
- **E7 — Money:** `app/Services/Marketplace/{MarketplaceFees,MarketplaceLedger,MarketplaceVendorService,VenueBookingService}.php`; `app/Http/Controllers/Marketplace/MarketplaceQuotePublicController.php`; `app/Http/Controllers/Public/SettlementWebhookController.php`; `app/Services/Payment/PaystackGateway.php`; current `AGENTS.md` finance invariants, `app/Services/Finance/{PlatformFeeResolver,LedgerService}.php` and newer Giving/member-benefit attempt/confirmer patterns.
- **E8 — Supplier proposals/reviews:** `app/Models/{MarketplaceQuote,MarketplaceReview}.php`; `app/Services/Marketplace/MarketplaceVendorService.php`; public quote controller and `resources/js/Pages/{Public/Marketplace/Quotes,Tenant/Venue/Quotes}/Show.jsx`; `tests/Feature/Marketplace/{MarketplaceVendorExpansion,MarketplaceQuoteFollowThrough}Test.php`.
- **E9 — Promotions/ranking:** `app/Models/{Shop,TenantAddon}.php`; marketplace/search controllers/services; `tests/Feature/Marketplace/ShopPromotionsTest.php`. Existing paid placement is not evidence of the full reciprocal reputation strategy.
- **E10 — Semantic/MCP:** `app/Mcp/MarketplaceMcpServer.php`, its venue tools and partner middleware; `app/Services/Marketplace/VenueEmbeddingService.php`; `tests/Feature/Marketplace/{AiSemanticSearch,MarketplaceMcpServer,VenueEmbedding}Test.php`.
- **E11 — Staff rooms:** `app/Services/Venue/InternalBookings.php`; `tests/Feature/Venue/`; [staff facility booking plan](plans/2026-10-19-staff-facility-booking.md). Protect staff-only inventory across public search, storefront, MCP and booking endpoints.
- **E12 — Existing acceptance/constraints:** [Community roadmap](community-roadmap.md); [Community follow-ups §5](plans/2026-10-21-community-followups.md); [Build 1 design](specs/2026-09-20-event-store-build1-design.md). Marketplace readiness reminder stays active until the owner's explicit confirmation/stop instruction.

## 9. Verification and self-review

- Source audit mapped every handoff section, all four commercial categories, each original build and the later MCP/staff-room additions.
- Existing bounded test run: `php artisan test --compact tests/Feature/Marketplace tests/Feature/Tenant/VenueInquiryTest.php tests/Feature/Tenant/VenueOperationsTest.php tests/Feature/Tenant/VenueCalendarTest.php` against the local testing database. **132 tests passed, 808 assertions, 171.99 seconds on 2026-10-22.** No new tests or application changes were introduced.
- Test coverage is evidence of covered behavior only: endpoint-created rental fixtures do not prove complete seller UI; sequential booking rejection does not prove concurrency; mocked Paystack success does not prove settlement/provider acceptance; persisted messages do not prove real-time delivery.
- Finance contradictions, confidential payload/access boundaries, stock/fulfilment and acceptance ownership are explicit gates, not silently accepted trade-offs. No promise of completion dates before designs and dependencies are agreed.
- Original handoff remains historical context. After owner approval, this roadmap becomes the continuation/status index; the original note links here without rewriting old decisions as newly approved rules.

### Review checklist

- [x] Audit all original handoff promises and retain evidence for partial work.
- [x] Make merchandise the first product priority without enabling booking/payment capabilities.
- [x] Preserve existing venue, Community, dues, Giving and verification boundaries in the proposed approach.
- [x] Separate source evidence, local testing, sandbox acceptance and production readiness.
- [ ] Owner reviews roadmap and proposed defaults.
- [ ] Prepare/approve merchandise design and visual preview.
- [ ] Prepare/approve detailed S1 implementation plan; only then consider implementation.
