# Paid Badge Templates Implementation Plan

> **For agentic workers** use `superpowers:executing-plans` for the native implementation, task by task. Track each step in its implementation commit. Use test-first development and one focused whole-change review before merge.

**Status** PR #100 merged as `5ef3652c` and deployed on 12 November 2026. Hosted Paystack test payment succeeded. Its full refund is pending provider processing. Acceptance found a tenant-host return-page error, corrected and regression-tested in the follow-up branch. Production seller setup and paid activation remain pending.

**Goal** Sell a reusable design-family licence for GH₵50 per event, with unlimited edits and reprints, through the badge library.

**Architecture** Add a typed digital-template offering to shared commerce. Keep the platform seller separate from the purchasing organisation and event. Issue an event licence in the same transaction as verified payment posting, and enforce that licence at every clean-artwork and print boundary.

**Tech stack** Existing Laravel, PostgreSQL, React, Paystack, DomPDF and queue infrastructure. No new dependencies.

**Spec** `docs/superpowers/specs/2026-11-11-design-library-miprinting-design.md`, sections 3–4 and M2. M1 shipped in PR #99, merge `656e3ec8`. Purpledot’s conference design is saved in its organisation library.

## 1. Review decisions

The approved commercial requirement is GH₵50 per event with unlimited reprints. The following are recommendations for owner approval with this plan.

- GH₵50 is the complete customer charge, 5,000 pesewas in GHS. MiConvener absorbs payment-processing costs. Tax treatment and the legal seller’s receipt details must be confirmed before paid activation. Any required tax must fit the approved total or return for price approval.
- The licence belongs to the purchasing organisation, event and design family. Include all attendee quantities, edits, both faces, orientation/custom-size variants and later versions in that family. A later version requires explicit preview and application. Another event or genuinely different family requires its own purchase.
- Allow full refunds requested within seven calendar days of verified payment while the licence is unused. Applying a design and editing it are permitted before first clean output. A clean preview, artwork download, PDF or production-pack request counts as use. Explain this immediately before the first such request.
- After first use, route refund requests to platform support. No automatic refund promise. Defective content, duplicate captures and payment errors remain eligible for support assessment. Legal obligations take precedence over the commercial rule.
- Platform staff may approve an exceptional full refund after use, with reason and audit evidence. It suspends future licensed output and retains historical sale/use records. Previously delivered files cannot be recalled.
- Purchases require an explicit delegated permission and verified payer email. No automatic charge or saved plan-billing authorisation. The buying organisation may use its own payment gateway for other streams while purchasing this platform product.
- Keep paid-template checkout off until the seller identity, tax treatment, refund wording and funding arrangements are approved and acceptance passes. Free/private template use remains available throughout rollout.

## 2. Current code and integration constraints

- `BadgeLibraryService::apply()` currently rejects paid designs. `BadgeLibraryController::preview()` sends only a watermarked raster for a paid design. M2 replaces those checks with event entitlement checks.
- `BadgeLibraryService::save()` can copy artwork and `source_version_id`. One editable source field is insufficient to secure paid derivatives. Explicit immutable provenance must survive save, edit, revision, restore and cross-event application.
- `CommerceOfferingRegistry` supports purchase and reservation. `CommerceOrderLocks` selects locking by persisted order type. Both must explicitly support `badge_template`.
- Database checks and the agreement composite foreign key currently permit only purchase/reservation. Lines currently require a listing plus a physical variant or booking. Digital schema and source guards need explicit changes.
- `StoreOrderAccess` uses a verified buyer email and seller-tenant staff membership. Buyer-organisation staff access needs a separate checked association and event scope. The buyer must never gain access to the platform seller’s other orders.
- `StoreRefunds` currently assumes merchandise/venue authorisation, seller-payable funding and restocking. `StoreBalance`, fulfilment actions, lists and payouts must explicitly exclude platform digital revenue from merchant availability.
- `LedgerService` is the only ledger writer. Reuse original-account payment references, observations, reconciliation and receipt outbox. Extend accounting inside that service for a platform sale and reversal.

## Global constraints

