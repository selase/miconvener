# Certificate and Badge Design Completion Implementation Plan

> **Execution status:** Planning only. The user explicitly instructed: do not execute yet.
> **For agentic workers:** When execution is authorized, use `superpowers:executing-plans` task by task. Do not deploy, push, or alter existing worktrees as part of planning.

**Goal:** Finish tenant artwork customization so the editor, saved design and generated PDFs agree, event access is enforced, and failures are understandable.

**Architecture:** Keep the existing React console, normalized layout JSON, Laravel Form Requests, Storage artwork service and Dompdf renderers. Establish canonical defaults for future certificate designs and consume equivalent normalized settings in both previews and PDFs. Preserve issued design snapshots without backfilling or rewriting them.

**Tech stack:** Laravel 13, PHP 8.5, React 19, Tailwind 4, Pest, Vitest/Testing Library, existing Dompdf and QR dependencies.

**Spec:** `docs/superpowers/specs/2026-09-30-badge-certificate-design-customization.md`, supplemented by the October 5 audit in this conversation. The original spec's implementation-status sentence is historical, not the current state.

## Global constraints

- Accept PNG/JPEG artwork only; no HTML/CSS, SVG/PDF or font uploads.
- Add no dependencies without approval. Prefer existing console primitives and existing file organization.
- Coordinates remain normalized and validated by `ArtifactLayoutValidator`.
- Existing issued certificates retain their original design version and artwork.
- Certificate identity and verification code cannot be hidden in custom mode.
- Certificate paper stays A4 landscape; badge sheets support A4/Letter.
- Use an isolated checkout and test database when execution begins; preserve current unrelated generated-asset changes and other agents' work.
- Read applicable AGENTS.md, domain skills and version-specific Boost documentation before implementation. If Boost is unavailable, record the limitation and use official documentation.
- This plan authorizes no implementation, migration, commit, push or deployment.

## Review focus

1. Switching a legacy template with null layout to custom artwork must retain recipient/title/body overlays; historical snapshots remain untouched.
2. A certificate from another event in the same tenant must return 404 even when the URL's event is accessible to the user.
3. A 101+ attendee event must have an actionable batch workflow with no duplicate print records from retries.
4. Failed uploads/saves/downloads must display useful errors without losing the draft or silently changing issued artwork.
5. Long Unicode names, transparent signatures, tier colors and multi-page sheets must remain readable in actual rendered PDFs.

## Task 1: Close certificate event boundaries

**Modify:** `app/Http/Controllers/Tenant/EventCertificateController.php`.
**Tests:** `tests/Feature/Events/EventMultiRoleCertificatesTest.php`; reuse event-staff test fixtures if available.

- [ ] Add HTTP regression cases for download and revoke with event A in the URL and event B's certificate. Expect 404, no deletion and no download-count increment.
- [ ] Cover foreign tenant, anonymous user, denied permission, and assigned Event Staff requesting an unassigned event's certificate through an assigned event URL.
- [ ] Run the affected tests and confirm the new boundary cases fail before changing production code.
- [ ] Apply the same relationship guard used for templates to download and destroy:

```php
abort_unless($certificate->event_id === $event->id, 404);
```

- [ ] Audit supplied `template_id` during issuance: reject a foreign-event ID explicitly instead of silently falling back to another template.
- [ ] Rerun the affected tests, review all certificate endpoints, and commit this boundary fix independently when execution is authorized.

## Task 2: Canonical certificate layouts and usable controls

**Modify:** `ArtifactLayoutValidator`, `CertificatePdfService`, `EventCertificateController`, certificate Store/Update Form Requests, `CertificateDesignEditor.jsx`, `CertificatesPanel.jsx`.
**Tests:** `EventCertificateCustomDesignTest.php`, `EventCertificateDesignVersionTest.php`; create `resources/js/test/console/CertificateDesignEditor.test.jsx`.

