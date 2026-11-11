# Reusable badge library implementation plan

> Use superpowers:executing-plans inline. Implementation authorised on 11 November 2026. Review before merge.

**Goal** Give organisers reusable, versioned designs while preserving their event badge designer and actual-size exports.

**Architecture** Separate named library designs and immutable versions from editable event copies. Snapshots own their artwork and retain category names instead of foreign ticket IDs. Record recoverable event revisions before replacement. Use existing artwork, font, permission and PDF services.

**Tech stack** Laravel, PostgreSQL, React, existing DomPDF and Vitest/Pest.

**Spec** `docs/superpowers/specs/2026-11-11-design-library-miprinting-design.md`, M1 only.

## Global constraints

- No payments, attendee messages or production changes in this stage.
- Preserve 40–210 mm dimensions and A4/Letter fit validation.
- Private event saves require event design access. Organisation sharing requires `manage badge-library`; platform publishing requires global superadmin authority and asset-rights confirmation.
- Paid catalogue entries remain preview-only. Original paid artwork and application are refused server-side until the later licensing stage.
- Event copies survive source deletion/withdrawal. Published versions are immutable. Legacy designs remain front-only.

## Review Focus

- Event-scoped users must never discover another private event's names, artwork or templates.
- Replaced/deleted source artwork must not break library copies or recoverable revisions.
- Destination category labels must reference destination ticket IDs only.
- Partial final duplex sheets must mirror positions across the physical paper, with each attendee on both faces.
- A stale library apply/restore must refuse to overwrite a newer event edit.

### Task 1 — Versioned library and safe application

Files include new library/revision models, landlord migration, `BadgeLibraryService`, tenant controller/requests, event access allowlist and permission defaults.

- [x] Write and run failing feature tests for private/shared/platform visibility, permissions, immutable versions, withdrawal, asset survival, category remapping, stale updates and paid refusal.
- [x] Implement snapshot copying, versioned save, safe apply/restore and preview endpoints using existing validators and font ownership.
- [x] Run focused library and existing badge feature tests, fix regressions and commit.

### Task 2 — Adaptable front/back compositions and printing

Files include event template/request, designer, preview, sheet controls, PDF service and Blade view.

- [x] Write and run failing tests for back-face persistence, independent artwork, paper fit and long-/short-edge duplex placement on partial sheets.
- [x] Implement optional back with the same field controls, explicit duplex mode and physical-size sheet placement. Preserve legacy front-only output.
- [x] Add curated generic portrait/landscape designs with replaceable dynamic fields, contain fallback and safe custom sizes.
- [x] Run PDF and frontend regressions and commit.

### Task 3 — Library UI and acceptance

Files include `BadgeDesignLibrary.jsx`, `BadgesPanel.jsx`, component tests and built assets.

- [x] Write and run failing UI tests for browse/filter, save/share, preview/replace confirmation, category mapping, revisions, error recovery and paid preview-only state.
- [x] Implement accessible library modal and event-copy previews, dimensions, category mapping and restore workflow.
- [x] Test real app desktop, 390px, dark mode, keyboard, neonatal portrait/landscape and A4 PDF dimensions/name completeness.
- [x] Run Pint, frontend build, focused static checks and affected tests. Conduct one whole-branch independent review and fix findings with regression tests.
- [x] Commit rebuilt assets and report limitations and review findings before merge.

## Self-review and acceptance record

### Touchpoints updated

- Library routes are discoverable from the event Badges page. Private/shared/platform collections, save/new version, withdrawal, previews, category mapping and event revision recovery have UI actions.
- `manage badge-library` is defined in PermissionsSeeder, RolePermissions and the tenant-safe list. Existing deploy seeding delivers it to built-in administrators when newly created. Event Organisers retain event-private saving and shared-template use.
- The permission seeder safely ensures the two generic starter designs exist, without replacing published versions or undoing withdrawal. Catalogue artwork is bundled on a read-only filesystem; event and saved library copies own independent private artwork.
- AGENTS.md records scope, immutable snapshots, font ownership, paid-preview restrictions and duplex placement. There are no new environment variables, dependencies or financial changes. Task progress lives in the task ledger; separate assistant memory files were unnecessary.