- Integer money, GHS only, server price 5,000 pesewas. Read no client price, seller identity or commission rate.
- Preserve existing purchase/reservation behaviour, existing flags and commercial terms.
- No stock, delivery address, collection pass or venue hold is required by a digital order.
- No attendee email, announcement, membership or follower operation. A transactional purchase/refund notice goes only to the verified payer under the receipt policy.
- Private artwork and paid snapshots stay server-side until authorised clean access. Financial and private-design responses are private/no-store and excluded from offline caches and telemetry.
- No dependencies, production migrations, seller setup, payments, refunds or activation during plan preparation.
- At implementation time, re-read AGENTS.md finance invariants and inspect current main. Reuse a suitable isolated checkout and dedicated disposable test database. Never run overlapping test processes against one database.
- Permission additions update PermissionsSeeder, RolePermissions and EventOrganiserAccess together. Tenant roles cannot gain platform finance powers.

## Review Focus

1. Two staff purchasing the same family/event concurrently must share one pending checkout and produce one active licence. Task 3 and Task 8.
2. A paid design copied into an organisation library, stripped of its visible artwork, or restored from history must retain its licence requirements. Tasks 1 and 5.
3. A refund racing a clean export must have one serialised outcome and an audit record. Tasks 5, 6 and 8.
4. Changing a gateway, price, actor role or event state after checkout must preserve evidence and recover money without granting unauthorised access. Tasks 3, 4 and 8.
5. A withdrawn design or permanently deleted event must preserve purchase/refund evidence and honour existing valid licences appropriately. Tasks 1, 5 and 8.

## 3. Data and accounting decisions

### Digital ownership and immutable evidence

Add `BadgeTemplatePurchase` as the typed link between one seller-owned StoreOrder and its buyer tenant/event, family, purchased version and frozen licence/price terms. A separate repurchase creates a new purchase record after confirmed refund. Enforce one unresolved checkout per buyer tenant/event/family with a database uniqueness guard and explicit attempt expiry rules. An unknown provider result remains unresolved.

Add `BadgeTemplateLicence`, unique on buyer tenant/event/family, with active/suspended/refunded/revoked state, current purchase association and first-used timestamp. Keep superseded purchases and append-only licence events. Never overwrite evidence when repurchasing. Composite ownership keys ensure the event belongs to the buyer tenant. Event deletion restricts or soft-deletes this financial association; historical records survive.

Add `BadgeTemplateUse` append-only records for output identity, actor, purpose, version and licence. A render claim counts conservatively as use before any clean bytes are returned. A failed render can be considered for a support refund, with failure evidence. This avoids holding a database lock throughout PDF rendering or a refund reopening access after clean bytes escaped.

Add `BadgeDesignProvenance` links for the current event template, library version and saved revision, each referencing every paid source family. Pure field copying carries provenance too. Replacing the entire design with an independently uploaded/free design clears current provenance only through the replacement service; old revisions retain theirs. Multiple sources require all relevant event licences. Clients cannot supply or delete these links.

### Platform revenue

Use the configured, owner-approved platform seller tenant/shop and a digital-scope agreement. The buying organisation is a separate association. The seller is never inferred from the buyer or Purpledot’s merchandise agreement.

Keep category Commerce with explicit digital transaction types and report them separately. Add `LedgerService::recordBadgeTemplateSale()` and `recordBadgeTemplateRefund()` with typed inputs. A sale of 5,000 with provider fee F posts debit clearing 5,000−F, debit gateway expense F and credit platform template revenue 5,000, subject to approved tax allocation. It creates zero organiser payable and zero merchant commission.

A full customer refund reverses the original revenue/tax allocation against cash/refund clearing. The original gateway expense remains borne by the platform unless the provider actually returns it, which is recorded from verified evidence. Record any actual additional refund fee separately. Every journal must balance and be idempotent. Captures in review remain liabilities until explicitly resolved.

Refunds require an explicitly backed platform refund reserve. Neither unrelated tenant balances nor venue security liabilities may fund them. A funding shortage remains `needs_funding`; unknown provider outcomes remain pending reconciliation. No automatic payout is created for a platform template sale.

## 4. Implementation tasks

### Task 1 — Digital schema, licence history and provenance

**Files** Create landlord migration `add_badge_template_commerce`, models/factories for `BadgeTemplatePurchase`, `BadgeTemplateLicence`, `BadgeTemplateLicenceEvent`, `BadgeTemplateUse`, `BadgeDesignProvenance`. Modify `StoreOrder`, `StoreOrderLine`, `BadgeLibraryVersion`, `BadgeDesignRevision`, `EventBadgeTemplate`, `Event` and `ArtifactArtworkCleanup`.