- [ ] Add failing tests for a newly customized legacy template: title, recipient name, body, issuer and verification code must be present in output with no caller-supplied layout.
- [ ] Add interaction tests that move a field, change width/height, font, size, weight, alignment, color and visibility, then assert the submitted layout contains those changes.
- [ ] Provide a server-owned default certificate layout through an explicit defaults method on the existing layout validator; return it with the certificate index payload. Initialize editable drafts from defaults merged with stored overrides.
- [ ] Keep deliberately partial historical snapshots partial. Resolve defaults for new/edited templates and preview drafts, then persist the resolved layout; do not change snapshot rendering semantics globally.
- [ ] Always retain recipient identity and verification code in custom mode; allow optional title/body/issuer fields to be hidden when artwork already contains static wording.
- [ ] Add labeled controls for normalized positions, dimensions, allowed fonts/styles and optional field visibility. Reuse console Input/Select/Checkbox components.
- [ ] Make reset restore canonical defaults rather than the layout captured when the modal opened. Restore a visible default CPD-hours input.
- [ ] Reject custom mode without usable background artwork before issuing a certificate; require new upload or a retained current asset. Return a field-level error, not a later broken download.
- [ ] Test remove/replace behavior, invalid bounds, missing artwork and snapshot immutability. Run focused Pest and Vitest tests and commit.

## Task 3: Make previews trustworthy

**Modify:** `ArtifactLayoutPreview.jsx`, `CertificateDesignEditor.jsx`, `CertificatePdfService`, `EventCertificateController`, `routes/subdomain.php`, `BadgeLayoutPreview.jsx`, `BadgesPanel.jsx`.
**Create with Artisan during execution:** a certificate draft-preview Form Request if existing requests cannot safely express this endpoint.
**Tests:** custom certificate HTTP tests plus editor/panel Vitest tests.

- [ ] Add failing tests proving Test PDF uses unsaved wording/layout/artwork, creates no certificate/version, does not update the template and leaves no uploaded temporary files.
- [ ] Keep the saved GET preview for compatibility. Add an authorized POST draft-preview route accepting the same allowlisted design fields and multipart images.
- [ ] Render an in-memory template from validated draft fields and event-owned stored assets. Encode uploaded preview images without persisting them; never accept disk/path values from the browser.
- [ ] Refactor existing artwork encoding minimally so saved uploads and temporary preview uploads use identical MIME/dimension/decode checks.
- [ ] Display PDF failures inside the modal. Open the returned PDF blob and release object URLs when safe; show a pending state while generating.
- [ ] Extend browser certificate preview to show issuer, signature, CPD and QR placement, replace all supported placeholders, honor design mode and declared fonts, and use the canonical layout. Mark sample QR/data as illustrative.
- [ ] Match background sizing policy in preview and PDF: preserve the approved full-page artwork placement rather than mixing browser cover-cropping with PDF stretching.
- [ ] Replace hardcoded badge cards with the saved template preview populated from actual attendee fields and tier styles. Preserve advisory-preview wording.
- [ ] Test stored artwork, newly uploaded transparent signature, mode switches, removed artwork, hidden fields and object-URL cleanup. Commit after focused checks pass.

## Task 4: Complete badge editing and batching

**Modify:** `BadgeDesignEditor.jsx`, `BadgeLayoutPreview.jsx`, `BadgeSheetSettings.jsx`, `BadgesPanel.jsx`, `UpdateEventBadgeTemplateRequest`, `BadgePdfService`, `BadgeTemplateService` as needed.
**Tests:** `EventBadgeDesignTest.php`, `EventBadgePdfTest.php`; create `resources/js/test/console/BadgesPanel.test.jsx`.

- [ ] Add failing tests for events with 100, 101 and 250 matching attendees, single-person reprints, and filtered batches. No request may contain more than 100 IDs.
- [ ] Present explicit batches of up to 100 matching badges with a batch selector and count. Download only the selected batch; reset to the first batch when search/results change. Retain search-based single reprints.
- [ ] Disable duplicate downloads while generation is pending; after success refresh history, preserving filter/batch state. On failure show retry without recording client-side print success.
- [ ] Add badge field width/height and font/alignment/color controls alongside existing x/y/visibility controls. Recompute orientation when dimensions change.
- [ ] Validate sheet fit when saving, returning a sheet-settings error before the user reaches printing.
- [ ] Implement the original spec's tenant-logo overlay using trusted tenant Storage data, or document a scope decision before execution if the model has no supported logo source. Do not leave an accepted element that always renders empty.
- [ ] Ensure tier styles, optional fields and QR placement appear in both preview and PDF. Preserve print history only after successful server PDF generation.
- [ ] Cover one/partial/full/multiple sheets and A4/Letter. Run focused tests and commit.

