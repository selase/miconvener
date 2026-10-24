# Marketplace audit and reconciled roadmap

**Date:** 2026-10-22. **State:** proposed for owner review; no implementation approval.

**Purpose:** finish the [Marketplace Architecture & Strategy Handoff](specs/2026-09-20-event-store-handoff.md), through a unified catalog and commerce structure, with organisation merchandise as the first completed use case. Keep the original vision, reuse functioning code, and stop treating a model, mocked payment test or public page as proof of a complete trading workflow.

**Direction agreed 2026-10-22:** one catalog/storefront and shared commerce infrastructure, with type-specific availability and fulfilment for purchases, rentals, venue reservations and services. The detailed roadmap and first-release defaults remain for review; this agreement does not authorise implementation.

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
| Print / association merchandise | Listing-kind constant only; retail workflow missing | First use case of unified commerce: merchandise variants/stock, shared orders and payments, verified purchasing, collection and reconciliation | E1, §5 |
| Reciprocal host–buyer reputation | Partial | Buyer-to-shop review after paid/accepted quote and aggregate score exist; no reciprocal host-to-buyer system found | E8 |
| Composite ranking | Partial | Structured/semantic relevance, verification, paid boost and rating signals exist; no reconciled cross-category ranking contract or reciprocal reputation model | E9, E10 |
| Paid boosts and featured placement | Implemented foundation | Preserve add-on purchase/time-window behavior; assess sponsored disclosure and fair ranking before wider promotion | E9 |
| AI marketplace concierge / semantic discovery (beyond original handoff) | Implemented foundation | Retain existing MCP tools, partner access controls, staff-room exclusions and embedding refresh; expand by type only after catalog rules are approved | E10 |

## 4. Findings that change the delivery plan

### F1 — Approval-first is not universal

`VenueBookingService::createBooking()` selects `pending_payment` for ordinary priced bookings. `VenueBookingController::book()` can immediately start checkout. The host's hold/quote screens do not by themselves prevent that path. Treat universal public approval as **unfinished**, while preserving the existing owner-approved browsing-only posture. Staff-room approval has its own policy and must remain separate.

### F2 — Sequential availability checks are not concurrency protection

Public creation checks availability and then creates a booking without a shared lock/transaction around the check-and-create operation. Host hold/quote transitions also need simultaneous-request scrutiny. `InternalBookings` already locks the listing; reuse the single availability semantics but prove all public/internal/blackout writers serialize consistently. Existing overlap tests are not a concurrent transaction acceptance test.

### F3 — Reconcile payment services before building shared commerce

Observed discrepancies and missing guarantees to resolve before taking new payments:

- Handoff describes gateway split settlement; the two marketplace checkout services use a plain platform gateway. PaystackGateway supports split configuration elsewhere, but that does not mean these checkouts use it.
- `MarketplaceFees` has its own configured percentage and buyer-plan fee schedule. It does not use the current event→tenant→package→platform fee cascade. The event-focused `PlatformFeeResolver` cannot simply receive a merchandise order: agree a transaction-context extension for purchases, rentals, venues and services, preserving superadmin control, explicit zero waivers, caps and stored terms.
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

The handoff lacks enough detail on stock, sizes, expiry, duplicate captures, order cancellation, collection, returns/refunds, seller settlement and support ownership. Agree those rules as part of the unified commerce design, implementing the purchase lifecycle first. Shared order infrastructure must retain type-specific reservation/fulfilment records: a venue reservation must not become a stock-decrement sale. Membership dues, voluntary gifts and event admission QR codes remain separate domains.

## 5. Unified catalog and commerce; merchandise first

The unified direction is agreed. The following boundaries, first-release defaults and delivery slices are proposed for review before a detailed design/implementation plan.

### Shared structure