**Interfaces** Purchase resolves its seller order and buyer event. Licence lookup is `BadgeTemplateLicences::forEvent(Event $event, string $familyId): ?BadgeTemplateLicence`. Provenance resolves a list of family IDs from a persisted design source, never request data.

- [x] Write `tests/Feature/Events/BadgeTemplateLicenceSchemaTest.php` assertions for cross-tenant event rejection, one active licence, preserved repurchase history, duplicate pending purchase refusal, immutable price/terms and source links, restricted financial deletion, and valid digital line ownership.
- [x] Run that file with `php artisan test --compact` and verify the new assertions fail.
- [x] Create schema through Artisan. Add explicit `badge_template` branches to order/agreement/line/reference constraints, retaining all existing purchase/reservation constraints. A digital line has quantity one, a typed purchase source, and null stock/booking source. Allow a digital-only null listing with a strict type check. Extend every affected ownership trigger and FK. Audit `CommercePaymentReferences` source checks too.
- [x] Run schema, commerce-reference and existing order-schema tests. Require zero new failures.
- [x] Commit schema/models/tests and tick this task.

### Task 2 — Offering dispatch and platform accounting

**Files** Create `app/Services/Marketplace/BadgeTemplateOffering.php`; modify `app/Contracts/CommerceOffering.php` only for required typed financial policy hooks, `CommerceOfferingRegistry`, `CommerceOrderLocks`, `StoreCommercialTerms`, `StoreBalance`, `StoreRefunds`, `app/Services/Finance/LedgerService.php`, and existing commerce reporting/payout queries that assume every commerce sale is payable.

**Interfaces** `BadgeTemplateOffering` implements every existing CommerceOffering method. Add a single financial-policy result to the offering contract for sale/refund classification and funding, with existing purchase/venue adapters reproducing their current values exactly. Digital uses platform revenue/reserve and no restock. Do not branch money behaviour from client metadata.

**Lock order** Seller tenant, buyer tenant when distinct, event (`FOR NO KEY UPDATE`), order, licence slot and use/refund claim. Always take seller before buyer for digital orders. Existing goods/venue ordering stays intact. Badge clean-output operations lock the event (`FOR NO KEY UPDATE`), event template, then sorted licence slots, and never request a tenant/order lock afterwards. Licence issuance never waits for the template. Document and test this acyclic order before coding the writers.

- [x] Add failing `BadgeTemplateAccountingTest.php` and extend `CommerceOfferingDispatchTest.php`. Assert exact 5,000 sale journal, provider fee, zero merchant payable/commission, digital exclusion from payouts, review-liability journal, balanced refund and original-account matching. Unknown types still fail closed.
- [x] Run and record failures.
- [x] Implement offering/lock/accounting hooks, scoped agreement validation and platform-reserve policy. Preserve original merchandise/venue journal allocations and permission checks.
- [x] Run affected Marketplace accounting, dispatch, reference, refund and payout tests. Require unchanged existing assertions.
- [x] Commit and tick.

### Task 3 — Authorised purchase creation and explicit checkout

**Files** Create `app/Services/Badges/BadgeTemplatePurchases.php`, `app/Http/Controllers/Tenant/BadgeTemplatePurchaseController.php`, Form Requests for purchase/start and acceptance. Modify `routes/web.php`, `StoreOrderAccess`, `StorePayments`, `app/Services/Pricing/PriceBook.php` if needed, PermissionsSeeder, RolePermissions, EventOrganiserAccess and `config/services.php` for the explicit platform seller reference.

**Interfaces** `BadgeTemplatePurchases::quote(Event $event, BadgeLibraryVersion $version, User $actor, string $verifiedPayerEmail, string $operationKey): BadgeTemplatePurchase`. Controller POST endpoints under the tenant/event badge-library routes create and start the purchase. Return only safe order/attempt status and hosted checkout navigation.

