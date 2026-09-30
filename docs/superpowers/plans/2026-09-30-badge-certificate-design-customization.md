# Badge and Certificate Design Customization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let tenants upload professional badge/certificate artwork, position safe dynamic fields, preview it, and generate deterministic print-ready PDFs without changing already issued credentials.

**Architecture:** Validated raster artwork is stored through Laravel Storage and embedded as data URIs by focused renderers. Certificate template edits create immutable design versions referenced by issued certificates. Badges gain one event template and an authoritative server-generated sheet PDF; React previews consume the same normalized layout schema.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL, Dompdf, Bacon QR Code, Laravel Storage, Inertia/React, Tailwind CSS 4, Pest 5

**Spec:** `docs/superpowers/specs/2026-09-30-badge-certificate-design-customization.md`

## Global Constraints

- Accept re-encoded PNG/JPEG only; no arbitrary HTML, CSS, SVG, PDF or font uploads.
- Add no dependency unless existing image/PHP capabilities demonstrably cannot re-encode safely and the user approves it.
- Coordinates are normalized `0.0..1.0`; unknown elements/styles are rejected server-side.
- Existing tenants retain the current visual defaults.
- Issued certificates are immutable through referenced design versions and retained assets.
- PDF generation reads through Laravel Storage and does not require public S3/R2 access.
- Badge physical dimensions are expressed in millimetres and server PDF is authoritative.

## Review Focus

- Replacing certificate artwork must not delete assets used by issued credentials; Task 2.
- MIME-spoofed/corrupt/oversized artwork must never reach storage; Task 1.
- Long/Unicode names must report or safely handle overflow rather than silently clip; Tasks 3 and 6.
- Badge PDF page dimensions, pagination and print history must be deterministic; Task 6.
- Every asset/template route must constrain through tenant and event; all tasks.

---

### Task 1: Build safe artifact artwork storage

**Files:**
- Create: `app/Services/Design/ArtifactArtworkService.php`
- Create: `app/ValueObjects/Design/StoredArtwork.php`
- Create: `app/Http/Requests/Tenant/StoreArtifactArtworkRequest.php`
- Test: `tests/Feature/Design/ArtifactArtworkServiceTest.php`

**Interfaces:**
- Produces `store(UploadedFile $file, string $tenantId, string $eventId, string $kind): StoredArtwork`.
- Produces `dataUri(string $disk, string $path): string` and `delete(string $disk, ?string $path): void`.
- `StoredArtwork` contains disk, path, MIME, width and height.

- [ ] Write failing tests for valid PNG/JPEG, spoofed MIME, corrupt bytes, dimensions, size, tenant/event path namespace, local data URI and storage failure.
- [ ] Verify RED.
- [ ] Implement decode/re-encode using available PHP image support; if unavailable, stop and request dependency approval rather than storing untrusted originals.
- [ ] Run focused tests and format.
- [ ] Self-review metadata stripping, memory limits, path traversal, S3-compatible reads and partial-write cleanup.
- [ ] Commit `feat(design): store artifact artwork safely`.

### Task 2: Add immutable certificate design versions

**Files:**
- Create: `database/migrations/landlord/2026_09_30_130000_create_event_certificate_design_versions_table.php`
- Create: `database/migrations/landlord/2026_09_30_130001_add_design_version_to_event_certificates.php`
- Create: `database/migrations/landlord/2026_09_30_130002_add_design_fields_to_event_certificate_templates.php`
- Create: `app/Models/EventCertificateDesignVersion.php`
- Modify: `app/Models/EventCertificateTemplate.php`
- Modify: `app/Models/EventCertificate.php`
- Create: `app/Services/Certificates/CertificateDesignVersionService.php`
- Test: `tests/Feature/Events/EventCertificateDesignVersionTest.php`

**Interfaces:**
- Produces `snapshot(EventCertificateTemplate $template): EventCertificateDesignVersion`.
- Version row owns immutable text/toggles/layout and retained artwork paths; certificate has nullable `design_version_id` for legacy rows.

