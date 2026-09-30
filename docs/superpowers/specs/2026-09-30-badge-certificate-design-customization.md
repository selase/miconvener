# Badge and Certificate Design Customization — design

**Date:** 2026-09-30

**Status:** Approved architecture; implementation not started

**Scope:** Tenant-provided artwork, safe dynamic overlays, preview and deterministic PDF output

## Purpose

Tenants need to use artwork produced by their own designers while MiConvener continues to supply names, roles, ticket data, QR codes and verification information. Certificate customization currently changes text only; dormant `background_path` and `signature_path` columns are neither uploaded nor rendered. Badges have no template model and are a hard-coded React component printed through the browser.

The first release is a constrained design system, not a Canva clone: upload professional background artwork, choose a preset or position safe dynamic fields, preview it, and generate a print-ready PDF.

## Security and dependency boundary

- Accept PNG and JPEG artwork only. Do not accept arbitrary HTML/CSS, SVG or PDF uploads in the first release.
- Validate MIME from file contents, dimensions, file size and decodeability.
- Strip unsafe metadata by decoding and re-encoding uploaded images before storage.
- Store paths, never raw data or public URLs, in models.
- Read artwork through Laravel Storage and embed data URIs during PDF generation. Rendering must work with local and S3-compatible disks without public network access.
- Use existing Dompdf and QR dependencies; add no package unless implementation proves an existing dependency cannot satisfy an approved requirement.

## Shared layout representation

Dynamic elements use normalized page coordinates from `0.0` to `1.0`, independent of pixels:

```json
{
  "recipient_name": {
    "x": 0.15,
    "y": 0.34,
    "width": 0.70,
    "height": 0.10,
    "align": "center",
    "font_family": "Helvetica",
    "font_size": 32,
    "font_weight": 700,
    "color": "#1E3A8A",
    "visible": true
  }
}
```

Allowed element keys and style values are server-defined. Unknown keys are rejected. Coordinates and dimensions must remain within the page. Fonts come from a small renderer-tested allowlist; custom font uploads are outside the initial release.

## Certificates

### Template fields

Extend `EventCertificateTemplate` with:

- `design_mode`: `miconvener` or `custom_background`;
- `orientation`: initially `landscape` only, retained explicitly;
- `page_size`: initially `a4` only;
- `layout`: validated JSON overlay configuration;
- `design_version`: incremented whenever rendering-affecting fields change.

Use the existing `background_path` and `signature_path`. The system template remains the default for existing records.

### Upload behavior

- Background expects landscape A4 proportions and has documented recommended pixel dimensions.
- Signature expects transparent PNG or JPEG and is optional.
- Replacing an asset writes the new file first, commits the template update, then deletes the old unreferenced file.
- Removing an asset deletes it only after the database update succeeds.
- Deleting an event/template cleans up its owned artwork through model-level lifecycle handling so every deletion path is covered.

### Rendering and immutability

`CertificatePdfService` becomes the only certificate renderer. It renders either the existing MiConvener design or a full-page tenant background with validated overlays.

Create an immutable `EventCertificateDesignVersion` whenever a template's rendering fields or artwork change. It stores the title/body/issuer text, toggles, layout, version number and its own retained background/signature paths. `EventCertificate` references the version used at issuance. Multiple certificates from the same template version share that row and its assets rather than duplicating JSON and files per recipient.

Later template edits create a new version and affect future certificates only. Replacing current template artwork must not delete an asset referenced by an issued design version. Version assets may be deleted only when no certificate references the version; ordinary certificate revocation does not rewrite or recycle a version. Existing certificates without a design-version reference continue using the legacy behavior until explicitly reissued; migration does not invent historical artwork.

The renderer must honour `show_qr` and `show_cpd_hours`, use the tenant identity, and render optional signature/background assets. Verification code and credential identity cannot be hidden in the custom mode; QR may be hidden only if the visible verification code remains.