- [x] Write failing `BadgeTemplateCheckoutTest.php`. Assert price 5,000/GHS, quantity one, own-gateway buyer allowed, correct platform seller, verified payer, exact accepted terms hash, tenant/event scope, purchase permission, duplicated/changed keys reusing a pending purchase, and an already-active licence returning Purchased without HTTP payment initialisation.
- [x] Run red tests.
- [x] Add `purchase badge-templates`, default Org Superadmin/Superadmin with explicit event-safe delegation. Keep buyer finance powers separate. Freeze legal seller, buyer tenant/event/family/version, total, currency, terms/refund policy version and original account before provider HTTP. Quote expiry is ten minutes and retries retain its deadline. Block a second unresolved attempt after an unknown outcome.
- [x] Recheck authorisation before every checkout replay. Refuse new checkout for withdrawn designs or deleted events. Existing attempts are verified and reconciled after flags/roles change. Route a captured payment for an unavailable target to review/refund rather than granting a new licence.
- [x] Run checkout, role, event-scope and existing merchandise replay tests; commit and tick.

### Task 4 — Verified payment, atomic licence and durable receipt

**Files** Modify `BadgeTemplateOffering`, `StorePaymentEvidence`, `StorePaymentConfirmer`, `StorePayments`, `StoreReceipts`, settlement webhook dispatch and receipt views. Add `app/Services/Badges/BadgeTemplateLicences.php`.

**Interfaces** `BadgeTemplateLicences::grant(BadgeTemplatePurchase $purchase, StorePaymentObservation $capture): BadgeTemplateLicence`, invoked only inside the confirmed sale transaction. ReceiptContext contains seller identity, event, family/version, GH₵50 and the licence/refund terms snapshot.

- [x] Add failing `BadgeTemplatePaymentTest.php` for callback/webhook in either order, repeated observations, double payment, wrong amount/currency/reference/account/metadata, missing fees, ledger failure and a later reconciliation retry. Assert one sale, one active licence and one receipt outbox record.
- [x] Run red tests.
- [x] Route signed digital metadata through existing server verification. A browser success return grants nothing by itself. Commit ledger, attempt/order status, purchase and licence together after durable evidence capture. Extra/late/ambiguous captures stay in review. A capture after quote expiry follows the existing conservative review policy; staff may refund, and no stock/date assumption applies.
- [x] Preserve transactional email recovery when queue dispatch fails. Capture payer email immutably and provide receipt access to the buying organisation’s currently authorised event staff and original payer, without exposing seller finance data.
- [x] Run payment, signed-webhook, receipt/outbox and account-change tests; commit and tick.

### Task 5 — Adaptable preview, paid provenance and clean-output enforcement

**Files** Modify `BadgeLibraryService`, `BadgeLibraryController`, `EventBadgeController`, `BadgePdfService`, `UpdateEventBadgeTemplateRequest`, `ArtifactArtworkService`, cleanup and any clean asset response routes. Add `app/Services/Badges/BadgeTemplateAccess.php`.

**Interfaces** `BadgeTemplateAccess::assertLicensed(Event $event, array $familyIds): void`; `claimUse(Event $event, User $actor, array $familyIds, string $purpose, string $outputKey): array` returns use identities atomically after locking template/licences. Purposes are clean_preview, artwork_download, badge_pdf and production_pack.

- [x] Add failing `BadgeTemplateAccessTest.php`. Assert copied/private derivatives, back-face sources, restored revisions, direct original-asset requests, modified source IDs and background removal cannot bypass entitlement. A different event fails. Free/private designs with no paid provenance continue to work.
- [x] Run red tests.
- [x] Resolve original provenance server-side for save/apply/edit/restore. Bind the render snapshot and all source licences in one use claim before resolving clean bytes. Recheck entitlement for every subsequent request, including signed URLs/jobs. Queued outputs carry the claim/version identity and never extend access from an expired or revoked capability.
- [x] Before purchase, expose allowlisted layout metadata and flattened watermarked previews rendered with provisional attendee, category, front/back, size and composition inputs. Reject arbitrary storage paths, remote URLs or fonts. Rate-limit/render-bound the endpoint. Use stored data for final apply. Reuse PDF text fitting and actual background-fit behaviour so previews reflect the chosen size. Never send paid raw artwork, clean background data URIs or clean screenshots to an unlicensed browser.
- [x] Include all accepted family versions, dimensions, orientations and unlimited reprints under the active event licence. Withdrawal stops new sales; existing licensees may retrieve their retained purchased versions and reprint. No automatic migration to a later version.
- [x] Test A4 single/duplex output, all 59 categories/names, and in-flight old-artwork retention. Commit and tick.

