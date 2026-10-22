# Unified catalog S1a implementation plan

> **For agentic workers:** Use superpowers:executing-plans for native task-by-task execution after owner approval. Track steps with checkboxes; one fresh whole-change review at the end. Do not begin while implementation scope is unconfirmed.

**Goal:** extend the existing Shop/StoreListing catalog with merchandise publishing, variants, stock administration and protected storefront entry points, preserving all existing venue features.

**Architecture:** StoreListing stays canonical; merchandise details and inventory are typed child records. Anonymous listing reads exclude private/archived/incomplete merchandise by default, while tenant-owned administration and existing member identity grant narrowly scoped access. No orders, reservations or payment activation in this slice.

**Tech stack:** current Laravel 13, PHP, PostgreSQL, Inertia/React and Tailwind 4; Pest and Vitest. No new dependencies.

**Spec:** [unified commerce design](../specs/2026-10-22-unified-commerce-design.md); [roadmap](../marketplace-roadmap.md).

## Global constraints

- Native execution; fail-first tests per task; tick steps in the implementation commit; update roadmap state only when it changes.
- Preserve existing venue data/URLs/calendar, private staff rooms, enquiries/quotes/deposits and linked operations. No production activation or settlement change.
- `commerce_catalog` defaults off; `commerce_checkout` remains off and is not implemented here. Catalog and `manage store catalog` do not grant venue/finance/refund rights.
- Prices are integer pesewas, GHS initially. This slice stores catalog prices but does not compute commissions or take payments.
- All identity/member access uses current services; no phone matching, follower-as-member shortcut or separate authentication system.
- No second Product catalog, generic new framework, dependency install, destructive conversion or speculative rental/service engine.
- Use Artisan generation, current Form Request conventions, Boost docs, `pint --dirty`, affected tests and tracked rebuilt assets for JS/CSS changes.

## Review Focus

1. Private merchandise leaking through existing generic queries, storefront serialization, counts, media or MCP. Task 2 owns the cross-surface regression set.
2. A seller/member from tenant A resolving tenant B's listing/variant via crafted identifiers. Tasks 2/3/4 own denied reads and mutations.
3. Existing venue CRUD receiving a merchandise ID or merchandise addition changing venue availability. Tasks 2/3 own compatibility regressions.
4. Stock edits racing, becoming negative or losing audit history; duplicate SKU/options. Task 1 owns database/service invariants.
5. Disabled feature/member proof expiry, sold-out items and long option labels on mobile/dark/enlarged text. Tasks 2/4/5 own access and browser acceptance.

## File responsibilities and interfaces

Existing files to inspect/edit as needed: `app/Models/{Shop,StoreListing}.php`, `app/Http/Controllers/Marketplace/MarketplaceStorefrontController.php`, tenant venue listing controller/request, `routes/{web,subdomain}.php`, `database/seeders/PermissionsSeeder.php`, `app/Libraries/RolePermissions.php`, current tenant feature registry/setup and `resources/js/Layouts/consoleNav.js`. Follow existing feature propagation rather than introducing a second registry.

New classes in existing namespaces: `app/Models/{StoreMerchandise,StoreVariant,StoreStockMovement}.php`; `app/Scopes/MerchandiseVisibilityScope.php`; `app/Services/Marketplace/{MerchandiseCatalog,MerchandiseInventory,MerchandiseAccess,MerchandisePayload,MerchandiseMediaStorage,CommerceCatalogRollout}.php`; tenant/public merchandise controllers, the merchandise-media controller and tenant Form Requests. Also modify `config/filesystems.php`, `app/Models/StoreListingMedia.php` and `app/Services/Tenancy/StorageMeter.php` for private photo storage/metadata/quota accounting. Generate factories and migrations under current landlord patterns. New pages stay under existing `Tenant/Store/` and `Public/Marketplace/Items/` page namespaces; shared helpers stay under existing component directories. Final page split follows existing component sizes.