1. **One catalog:** retain `Shop → StoreListing` as the customer-facing catalog and organisation storefront. Products, rentals, venues and services are types of offering in that catalog, with shared identity, branding, media, descriptions, category, publication, visibility and seller ownership. Add type-specific details/variants as needed; do not introduce an independent Product catalog competing with StoreListing. Exact child tables and interfaces belong in the design.
2. **Shared commerce:** common customer verification, order header/line-item concepts, quantity/options, quoted price and terms snapshots, approval where required, payment attempts, receipts, cancellation/refund orchestration, ledger/reconciliation and order-history foundations. Store delivery mode and type-specific fulfilment state separately from payment state. Reuse existing audited services where suitable; shared does not mean copying the current marketplace payment gaps.
3. **Type-specific availability and fulfilment:** a common availability/reservation boundary delegates to the correct rules. A purchase checks variant stock and consumes it when sold; a rental reserves quantities or assets over a date range and restores availability after return; a venue reserves a calendar interval with capacity/approval rules; a service checks provider/resource availability, scope and deliverables, often through a quotation. Expiry, approval, cancellation, late payments and refunds must coordinate with each type's reservation rules. Do not apply a permanent stock deduction to every listing.

This supports one storefront and a coherent commercial journey without forcing every offering into one giant booking/stock table. Quantity-based chair/microphone rentals must account for all overlapping reservations, while a venue's capacity and exclusive time reservation are different constraints.

### Preserve existing venue features

- Keep existing venue listings/identifiers, URLs, shop branding, media, amenities, capacity layouts, calendar/blackouts, enquiries, quotes, bookings, deposits, event links, tasks, inspections and host/planner operations working throughout the transition.
- Introduce a venue integration/adapter into shared commerce incrementally after its design and acceptance gate. Existing `VenueBooking` records and relationships remain authoritative for current reservations; do not create a second competing availability calendar or require a destructive bulk conversion.
- Preserve staff-only room privacy, internal staff booking rules, public browsing and paused-booking/payment controls. No global switch from the purchase release may activate another transaction type or alter tenant settlement settings.
- Require regression checks for venue discovery/detail URLs, availability, staff-room exclusion, quotes, existing booking/payment continuation and linked event operations before adopting shared infrastructure. Repairing the legacy gaps in §4 remains explicit later work, not an implicit change inside merchandise delivery.

### First use case and scope discipline

Build the shared foundation only as far as the purchase use case requires, with explicit extension boundaries for the other types. Do not build speculative rental/service engines or migrate legacy bookings during S1. A shared model does not promise mixed-type or multi-seller checkout in the first release.

### Proposed first release: physical merchandise

- Extend the existing organisation storefront/catalog with purchasable merchandise and the shared commerce foundations above. Merchandise-specific data covers variants, sale inventory and collection; it is not a second shop, separate sign-in or isolated payment/order system.
- One selling organisation per order; physical goods only initially. Photos, descriptions, size/colour variants, integer minor-unit prices and stock per variant; draft/published/archive states. Buyer chooses collection from the organisation or seller-arranged delivery from the seller’s offered methods. Ready orders remain available until collected/delivered; credential expiry never expires the order. Delivery integrations, multi-seller baskets, digital licensing and pre-orders/backorders are deferred.
- GHS for the initial pilot; no assumed foreign-currency conversion. Confirm settlement mode, fee policy, tax/invoice presentation and refund rules before money work. Preserve each tenant's existing gateway settings.
- Public and members-only products. Members-only access requires the existing verified identity and active membership in the selling organisation. Following is not membership, and staff-room audience rules are not member product rules. No dues requirement and no implication that a donation is a purchase.
- Public store can work for an approved shop even if Community is off. Within Community, expose Store only when the store feature is enabled; public organisation page and member app links must respect product visibility and tenant scope.
- Native email-first identity/verification for checkout/order access, using existing proof/recovery patterns. Keep phones optional; shared/reassigned numbers must not reveal order history. No new parallel sign-in system.
- Server-priced checkout snapshot and bounded stock reservation (propose 10 minutes; final provider timing belongs in the design). Revalidate stock/access when creating a quote; honour an eligible accepted quote only within its agreed window. Separate shared order, payment-attempt and type-specific fulfilment states.
- Failed/abandoned checkout releases stock. Duplicate callback/webhook cannot issue another order, reserve twice or send multiple receipts. Late/extra payments must enter reconciliation/refund review rather than oversell or lose money.
- Member order history, receipt and collection instructions; staff product/stock/order screens, ready-for-collection and collected states with auditable handover proof. Do not reuse entry QR codes for collection.
- First release includes unpaid cancellation and a staff-controlled full-refund/cancellation path for paid but uncollected orders, with provider outcome and ledger reconciliation. Agree returns/partial refunds/collected-order exceptions before expansion; publish support and collection/refund terms.
- Separate rollout capabilities by transaction type (purchase, rental, venue, service), and permissions for catalog administration, orders, finance/refunds and collection; no automatic grant via `manage venue`. Use existing permission propagation conventions. A store launch must not turn on venue bookings, supplier quote payments or other organisations' flags.