### UI review

- Browser checks used only the local acceptance organisation and a local-only test account. Save organisation design, preview/replace, long-name selection, back-face creation/upload, and 59-badge downloads were exercised through the real UI.
- Desktop and 390px, light and dark were checked. No horizontal document/dialog overflow. Preview selection moves keyboard focus to its comparison heading; shared console modal supplies focus trapping and Escape dismissal. Loading, validation errors and retry states are present.
- Blank collections explain how to save a design. Paid previews have no apply action. Platform publication explicitly asks for asset rights. Library scope choices are returned by the current server-side permissions.
- The neonatal design was saved only to the local organisation library. Its customer artwork was not published to the shared catalogue.

### Solved during review

- Independent review found retained old artwork/back faces on curated replacement. Complete replacement defaults now clear omitted optional fields, with regressions for both starter families.
- Independent review found tenant-owned fonts in platform publication and missing back-face font validation during application. Publication now requires bundled fonts on both faces, and application validates both faces. Three regression cases cover these refusals.
- Author review added back-upload/face consistency and cleanup for removed back artwork. Preview thumbnails use actual design artwork, and comparison sizes are constrained for readable desktop/mobile review.

### Print acceptance

- Browser-generated original front-only output is seven A4 pages for 59 people. The two-sided output is fourteen A4 pages. Each expected name appears once per face, including line-wrapped hyphenated names. Category totals match 29 Doctor, 25 Nurse, one Physician Assistant, three Faculty and one Host per face.
- Page dimensions are 595.28 × 841.89 points. Renderer regressions check exact 54 × 85 mm badge dimensions, both duplex flip modes, partial-sheet coordinates and actual PDF page counts.
- Digital output has been checked. Paper feed, printer scaling and physical duplex registration require a printer test at 100% / actual size.

### Rulings and later stages

- Implementation proceeds under the owner's explicit M1 authorisation without an additional plan-approval stop.
- Platform publication currently requires bundled fonts, while private/organisation designs retain their own uploaded/imported fonts. Cross-organisation font licensing/copying would require separate implementation.
- GH₵50 purchasing and MiPrinting/supplies ordering remain M2–M4. M1 provides paid catalogue previews with server-side clean-output refusal.

### Verification results

- Final affected PHP run passed 92 tests / 379 assertions. The subsequently expanded private-event deletion regression passed with eight assertions.
- Final affected frontend run passed 49 tests. The full frontend run reached 656/657 with one unrelated CertificatesPanel timing timeout under heavy host load; its file passed on the isolated retry (33 tests together with the library file). Earlier unrelated parallel timeouts also passed in their isolated run. CI remains the full-suite gate.
- PHPStan reports zero errors on changed controllers, requests, services, models and the catalogue seeder. Pint, ESLint, the production build and whitespace checks pass.
- The initial baseline and first new-test run briefly overlapped on the dedicated test database and were invalidated. All subsequent PHP tests ran sequentially. A later PostgreSQL connection timeout under host load passed on rerun, followed by the clean 92-test run.
- Source event deletion now cleans private event library versions and their artwork, while organisation-shared copies survive. Post-commit cleanup failures are reported without turning a committed application into a failure or deleting its newly stored copies.
- A generic design preserves the destination event's reviewed category labels when the source has no matching labels. An explicit source match or organiser edit remains visible before replacement.
- Valid numeric form strings are normalised before optimistic-version comparison. The regression failed with a server error first, then passed with save/apply/restore/replacement checks (five tests, 20 assertions).
- Work is submitted for review with no merge or production deployment in this task.