- [ ] Write failing version creation, reuse, changed-template, asset retention and legacy-null tests.
- [ ] Verify RED.
- [ ] Create additive migrations, models and relationships with exact tenant/event constraints.
- [ ] Implement deterministic design hash so unchanged templates reuse one version.
- [ ] Prevent deletion of referenced versions/assets; clean only unreferenced versions through the service.
- [ ] Run focused and existing certificate tests; format.
- [ ] Self-review foreign-key deletion behavior, concurrent snapshot creation, historical asset lifetime and rollback cleanup.
- [ ] Commit `feat(certificates): version issued certificate designs`.

### Task 3: Render customized immutable certificates

**Files:**
- Create: `app/Services/Design/ArtifactLayoutValidator.php`
- Modify: `app/Services/Certificates/CertificatePdfService.php`
- Modify: `resources/views/pdf/certificate-template.blade.php`
- Create: `resources/views/pdf/certificate-custom.blade.php`
- Modify: `app/Http/Controllers/Tenant/EventCertificateController.php`
- Modify: `tests/Feature/Events/EventMultiRoleCertificatesTest.php`
- Create: `tests/Feature/Events/EventCertificateCustomDesignTest.php`

**Interfaces:**
- Validator returns canonical allowed element configuration or throws validation exception.
- Renderer selects legacy/system/custom view from the certificate's design version and embeds artwork/signature data URIs.

- [ ] Write failing custom background, signature, QR toggle, CPD toggle, immutable post-edit, Unicode and overflow tests.
- [ ] Verify RED.
- [ ] Implement allowlisted elements/fonts/styles and bounds validation.
- [ ] Update issuance to snapshot a version in the same transaction as certificates.
- [ ] Render system/custom PDFs exclusively through `CertificatePdfService`.
- [ ] Run certificate, public verification and report-export tests; format.
- [ ] Self-review that verification identity cannot disappear, missing historical assets fail explicitly, and no remote URL is fetched.
- [ ] Commit `feat(certificates): render tenant certificate artwork`.

### Task 4: Add certificate designer API and console

**Files:**
- Create: `app/Http/Requests/Tenant/StoreCertificateTemplateRequest.php`
- Create: `app/Http/Requests/Tenant/UpdateCertificateTemplateRequest.php`
- Modify: `app/Http/Controllers/Tenant/EventCertificateController.php`
- Modify: `routes/subdomain.php`
- Modify: `resources/js/Pages/Tenant/Events/panels/CertificatesPanel.jsx`
- Create: `resources/js/Pages/Tenant/Events/Certificates/CertificateDesignEditor.jsx`
- Create: `resources/js/Pages/Tenant/Events/Certificates/ArtworkUpload.jsx`
- Create: `resources/js/Pages/Tenant/Events/Certificates/ArtifactLayoutPreview.jsx`
- Test: `tests/Feature/Events/EventCertificateCustomDesignTest.php`

**Interfaces:**
- Store/update accept multipart background/signature, explicit removal flags, design mode and validated layout.
- Adds authorised test-PDF endpoint using representative preview data without issuing a credential.

- [ ] Write failing upload/remove/replace/foreign-event/test-PDF tests.
- [ ] Verify RED.
- [ ] Move inline validation to Form Requests and implement transactional asset replacement.
- [ ] Build system/custom mode, uploads, allowlisted controls, live preview, reset and future-certificates warning.
- [ ] Run backend tests, `npm run build`, ESLint/Prettier commands used by the repository and format.
- [ ] Visually render and inspect default, background, signature, long-name and no-QR test PDFs.
- [ ] Self-review all UI states, component props, keyboard access, replacement cleanup and responsive preview.
- [ ] Commit `feat(certificates): add tenant certificate designer`.

### Task 5: Add event badge template and permissions