Proposed service contract (resolve request context/authorisation before privileged reads):
- `MerchandiseInventory::adjust(StoreVariant $variant, int $delta, string $reason, string $idempotencyKey, User $actor): StoreStockMovement` — locks current variant; rejects underflow; appends movement and updates stock atomically. Unique (variant_id, idempotency_key) returns the original movement on matching retry and rejects a mismatched retry. Movements cannot be edited/deleted through normal model/HTTP paths; reversal is a new movement.
- `MerchandiseAccess::canRead(StoreListing $listing, Session $session): bool` — uses live member access for the selling tenant; anonymous public reads still require enabled catalog/publication.
- `MerchandisePayload::forViewer(StoreListing $listing): array` — explicit approved fields after access is established; no raw Shop/Tenant serialization.
- `MerchandiseCatalog::save(Tenant $tenant, User $actor, ?StoreListing $listing, array $input): StoreListing`, `publish(Tenant $tenant, User $actor, StoreListing $listing): StoreListing` and `archive(Tenant $tenant, User $actor, StoreListing $listing): StoreListing` enforce feature/permission/ownership and typed validation. Input shape is spec §3 and Task 3's request contract. Creation stock flows through the inventory service with per-variant adjustment keys.
- `CommerceCatalogRollout::baseEnabled(Tenant $tenant): bool` requires active tenant and TenantFeature commerce_catalog=true; `enabled(Tenant $tenant): bool` additionally requires an active Shop for public reads. Administration/onboarding checks baseEnabled plus manage store catalog; a missing Shop can be created in the first authorised POST transaction, while an inactive existing Shop is rejected and never auto-reactivated. GET shows an onboarding/empty state without creating records. No rollout method changes flags or grants entitlements. Default public-scope queries enforce equivalent conditions in SQL.
- `MerchandiseMediaStorage::put(Tenant $tenant, StoreListing $listing, UploadedFile $file): StoreListingMedia` and `stream(StoreListingMedia $media): StreamedResponse` use the spec's private storage/quota rules. Stream callers authorise current access first; the media service validates tenant-prefixed keys and uses no-store/nosniff headers. Existing venue URL/storage behavior is retained.

Proposed route contract: tenant GET/POST `/store/catalog`, GET/PUT `/store/catalog/{listing}`, POST `/store/catalog/{listing}/publish`, POST `/store/catalog/{listing}/archive`, POST `/store/catalog/{listing}/variants/{variant}/adjustments`, POST `/store/catalog/{listing}/photos`; public GET `/marketplace/items/{slug}` and GET `/marketplace/items/{slug}/photos/{media}`. Names use `tenant.store.catalog.*` and `marketplace.items.*`. Each identifier is re-resolved under the seller/type/access context. No checkout route in S1a.

These new names belong to this proposed plan, not existing APIs. `Session` is `Illuminate\Contracts\Session\Session`, matching existing `MemberAccess::role(Session $session, Member $member)`. Resolve the live verified email with `PlatformAttendeeVerification::verifiedEmail($session)`, normalize using existing member identity rules and restrict active members to the selling tenant; require self access for S1. Offline `AttendeeIdentityContext` cache proofs do not authorise purchases. Representatives and phone-only purchase eligibility are deferred design decisions, not silently inherited from document-reading access.

## Task 1: typed catalog data and audited stock

**Files:** new three models, factories, landlord migrations and `MerchandiseInventory`; tests `tests/Feature/Marketplace/MerchandiseCatalogSchemaTest.php` and `MerchandiseInventoryTest.php`.