### Delivery slices, in priority order

**S1a — Unified catalog contract and purchase entry points.** Design/preview first: map common listing fields and typed details, shared commerce concepts and incremental legacy adapters. Then extend StoreListing-based publishing with merchandise variants, permissions, visibility and sale-stock administration. No rental/service engine or payment activation. Exit: an association with no dues can publish a shirt with sizes through its existing storefront; another organisation cannot see its private products; venue listings, detail URLs, existing availability and staff-only rooms remain unaffected.

**S1b — Shared commerce foundation with purchase availability.** Approve the cross-type commerce finance contract, add the shared order/payment-attempt/terms-snapshot and reconciliation foundation, implement the purchase-specific stock reservation/fulfilment adapter, single-seller merchandise checkout/receipts/history and refund support. Keep venue booking/payment paths on their existing integration until an approved migration slice; do not silently route them through the new purchase implementation. Exit: sandbox-proven last-item race, double-submit, failure/retry, duplicate/out-of-order callback/webhook, expired quote, late/extra payment and failed ledger-post cases; balances reconcile. Payment provider tests need separately agreed isolated sandbox credentials/scope, never a production credential swap.

**S1c — Collection/delivery and bounded pilot acceptance.** Versioned seller fulfilment offers, explicit buyer choice, protected delivery address, office pickup or seller-arranged delivery, preparation/dispatch/receipt proof, messages, order support and finance visibility. Exit: authorised Purpledot pilot can complete one sandbox purchase from a no-dues member, receive/read the receipt and collect once; cancellation/refund and access revocation are tested. Live trading requires a separate go-ahead and actual merchant/support readiness. Do not create new permissions/features/data merely because this roadmap is accepted.

## 6. Reconciled completion sequence

| Priority | Milestone / original scope | Deliverable and acceptance gate | Proposed state |
|---|---|---|---|
| 1 | S1a–c: unified catalog/commerce, merchandise first (original Build 3 expansion) | Establish shared foundations with the purchase adapter; complete the slices above; preserve original venue features and booking controls | S1a deployed via PR #49 / b358f658 (2026-10-22), catalog/checkout switches off; S1b deployed via PR #50 / `7a90c66c` with checkout/catalog off; commercial/provider activation gates open; S1c native execution approved; implementation in progress on `codex/commerce-s1c`; seller offers/buyer arrangements implemented with focused checks (2026-10-24); readiness/proof/handover/delivery work remains; earlier preview pickup-only |
| 2 | S2: venue integration and transaction completion (Build 2) | Incremental adapter to shared orders/payments where approved, retaining authoritative booking/calendar records; approval-first public flow; shared lock/availability rules; expiry/cancellation/late payment; common validated confirmer; reconciled ledger and seller settlement; decide split vs platform collection; bank-transfer/pro-forma/evidence/manual review designed as its own slice. Exit: sandbox lifecycle plus concurrency/privacy acceptance | Proposed; blocked from trading by finance and merchant readiness gates |
| 3 | S3: rental/service commerce completion (Builds 1/3) | Extend the unified catalog and shared commerce with dated quantity/asset availability and returns for rentals, scheduling/deliverables for services; finish type-aware forms/detail/search/storefront links and consistent kind vocabulary; connect existing RFQ/proposals. Exit: seller creates→publishes→buyer discovers→requests→seller quotes→sandbox payment, without hand-created database listings | Proposed; reuse E8 rather than rebuild |
| 4 | S4: operations and booker handoff (Build 2) | Complete authenticated venue message delivery/live updates and private attachments; preserve multi-event tasks/inspections/calendar; walk-in external booker intake; explicit consent/identity and idempotent booking→event creation with authorised tenant ownership. Exit: two organisations collaborate; wrong tenant denied; retry cannot create another event | Proposed; design separate from staff-room booking |
| 5 | S5: trust and growth completion (Build 3) | Reciprocal review eligibility/consent/moderation/disputes; declared cross-category ranking; sponsored placement disclosure and add-on lifecycle/visibility verification. Exit: only eligible completed transactions review; no retaliation/unauthorised edits; expired promotion loses ranking | Proposed; existing reviews/promotions retained |
| Always | Merchant and launch readiness | Verify actual participating merchants, accurate listings, support ownership, operational policies and agreed payment journey before promoting transactions | Pending owner confirmation; browsing stays available |