### Task 6 — Refunds, support exceptions and race-safe suspension

**Files** Modify `StoreRefunds`, `BadgeTemplateOffering`, platform reserve/refund policy hooks, LedgerService; create `app/Services/Badges/BadgeTemplateRefunds.php`, platform refund controller/Form Request and licence-event records. Extend existing reconciliation jobs instead of adding a competing provider sender.

**Interfaces** `BadgeTemplateRefunds::request(BadgeTemplatePurchase $purchase, User $actor, string $operationKey, string $reason, bool $exception = false): StoreRefund`. Buyer request records intent; only platform staff with the platform-scoped `manage template refunds` permission may authorise money movement.

- [x] Add failing `BadgeTemplateRefundTest.php` for unused within seven days, exact seven-day boundary, applied-but-unexported design, used/out-of-window support referral, confirmed/failed/unknown refunds, insufficient funding, duplicate operation keys, extra-capture refunds, refund/use race and repurchase after confirmed refund.
- [x] Run red tests.
- [x] Refund approval locks the licence slot, checks use history and immediately suspends output while funding/provider status is pending. A definitive refusal restores access only after terminal provider verification and an explicit recorded transition. Unknown stays suspended. Confirmed full refund revokes access and reverses only the refunded purchase’s applied sale. Refunding an extra capture leaves the valid licence active.
- [x] Freeze refund amount/account/fee bearer and reserve funding before provider submission outside business locks. Reuse stable operation identity and reconciliation. Return full GH₵50 on an approved primary refund. Retain first-use evidence and previous files/history. Require a reason and platform-only audited exception for a used licence.
- [x] Offer replacement/free-design restoration after refund, preserving the last paid revision as locked history. No partial refunds in this slice.
- [x] Verify existing store/venue refunds, fees, unknown results, funding and payout balances; commit and tick.

### Task 7 — Buyer and platform operations UI

**Files** Modify `resources/js/console/components/BadgeDesignLibrary.jsx`, `BadgesPanel.jsx`, corresponding tests, `ConsoleApp.jsx` route wiring and existing platform console views/navigation. Add focused purchase/status/refund components under the existing badge component structure and controller routes. Rebuild `public/build`.

- [x] Write failing React/HTTP tests for Free/GH₵50/Purchased labels, permission denial, purchase confirmation, pending/unknown recovery, receipt, licence/refund status, used-output acknowledgement and staff support controls.
- [x] Run red tests.
- [x] Present the journey as preview → confirm event/licence and GH₵50 total → hosted payment → payment status → explicit Apply to this event. Never replace the current event design on payment return. Retain proposed edits across sign-in/payment return using a private server draft, bound to actor/event/version and a 24-hour expiry; validate again before applying.
- [x] Add an event purchase panel and platform digital-sales view with reconciliation/refund controls and original evidence. Suppress stock, pickup, shipping and venue wording. Adapt central buyer lists/type filters explicitly and retain original verified-payer access. No global seller list is exposed to buyers.
- [x] Warn before first clean output that it records use and affects ordinary refund eligibility. Output request supplies a stable confirmation key. Show a support action after use, and readable recovery when payment, render or receipt delivery is pending.
- [x] Browser-check desktop, 390px, light/dark, keyboard, denied platform access, actual raster previews, private purchase history and downloaded A4 PDFs. Component/HTTP tests cover stale state, duplicate clicks and the native provider callback return.
- [ ] Complete the actual hosted-provider interruption/return browser journey during the approved financial acceptance run.

### Task 8 — Concurrency, regression and financial acceptance

**Files** Add `tests/Infrastructure/Badges/PaidTemplateRaceTest.php`; extend existing badge/commerce acceptance coverage and this plan’s run record.