- [ ] Write failures proving listing ownership, one child per merchandise listing, normalized SKU unique per seller, option combination unique under case/whitespace/key-order normalization, nonnegative integer stock/price, and archival retaining variant IDs/history. A venue listing cannot acquire merchandise details by an arbitrary kind flip.
- [ ] Run `php artisan test --compact tests/Feature/Marketplace/MerchandiseCatalogSchemaTest.php tests/Feature/Marketplace/MerchandiseInventoryTest.php`; confirm meaningful failures for missing schema/service.
- [ ] Generate/implement schema and typed models/factories; implement `adjust` with transaction/row lock, required reason/actor and immutable movement, using the spec’s normalized option-key/SKU rules. New stock initializes through an auditable movement. Test identical retry returns the original movement, altered payload with same key fails, movement update/delete is denied, and a general variant edit cannot change on_hand. Test concurrent corrections with separate connections; no lost update/negative balance.
- [ ] Run affected tests to green; inspect migration reversibility without deleting existing venue records. Do not migrate production.
- [ ] Commit task plus ticked plan steps.

## Task 2: default privacy and legacy venue compatibility

**Files:** visibility scope/access/payload services, StoreListing/Shop query helpers, venue controller/request guards, tenant EventController, InternalBookingController, marketplace quote controller/service and legacy media actions; tests `MerchandiseVisibilityTest.php`, existing `tests/Feature/Venue/StaffOnlyRoomsAreHiddenTest.php` and marketplace discovery/MCP tests.

- [ ] Write failures: private, archived, draft/suspended/taken-down or incomplete merchandise, or merchandise from inactive Shops/tenants/catalog-off sellers, never appears through anonymous Shop listings, storefront, venue search, item/venue detail, photos/counts or MCP, including search listing_kind=all and category filter combinations. Tenant owner access and exact active verified member access are bounded; follower/phone-only/expired/wrong-tenant proofs fail.
- [ ] Write venue regressions: old venue URLs/data remain unchanged; staff-only rooms remain hidden; passing a merchandise ID to legacy venue edit/update/delete, internal room selection/create, event venue attachment, supplier/venue RFQ/quote checkout or media-upload/update cannot mutate, reserve, attach, charge for or publicly expose it. Exclude merchandise from the legacy venue administration list. Existing rental/service records are not silently deleted or converted.
- [ ] Run the new visibility test and relevant existing discovery/staff-room tests to confirm failures isolate the new boundary.
- [ ] Implement default merchandise read scope and narrowly authorised owner/member query helpers. Retain StaffOnlyScope. Add explicit payloads; private merchandise image storage and an authorised no-store media route (including owner draft preview), with type-aware media URL generation leaving venue media unchanged. Test copied endpoint URLs after public→members/archive/catalog-off transitions; no public disk/remote URL may escape. Add the legacy type guards above and explicit merchandise exclusions in existing venue search variants. Avoid unbounded `withoutGlobalScopes()`.
- [ ] Run the affected tests to green, including inactive/exited status, member email changes and email proof expiry between reads (phone-credential revocation alone must not revoke current matching email ownership) and archive/unpublish while a page is open.
- [ ] Commit task plus ticked plan steps.

## Task 3: enabled tenant administration

**Files:** tenant merchandise controller, Form Requests, catalog service, routes, permission/role/feature setup, navigation; tests `TenantMerchandiseCatalogTest.php`, permission invariant tests and `resources/js/test/console/consoleNav.test.js`.

- [ ] Write failures for permission/feature off, wrong-tenant mutation, verified existing Shop reuse, safe missing-Shop creation via tenant-owned onboarding, duplicate SKU/options, malformed/decimal/negative amounts, invalid/remote/SVG/oversize uploads, more than eight photos, tenant quota exhaustion, failed metadata persistence cleaning up a private object, incomplete publication, platform takedown and stock-history access.
- [ ] Run `php artisan test --compact tests/Feature/Marketplace/TenantMerchandiseCatalogTest.php`; confirm failures before new endpoints.
- [ ] Implement catalog request shape: title/slug/description; merchandise visibility; GHS; collection instructions; variants with SKU/options/price_pesewas/active and initial stock; controlled photo uploads. Ownership/feature/permission enforced on every action. Use separate inventory-adjust action with UUID idempotency_key rather than silently overwriting stock on general edits. Store photos on commerce-media-local locally or existing private s3 in production, add private metadata without rewriting venue rows and extend StorageMeter under the existing tenant lock. Follow CommunityContentStorage failure/stream safety patterns; do not call it with a fake library version or change its content policy.
- [ ] Add `manage store catalog` to existing permission propagation/role definitions and missing-only rollout conventions. Do not auto-grant through `manage venue`, alter paid plans or activate a production tenant. Add discoverable tenant Store navigation only for permitted enabled users.
- [ ] Run new tests plus permission/navigation invariants to green. Verify re-seeding preserves deliberate permission removals.
- [ ] Commit task plus ticked plan steps.