**Files:**
- Create: `database/migrations/landlord/2026_09_30_140000_create_event_badge_templates_table.php`
- Create: `app/Models/EventBadgeTemplate.php`
- Create: `app/Http/Requests/Tenant/UpdateEventBadgeTemplateRequest.php`
- Create: `app/Services/Badges/BadgeTemplateService.php`
- Modify: `app/Http/Controllers/Tenant/EventBadgeController.php`
- Modify: `routes/subdomain.php`
- Modify: `database/seeders/PermissionsSeeder.php`
- Modify: `app/Libraries/RolePermissions.php`
- Test: `tests/Feature/Events/EventBadgeDesignTest.php`
- Modify: `tests/Feature/Admin/PermissionsSeederTest.php`

**Interfaces:**
- One template per event; produces default payload preserving current 10:7 appearance.
- Adds `read badge-template` and `update badge-template` to seed and built-in role mapping together.

- [ ] Write failing default, update, dimensions, unknown element, tenant/event ownership and permission tests.
- [ ] Verify RED.
- [ ] Implement migration/model/service/Form Request/controller routes.
- [ ] Add both permissions atomically to seeder and role mappings.
- [ ] Run badge and permission consistency tests; format.
- [ ] Self-review custom dimension bounds, unique event row, plan/permission behavior and existing badge payload compatibility.
- [ ] Commit `feat(badges): add tenant badge templates`.

### Task 6: Generate authoritative badge-sheet PDFs

**Files:**
- Create: `app/Services/Badges/BadgePdfService.php`
- Create: `resources/views/pdf/badge-sheet.blade.php`
- Create: `app/Http/Requests/Tenant/GenerateBadgeSheetRequest.php`
- Modify: `app/Http/Controllers/Tenant/EventBadgeController.php`
- Modify: `routes/subdomain.php`
- Modify: `tests/Feature/Events/EventBadgeTest.php`
- Create: `tests/Feature/Events/EventBadgePdfTest.php`

**Interfaces:**
- Produces `generate(Event $event, EventBadgeTemplate $template, Collection $registrations): DomPDF`.
- Endpoint accepts event-owned registration UUIDs and records print history only after PDF output is generated successfully.

- [ ] Write failing exact-size, one/partial/full sheet, invalid registration, optional field, tier override, QR, generation failure and print-history tests.
- [ ] Verify RED.
- [ ] Implement physical mm-to-point layout, sheet pagination, crop marks and embedded artwork.
- [ ] Enforce a documented synchronous batch limit; return validation guidance above it rather than timing out.
- [ ] Run badge suites and format.
- [ ] Render and inspect representative A4 PDFs at actual-size settings.
- [ ] Self-review browser download headers, memory limits, page overflow, print count accuracy and deterministic ordering.
- [ ] Commit `feat(badges): generate print-ready badge sheets`.

### Task 7: Build badge designer and retire browser-authoritative printing

**Files:**
- Modify: `resources/js/Pages/Tenant/Events/panels/BadgesPanel.jsx`
- Create: `resources/js/Pages/Tenant/Events/Badges/BadgeDesignEditor.jsx`
- Create: `resources/js/Pages/Tenant/Events/Badges/BadgeLayoutPreview.jsx`
- Create: `resources/js/Pages/Tenant/Events/Badges/BadgeSheetSettings.jsx`
- Modify: `tests/Feature/Events/EventBadgeDesignTest.php`

**Interfaces:**
- React preview consumes server template schema and submits the same normalized layout.
- Print action downloads server-generated PDF; `window.print()` is no longer authoritative.

- [ ] Add API assertions needed by designer state and write frontend-adjacent backend tests first.
- [ ] Build presets, artwork upload, field visibility/position, tier styles, sheet controls, preview overflow warnings, reset and PDF download.
- [ ] Keep the global `.print-area` CSS because other exports use it; remove only badge-specific `window.print()` code after server PDF tests and visual QA pass.
- [ ] Run badge suites, full frontend build, repository lint and format.
- [ ] Render/inspect default, tenant-artwork, long-name, one-page and multi-page badge outputs.
- [ ] Run PHPStan over all changed PHP.
- [ ] Perform final implementation self-review covering dependencies, schema, permissions, storage lifecycle, issued-certificate immutability, UI touchpoints, print dimensions, dead code and test proportionality.
- [ ] Commit `feat(badges): add tenant badge designer`.