Do not release merchandise, services and venue changes as one large PR. Each slice gets its own approved spec/plan, failing tests first, proportionate checks, one scoped self-review and one fresh whole-change review. Use affected local suites and one final CI gate rather than repeatedly running the entire suite for unchanged code. JS/CSS changes include rebuilt `public/build`; no full asset build is needed for this documentation-only audit.

## 7. Owner decisions and recommendation

The owner has agreed the unified structure and merchandise-first direction. Approve or amend these remaining details before preparing the S1 build plan:

1. **Pilot scope:** recommend physical merchandise, one organisation per order, buyer-selected seller-offered collection or seller-arranged delivery, GHS, no dues dependency; delivery included in product price for this bounded slice. Public and active-member-only visibility supported; defer discounts, shipping, pre-orders and digital goods.
2. **Commercial/settlement contract:** decide store fee/bearer/cap policy and which existing tenant settlement modes are supported for the pilot. Recommend no automatic adoption of the old 10%, no forced gateway change, and no checkout until reconciliation/refund/payout responsibilities are explicit.
3. **Operational policy:** approve the collection, cancellation/refund/support rules and identify the pilot's real operator/products. Existing standing Purpledot approval for Community does not itself approve merchandise trading, sandbox payments or a broader marketplace rollout.
4. **Sequence and migration:** recommend S1→S2→S3→S4→S5, completing shared finance/privacy prerequisites inside S1; integrate existing venues incrementally, then rental/service adapters. Mixed-type checkout, legacy conversion mechanics and resource-allocation details require their own approved design. Urgent defects affecting currently active use can be separately prioritised; no new exposure is authorised here.

Prepared for review: [unified catalog/commerce design](specs/2026-10-22-unified-commerce-design.md) and [S1a catalog implementation plan](plans/2026-10-22-unified-commerce-s1a-catalog.md), with a standalone synthetic storefront preview saved as a local review artifact. These define the merchandise-first UI, venue-preservation boundaries and typed availability contracts. S1b/S1c plans follow their commercial/operational decisions. Owner clarified 2026-10-22: start with option1 (design/build/test merchandise and prepare a PR without deployment/payment activation), then progress systematically through the entire roadmap. S1a was subsequently owner-approved for merge/deployment and is deployed as PR #49; organisation rollout/trading gates and later commercial decisions remain explicit.

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
- Revision 2026-10-22: self-reviewed the unified-structure amendment against the agreed direction; verified catalog reuse, shared orders/payments, all four availability/fulfilment types, venue preservation, gradual adoption, separate rollout controls and merchandise-first scope. The 132-test result above belongs to the earlier audit; this documentation-only revision did not rerun those unchanged application tests.
- Original handoff remains historical context. After owner approval, this roadmap becomes the continuation/status index; the original note links here without rewriting old decisions as newly approved rules.

### Review checklist