## Task 4: unified storefront and merchandise screens

**Files:** existing storefront controller/page, new merchandise detail controller/page, seller merchandise index/form/inventory views, native organisation/Community Store entry points; tests `MerchandiseStorefrontTest.php` and `resources/js/test/marketplace/MerchandiseCatalog.test.jsx`.

- [ ] Write failures for correct typed card links, preserved venue links, guarded member details, public price/options payload, sold-out/default-variant states, stock correction validation/error feedback and no S1a checkout action. Private cards must not be named in anonymous payloads.
- [ ] Run the new PHP and JS tests to confirm meaningful failures.
- [ ] Implement the screens using existing React/console tokens and square visual surfaces. Filter Store navigation by feature/access; public Store works without dues/Community. Keep venue-specific capacities/date controls off purchase forms.
- [ ] Item selection may display catalog availability only; show “Purchasing is not enabled yet” in S1a. No fake success/order, provider request or reservation from a preview button.
- [ ] Run affected tests/lint; rebuild and include `public/build` in the same implementation commit. Test keyboard option selection, form focus/errors, safe media and feature revocation.
- [ ] Commit task plus ticked plan steps.

## Task 5: preservation gate and review

- [ ] Self-review the entire slice against spec §7 and the five Review Focus cases. Fix concrete issues before reporting completion.
- [ ] Run the new merchandise suite, affected marketplace/discovery/venue/staff-room/member access tests and affected JS tests. Run Pint/lint/static analysis; run one complete CI gate on the final source rather than duplicating full suites repeatedly.
- [ ] On isolated local QA, test tenant creation/edit/publication and a no-dues member; all local testing is authorised. Verify 320/390/768/1440px, dark, long names, sold-out, missing photo, revoked access and 200% text. Confirm existing venue search/detail, calendars, private rooms, booking continuation and linked operations still work. No live payment or outbound message.
- [ ] One fresh scoped whole-change review, address material findings, record self-review with touchpoints and UI gaps. Commit final asset/plan/roadmap state together.
- [ ] Prepare PR for owner review. Do not merge, deploy, enable production catalog/checkout or begin S1b solely because S1a tests pass.

## Plan self-review and open gates

Coverage: typed data/stock in Task 1; privacy and venue preservation in Task 2; permissions/onboarding in Task 3; customer/staff UI in Task 4; end-to-end compatibility in Task 5. S1b commercial decisions deliberately do not block catalog design, but do block payment work. New service signatures are proposed; session/member integration is explicitly bound to existing native services, with self-only eligibility for S1.

Implementation permission is currently being clarified because the owner's preceding instruction explicitly required review before implementation. The design/preview/plan package can be prepared while that question is pending. This plan does not waive that gate.

**Builder-check amendments:** specified protected media/stale URL behavior; all-kind/category search isolation; legacy room/event/RFQ/media type guards; precise active-email membership revocation; inventory idempotency/immutability/normalization; and feature-off/default-scope behavior. These resolve concrete implementation ambiguities without authorising implementation.

**Preparation evidence (2026-10-22):** synthetic preview browser checks passed at 320/390/768/1440px; purchase filtering and sold-out options, rental date fields, catalog view and dark mode checked. These are prototype checks, not application, checkout or production acceptance. Builder-check findings were incorporated into the design/plan; no source implementation or new payment tests have run.