### Console

The certificate panel gains:

- MiConvener design / custom artwork mode;
- background and signature upload/removal;
- safe color/font controls;
- element visibility and position controls;
- representative preview data clearly marked as preview;
- “Generate test PDF”;
- reset to default design;
- warning that edits apply only to future certificates.

## Badges

### Model

Create one `EventBadgeTemplate` per event with:

- tenant/event IDs;
- width and height in millimetres;
- orientation;
- background path;
- layout JSON;
- tier-style JSON;
- sheet settings JSON;
- design version.

Default dimensions preserve the current approximate 10:7 landscape layout. Presets include common badge sizes; custom dimensions have safe minimum and maximum values.

Allowed dynamic elements initially are:

- event name;
- attendee full name;
- ticket type;
- tier/role label;
- ticket code;
- seat label;
- QR code;
- tenant logo.

### Preview and printing

The React preview and server renderer consume the same validated payload and normalized layout. The browser preview is advisory; the generated PDF is authoritative.

Add a badge PDF endpoint accepting validated registration IDs. The server produces exact physical dimensions on an A4 or Letter sheet according to margins, gaps, rows/columns and optional crop marks. Print history is recorded only after successful PDF generation, not before opening a browser print dialog.

The existing `window.print()` flow remains available during rollout until PDF generation is proven, then is removed to avoid two competing output paths.

### Background workflow

Tenant-provided badge artwork should leave intentional clear zones for dynamic data. The preview displays overflow warnings for long representative names and validates QR contrast/size guidance. MiConvener does not attempt to infer safe placement from arbitrary artwork.

## Authorization and tenancy

- Existing certificate permissions continue to gate certificate templates.
- Badge template read/update receives explicit permissions added to both `PermissionsSeeder` and `RolePermissions`.
- Every template lookup is constrained through the event relationship, not only tenant-global route model binding.
- Uploaded paths are tenant/event namespaced.
- Preview/test endpoints cannot read another tenant's assets or certificate data.

## Error handling

- Invalid or corrupt images are rejected before storage.
- A storage failure leaves the previous design intact.
- Missing artwork at render time falls back to the MiConvener template for preview/test output and records an actionable error; an issued certificate using a missing snapshot asset must not silently change appearance and instead returns a controlled failure.
- Long text is reduced only within defined limits; beyond that the preview and issuance response report overflow rather than clipping silently.
- Bulk badge/certificate PDF work that risks request timeout runs in a queued export flow or enforces a synchronous batch limit.

## Testing

Tests must cover:

- image MIME spoofing, corrupt content, size and dimension limits;
- tenant/event authorization boundaries;
- local and S3-compatible storage reads;
- replacement, removal and deletion cleanup;
- existing templates after additive migration;
- custom background and signature appearance in rendered HTML/PDF inputs;
- certificate design-version immutability and asset retention after template edits;
- `show_qr` and `show_cpd_hours` behavior;
- Unicode and long-name overflow;
- exact PDF page/orientation settings;
- badge physical dimensions and sheet pagination for one, partial and full sheets;
- tier overrides and optional fields;
- print history only after successful generation;
- public verification and downloads with customized certificates;
- frontend build, component prop conventions and preview error states.

Visual QA must render representative PDFs to images and inspect at least default, custom-background, long-name, no-signature and multi-page badge cases.

## Rollout

1. Fix certificate recipient/flag defects from the notification coverage project.
2. Add certificate uploads, snapshot rendering and preview/test PDF.
3. Add badge template model and read/update API.
4. Add badge preview controls.
5. Add authoritative badge-sheet PDF and migrate print logging.
6. Keep defaults visually compatible for tenants who never customize.

## Non-goals

- Arbitrary tenant HTML/CSS
- SVG/PDF artwork uploads
- Custom font uploads
- A general-purpose vector design application
- Automatic interpretation of a tenant's artwork
- Editing the appearance of credentials already issued