- [x] Audit all original handoff promises and retain evidence for partial work.
- [x] Reflect the agreed unified catalog/commerce direction, with merchandise first and type-specific availability/fulfilment.
- [x] Preserve existing venue features through explicit incremental integration and regression gates, without activating other transaction types.
- [x] Preserve existing venue, Community, dues, Giving and verification boundaries in the proposed approach.
- [x] Separate source evidence, local testing, sandbox acceptance and production readiness.
- [x] Owner approves the unified direction and merchandise-first execution (2026-10-22); commercial activation gates remain open.
- [x] Prepare unified catalog/commerce design, merchandise-first visual preview and S1a plan; self-review and scoped builder check completed.
- [x] Owner authorises merchandise implementation and systematic roadmap continuation (2026-10-22).
- [x] Approve S1a catalog plan and start native implementation; typed stock and privacy/legacy-isolation tasks are built and locally verified.
- [ ] Prepare detailed S1b/S1c plans and resolve their commercial/provider acceptance gates before payment activation.

## Current continuation package (2026-10-22)

S1a is deployed on `main` via [PR #49](https://github.com/selase/miconvener/pull/49), merge `b358f658`, Cloud release `depl-a30b889b-4c89-46b4-9098-3a77fe5bc3bf`. Full CI: 3,741 PHP / 17,750 assertions (one non-failing risky summary), 473 JS; checks/production-read evidence in the S1a plan. Catalog and checkout flags remain off. Review the [S1a self-review and release limitations](specs/2026-10-22-commerce-s1a-self-review.md). The [next-slice decisions and systematic sequence](plans/2026-10-22-commerce-continuation-gates.md) preserve the remaining commercial/provider/rollout gates. Do not treat catalog implementation or local QA as approval to enable checkout or deploy.

**Next action:** use the revised S1c collection/delivery design and native plan linked below. Owner required the planning amendment before implementation; ready orders have no expiry and a pass limits only verification. The prior synthetic preview covers pickup only. S1b is deployed with checkout disabled. Provider/mobile/mail acceptance, numerical/support/funding terms and named pilot approval remain open before trading.

**S1b implementation (2026-10-22):** owner approved the checkout plan and native build with preservation/self-review before merge. Immutable order/access and versioned agreement/shared arithmetic tasks are committed (`2b5b085e`, `d0fef3ad`); stock holds are locally verified. No merge/deploy, named commerce pilot or production payment activation is inferred.

## S1c review package (2026-10-24)

S1b is merged/deployed as PR #50, merge `7a90c66c`, Cloud `depl-a30c9c77-244c-4e04-8162-5b85098e1d20` (2026-10-23 08:55:05 UTC). Pre-merge CI passed 3,864 PHP /18,210 assertions and479 JS; one risky-test warning is also present on successful main. Post-release checks confirmed all Commerce tables/permissions, healthy public pages/assets, zero enabled checkout/catalog organisations and zero production Commerce orders/agreements. No checkout/trading activation occurred.

**Next review:** [S1c collection design](specs/2026-10-24-commerce-s1c-collection-design.md) and [native implementation plan](plans/2026-10-24-commerce-s1c-collection.md). Synthetic interactive preview lives in the task's visualization directory as `commerce-collection-preview.html`. Review complete-order bearer handover, sharing warning, 15-minute proof, no staff override and collection-role defaults. A fresh read-only builder check found no planning blockers. Owner subsequently approved native implementation, then required the collection/delivery amendment before code. No merge/deploy/rollout approval is inferred.

After owner review: native S1c implementation and one whole-branch review/CI, then separately authorised isolated provider/mobile/mail acceptance and approved pilot/commercial/support terms. Keep checkout disabled until those gates are complete. Existing venue and Community workflows remain unchanged.

### S1c owner clarification — collection and delivery (2026-10-24)

Ready orders remain available until collected or delivered, subject to explicit existing cancellation/refund safeguards. Buyers choose from versioned seller offers; office pickup and seller-arranged delivery coexist. Delivery dispatch is not buyer receipt; use recipient verification at destination, with no ready-order deadline. The revised S1c spec/plan add Tasks 0/3b for offers/consent/encrypted destinations and delivery/transit-safe refunds. Delivery included in product price is the bounded recommendation; separate fees/COD, courier integrations and tracking are deferred. The earlier collection-only pilot language is superseded by this clarification. Checkout stays off; production/provider/trading approvals remain separate.