- [x] Write real PostgreSQL-worker races for two purchasers/same event, callback versus webhook, issue versus event deletion, refund versus use claim, two refunds, and duplicate/extra capture versus repurchase. Assert at most one entitlement and one financial posting per reference, no deadlock, balanced journals and the expected active/suspended state.
- [ ] Prove the critical guard tests fail when licence uniqueness, use/refund serialisation or original-account validation is removed, then restore and pass.
- [ ] Run the affected badge/commerce/venue tests locally once, then full CI on the final head. Run `vendor/bin/pint --dirty`, PHPStan on affected code, frontend lint/tests, build and whitespace checks. Record unrelated failures honestly and reproduce before attributing them.
- [ ] Use isolated test accounts/recipients and test-mode provider credentials for full purchase, interrupted return, repeated callbacks, unused refund, failed/unknown refund, used-refund exception and receipt recovery. Human completes hosted provider steps when required. No real attendee is involved.
- [x] Verify exact 54 × 85 mm badges, A4 seven-page front/14-page duplex outputs for the 59-person synthetic roster, long names, both flip modes, custom landscape and repeated exports without another payment. A physical actual-size paper proof remains a printer acceptance step.
- [x] Perform one author self-review of all touchpoints and one independent whole-change review. Fix findings with targeted regressions. Record actual counts and unresolved limitations, then submit for owner merge approval.

### Task 9 — Controlled release and live acceptance

**Files** Add dry-by-default `badges:paid-templates` readiness/enable command in the existing command structure, platform configuration, permission seeding and AGENTS.md invariants. Update the design spec milestone status and this plan.

- [x] Test readiness refusals for missing seller/agreement/account, missing roles, absent approved commercial terms, unsupported currency and missing digital refund reserve. Flags default off and do not depend on a tenant’s merchandise flag.
- [x] Implement read-only readiness output and an explicit buyer-tenant pilot enable/off operation. Pausing refuses new purchases, while receipt, verification, reconciliation, refund and valid licensed reprint paths remain available.
- [ ] After owner merge/deploy approval, verify migrated constraints, role grants, flags, starter designs and existing badge output. Approve actual seller setup separately and activate Purpledot only after financial/browser acceptance and approved commercial decisions.
- [ ] Ask approval for a named live recipient and amount before the live purchase/refund acceptance. Prefer the real GH₵50 licence total; use a small payment only with an explicit test price override restricted to an approved test record, never a hidden change to public pricing.
- [ ] Confirm one payment, receipt, licence, repeated export and approved refund outcome in production; record evidence without secrets/private links. Expand rollout only after owner approval. Preserve financial history on rollback and disable new purchases instead of destructive migration rollback.

## 5. Suggested delivery order

Use three reviewable checkpoints under one native implementation effort. A contains Tasks 1–2 behind the off switch. B contains Tasks 3–6 and complete financial regression coverage. C contains Task 7 plus Tasks 8–9 acceptance and release readiness. Merge/deploy decisions remain explicit. Each checkpoint is independently safe to deploy with paid checkout off.

## 6. Plan self-review

I stepped back and checked scope, interfaces, money and access boundaries.

- Added explicit digital schema/constraint work because adding a registry entry alone would fail existing source/FK checks.
- Separated platform revenue from merchant payable and required a backed platform refund reserve. Reusing Purpledot’s commission agreement would misstate who receives money.
- Included immutable multi-source provenance and all clean-output routes, including paid clean previews after licence purchase. Saving as a free organisation design cannot erase provenance.
- Made first use a conservative pre-output claim to settle the render/refund race without holding financial locks during PDF rendering. Failed-render refunds remain a support decision.
- Preserved original payer access and added buyer-event staff access separately, avoiding seller-tenant membership assumptions.
- Covered duplicate purchase, extra capture, repurchase, withdrawn designs, retained versions, event deletion, account changes, off switches and receipt outages.
- Kept prices/tax, ordinary refund window, used-refund exceptions, seller identity and platform funding as visible owner decisions. No financial policy in this document is represented as already approved beyond GH₵50/event and unlimited reprints.
- Implementation has not started. Only this plan was added during this request.

## 5. Local implementation verification and release gates

The local browser run used a separate synthetic organisation, 59 synthetic attendees, a synthetic platform seller/agreement, logged/faked mail and blocked external provider calls. A simulated capture was confirmed through the native confirmation, ledger, licence and receipt services. This establishes local application behaviour. It does not establish hosted Paystack payment/refund acceptance. The live neonatal event and its existing free organisation design were not modified.