## Task 5: Permissions, errors and certificate pagination

**Modify:** `app/Services/Events/EventSections.php`, `CertificatesPanel.jsx`, `BadgesPanel.jsx`, both editors; relevant request/controller responses if needed.
**Tests:** panel Vitest tests and existing section/permission feature tests.

- [ ] Add failing interaction tests for 403, 422, 500, non-JSON response and network rejection on load/save/preview/download. Verify drafts remain intact and errors are visible in the active modal.
- [ ] Use `auth.can` conventions already established in sibling panels to gate customize, issue, revoke and badge-download actions. Retain server authorization on every endpoint.
- [ ] Align Badges navigation with `read badge-template`; test users who can read events but cannot read badge templates.
- [ ] Consume certificate pagination metadata, add Previous/Next and count controls, and request the selected page. Reset page to one when role/search changes; move back if revoking the last item empties a later page.
- [ ] Show field-level 422 errors and a general fallback for unexpected responses. Add retry to load failures; clear stale errors after a successful request.
- [ ] Put certificate table in a horizontal-scroll container and make modal controls usable at narrow widths. Give icon-only actions accessible names, announce errors and preserve keyboard focus behavior.
- [ ] Run permission, panel interaction and console-component-contract tests; commit.

## Task 6: Finish original-spec lifecycle and rendering checks

**Inspect/modify as required:** `ArtifactArtworkService`, certificate/badge models, `CertificateDesignVersionService`, event deletion lifecycle, both PDF services and views.
**Tests:** `ArtifactArtworkServiceTest.php`, certificate design-version tests, badge PDF tests.

- [ ] Verify model-level cleanup on template/event deletion, including bulk deletion paths. Add missing regressions before fixes. Delete only unreferenced assets; never remove a file used by a surviving issued snapshot.
- [ ] Test local and S3-compatible reads with mocked Storage and unavailable snapshot assets. Return controlled errors for historical artwork loss; preview fallback must explicitly warn when artwork cannot be displayed.
- [ ] Test Unicode and names at/beyond supported bounds. Validate all editable text fields for predictable overflow, not just recipient-name character count.
- [ ] Render representative PDFs to images: default certificate, custom background, transparent/no signature, long Unicode name, tier badge and multi-page sheet. Check physical dimensions, clipping, QR readability and actual page count.
- [ ] Confirm crop-mark behavior against the original spec; current dashed badge outlines are not conventional crop marks. Implement printable corner marks if crop marks remain enabled.
- [ ] Run browser smoke tests at desktop and narrow widths against local Herd using an authorized session. No production issuance, revocation or bulk printing is needed.

## Final verification and completion criteria

- [ ] Run focused artifact/permission feature tests with `php artisan test --compact` and explicit filenames. Audit baseline: 54 artifact tests, 205 assertions passed on October 5; expand coverage for the new behaviors.
- [ ] Run new Vitest files using `npm test -- resources/js/test/console/CertificateDesignEditor.test.jsx resources/js/test/console/BadgesPanel.test.jsx` and any certificate-panel test file added during execution.
- [ ] Run targeted ESLint, `vendor/bin/pint --dirty`, PHPStan using repository conventions, console-component-contract tests, production build and `git diff --check`.
- [ ] Broaden testing only for touched shared services or newly discovered regressions; record unrelated baseline failures separately.
- [ ] Self-review every original-spec requirement, dependency use, permission/role propagation, navigation, snapshot behavior, upload lifecycle, empty/error states and dead code.
- [ ] Completion requires: customized certificates contain recipient identity, editor controls affect PDFs, previews reflect drafts, cross-event access is rejected, large badge events have a usable batch workflow, certificate pages are reachable, and visual PDF/browser checks pass.
- [ ] Report any unverified item explicitly. Do not mark finished based only on passing backend tests. Commit/push/deploy only within the authorization supplied at execution time.

## Plan self-review

All October 5 audit findings are assigned to tasks. Original-spec gaps beyond the audit—tenant logo, reset defaults, deletion cleanup, crop marks, overflow, and local/S3 rendering—are included as explicit verification or implementation work. No dependency installation or historical-snapshot migration is required. This is a completion plan, not a claim that these fixes already exist.