- Seven real PostgreSQL-worker races passed with 64 assertions, including the event/revision foreign-key deadlock reproduction.
- Combined local regressions ran 435 tests. 434 passed with 1,786 assertions. The one failure caught four unsupported console Button variants. They now use the existing `default` variant, then passed the 43-test correction run (131 assertions), including the console component contract, platform refund POST and historical-purchase display regression.
- The final four focused frontend files passed 43 tests, including the existing library UI suite. After independent review, 87 affected PHP tests passed with 307 assertions, and all seven worker races passed again with two distinct staff identities in the purchase race. PHPStan on affected application files reports zero errors. Frontend lint passed. Assets are rebuilt for the final reviewed head.
- Native browser PDFs contain all 59 names and five categories. Front-only output is seven A4 pages. Both duplex flip modes produce fourteen A4 pages. Programmatic custom-landscape coverage produces eight A4 pages. A physical actual-size printer proof remains open.
- Hosted payment/refund acceptance, actual legal-seller/tax/receipt approval, reserve funding and production enablement remain explicit gates. No production setting or financial operation was performed during implementation.

### Implementation rulings

- Reuse the existing badge service namespace and typed shared commerce path. Command name is `badges:paid-templates`. This follows existing application structure. The cost of a later naming change is route/command documentation updates.
- Use a typed refund-allocation result so goods and venue arithmetic retains its existing behaviour. Digital revenue uses account 4010 and protected reserve cash 1020. Incorrect classification would affect statements, so goods/venue regression and balanced-journal tests remain release gates.
- The digital event lock uses `FOR NO KEY UPDATE`. An exclusive `FOR UPDATE` event lock deadlocked against revision foreign-key checks. The chosen mode still serialises deletion and money/use decisions while permitting those checks, as proven with real workers.
- Applying/editing remains unused. Clean-output claims count before rendering, including failed rendering, to prevent bytes escaping before refund exclusion. Support may assess an exceptional full refund if rendering failed.
- Published family price classification stays immutable. Private copied versions/revisions preserve paid provenance and in-flight asset snapshots. A genuine free replacement clears only current event provenance, preserving locked paid history. The cost is retained storage and explicit replacement.
- Same-family quote retries retain the original purchase version and ten-minute deadline. The separately saved proposal may target a newer version and requires explicit application after payment. No automatic replacement is performed.
- Original financial and audit history is retained after event archival and repurchase. Accounts with paid-design history are deactivated instead of deleted. A payment can still settle if its original requesting account was already removed.
- Verified-failed refund restoration restores access while retaining the approved refund reserve for finance resolution. This deliberately favours refund backing over spending availability. Retiring that retained obligation requires a later explicit financial-resolution policy.
- Bundled fonts match the raster preview. Standard PDF core fonts use the existing raster approximation, and the PDF remains authoritative. A closer core-font preview would be presentation polish.

### Self-Review Report

**Touchpoints updated**

- AGENTS.md records pricing, locks, provenance/use, accounting, privacy and rollout invariants.
- Permission definitions, deploy seeder and built-in role defaults include delegated event purchases and platform-only template finance. The readiness command verifies definitions without reseeding deliberate grant removals.
- Library purchase/history, My MiConvener licence navigation and the platform finance menu are linked from existing screens.
- Configuration and `.env.example` list explicit seller identity and owner commercial approval, with empty defaults. No dependency was added.
- Tests cover schema, ownership, quoted terms, capture, receipt, entitlement, output, refund, reserve, role/event scope, telemetry, frontend interaction and worker races. Existing badge, merchandise and venue coverage is retained.
- No assistant memory file was changed. The repository plan and invariants carry the handover.

**UI gap analysis**

Discoverability, empty states, permission denial, loading/recovery and original-payer receipt access are present. Desktop and 390px checks cover previews, first-use confirmation, purchase history and finance controls. Light/dark and keyboard checks cover the React flow. Native HTTP tests cover provider-return routing. The actual hosted-provider browser acceptance remains open.

**Solved during self-review**

Event deletion and revision-FK lock races, immutable provenance/output claims, same-family deadline retries, failed-initialisation retry identity, suspended repurchase refusal, stale historical status, archived receipts, retained actor deletion, finance navigation and local Telescope filtering have regression coverage. Four unsupported Button variants were corrected to the existing console variant.

**Recommended for owner decision before activation**

Confirm the actual platform seller, tax/receipt wording and backed reserve. Complete the hosted test payment/refund journey and a physical 100% printer proof before enabling a named organisation. Timing depends on owner/provider participation. The recommendation is to keep the purchase switch off until these checks pass.

**Scope notes**

MiPrinting, paid production services and paraphernalia remain later milestones. Existing free/private designs and event printing continue through their established routes. The local simulated capture is recorded separately from future provider acceptance.

### Independent review and correction pass

One fresh-context reviewer inspected `656e3ec8..78ced1be` and reported no critical findings and four important corrections. Each correction has a regression that failed before the fix. No second reviewer was dispatched.

1. Expired, definitively refused checkouts can now be replaced when every attempt is inactive, payment is still awaiting and no capture observation exists. An expired untouched quote may also be replaced by another authorised, independently verified payer. The original purchase/order/evidence stays retained. Unknown or captured payments continue to block replacement. The regression also exposed an unloaded database-default purchase state, corrected by refreshing the created purchase before returning it.
2. Paid and free previews now share composition, attendee and category controls. Paid responses include only safe selected dimensions alongside the flattened raster. Landscape dimensions, category/name samples, private draft inputs and explicit apply are tested together. Same-family composition changes preserve reviewed category labels.
3. The purchase confirmation shows frozen seller, support and receipt terms before the acceptance checkbox.
4. Platform finance verification, refund and restoration controls use the existing high-contrast neutral treatment. Browser measurements changed from text22/143/127 on241/250/255 to text24/28/50 on239/242/245, exceeding normal-text contrast requirements.

Final local correction evidence is 87 PHP tests/307 assertions, 43 frontend tests, seven real-worker races/64 assertions, clean affected PHPStan/lint/Pint and a rebuilt asset bundle. Browser checks confirm paid 85×54 landscape selection, attendee/category changes, seller/support/receipt disclosure and a disabled payment button before acceptance. External hosted payment remains unperformed.

The reviewer set aside the already explicit provider/legal/funding/activation and physical printer gates, the documented PDF core-font approximation, recoverable missing-back selection and retained failed-refund reserve. These remain documented release gates or accepted conservative behaviour. They are not reported as completed acceptance.

## Hosted-provider acceptance on 12 November 2026

The owner completed one GH₵50 hosted Paystack test-mode card purchase for the synthetic local event. The database was `miconvener_acceptance_codex_20261102`, with test credentials, a synthetic seller and logged mail. Purchase `01a1cf73-0ace-72c5-955a-b2e56270efce` received one verified 5,000-pesewa GHS capture, a 98-pesewa gateway fee, one active unused event licence and one durable receipt. The receipt delivery job completed through the log mailer.

The browser returned to an organisation-hosted private receipt and received 404. `GuardAttendeePortalHost` correctly requires the platform hostname for private `/my/*` pages. Checkout initialization, callback redirection and digital receipt mail now build their URLs from the configured platform URL and named route path. Three regressions failed before the correction. The affected checkout, payments, refunds and store-receipt suites then passed 67 tests with 232 assertions. A replay of the existing callback reached the receipt in the browser.

One full unused-licence refund was submitted through the native refund service against the original test account and capture. Refund `01a1cf79-b215-731e-8c11-7ed4e5a332f3` remains provider-pending. The licence is suspended. Completed provider refund, refund ledger reversal and final licence revocation remain unverified until Paystack reports processing complete.

### Acceptance correction self-review

All three URL-producing touchpoints were checked. Purchase authorization, original-payer receipt ownership, receipt history, money locking and permission definitions continue through their existing services. Configuration, dependencies, seeders, navigation, frontend assets and environment variables require no additions for this URL correction. The browser receipt retains its existing responsive layout, loading/error handling and keyboard controls. The new tests cover organisation-host initialization, callback routing and mail rendering. Paid activation, actual seller tax details, real reserve backing and the physical print proof remain outstanding.

A few UI/refactoring pieces were implemented alongside their tests rather than strictly test-first. The reviewed defects, financial guards and locking regressions have recorded failing-then-passing reproductions.

### Final CI fixture correction

CI on `2b91beec` passed code quality and 4,942 PHP tests, with one badge-layout failure. The paper-size test had inherited a randomly generated event title. A deterministic title, `Reprehenderit voluptates consequatur voluptatem.`, reproduced the same text-fit error after the first three print modes passed and the compact landscape mode began. The positive printing fixture now uses `Preterm Newborns`. A separate oversized-title test checks the `layout.event_name` refusal and retained paid use claim. All 22 tests in that file passed with 79 assertions. No application text-fit rule was changed. The final CI result is recorded on PR #100.
