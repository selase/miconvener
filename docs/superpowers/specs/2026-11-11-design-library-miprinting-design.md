# Cross-event design library and MiPrinting

**Prepared 11 November 2026.** Product and phased delivery proposal for owner review. Implementation has not started. The GH₵50 paid-template price is requested. The owner approved GH₵50 per event with unlimited reprints on 11 November 2026. Other proposed defaults below remain recommendations for review.

## 1. Intended experience

An organiser opens an event's Badges page and can choose a design, continue editing, download badges, request a custom badge design, or order printed badges through MiPrinting. The same event workspace links to conference supplies such as tote bags, cloth, pens and T-shirts.

The organiser should carry event details, artwork and previously entered specifications through the journey. Staff confirm which people will appear on a print order, review a proof and approve the final amount and delivery arrangement before production starts.

## 2. What is already available

- `EventBadgeTemplate` persists one editable design per event. It currently has no named cross-event library, publication workflow or paid entitlement.
- The badge designer supports uploaded PNG/JPEG backgrounds, portrait/landscape/custom dimensions, movable text/QR/logo fields, visibility, fonts, printed ticket-category overrides and A4/Letter output. Badge names wrap and shrink, with an explicit print error when they cannot fit.
- `StoreSellerQuotes` supports goods, custom printing and delivery amounts, production terms and customisation. Store payments, immutable receipts, refunds and collection/delivery workflows exist.
- `CommerceOfferingRegistry` currently implements purchase and venue reservation offerings. Digital template licences and pure design services need explicit handling. Stock and physical collection are not suitable entitlement rules.
- Event-scoped staff access exists through `EventStaffScope`. A member of one event's team must not discover another event's private design or attendee list.

The shipped neonatal conference is the acceptance reference for the first library template. Its official logos, colours and event details are event artwork, not assets to publish for unrelated organisations. A reusable public design uses replaceable organisation/logo/title slots and licensed generic artwork.

## 3. Library and application to an event

### Content and ownership

Provide two views in a single library, **MiConvener designs** and **My organisation's designs**. Each item has a name, thumbnail, free/paid price, supported layouts, editable areas and a preview with representative attendee names. Search and filters cover portrait, landscape and custom dimensions, with a free/paid filter.

An organiser can save their event design as a private event template. Promoting it to a named organisation-wide template requires an organisation administrator or an explicitly delegated organisation-level catalogue permission. Event-scoped staff may browse templates deliberately shared with their event or the whole organisation, but cannot enumerate other private event designs or promote them organisation-wide. Publication to the shared MiConvener library requires the existing platform-role authority, with a dedicated catalogue permission if needed. Global designs cannot silently expose customer artwork or attendee information.

Versions are immutable once published or purchased. Editing a published template creates a new version. Applying a design creates an event-owned copy and records its source/version. Existing events keep their current copy when a catalogue version changes or is withdrawn. Withdrawal stops new selection and purchase while retaining licensed versions for existing events and financial records.

Applying to an event with a saved design shows a before/after preview and asks the organiser to replace it. Preserve the previous event design as a recoverable revision. Store the layout, sheet settings, font references and artwork together. Never mutate registrations or ticket prices. Printed category mappings bind to the destination event's own ticket types. Match labels only through an explicit mapping or exact unambiguous labels, and show unresolved rows for review. Never copy source ticket UUIDs into another event.

### Background and size adaptation

Store background artwork separately from changing fields. Structured templates expose replaceable logo, title, date and colour slots. Supply explicit portrait and landscape compositions for curated templates. Normalised coordinates assist resizing within an orientation, but a tall image must not be silently stretched into a wide one.

When the organiser changes orientation or size, use the appropriate composition when available. Otherwise offer Contain, Cover or Stretch with a live crop/fit preview, preserve the original asset, and let the organiser reposition fields. Contain is the recommended initial fallback. A template with a single uploaded background remains usable, with its fit/crop behaviour made visible before applying.

Use physical millimetres throughout, derive aspect ratio from the requested dimensions and re-run text fitting and sheet packing for the target size. Show the number of badges per sheet and the physical size beside Download. Preserve the current validated print range and disclose it in the controls. The current application validates 40–210 mm per side plus fit on A4/Letter. Expanding supported sheet formats or that range requires renderer tests, rather than a UI promise of unlimited custom sizes.

Safe areas and crop/bleed belong to the template and print specification. Fixed logos must retain their aspect ratio. Long names and all five conference categories must be tested against every curated composition. User-uploaded backgrounds stay private to the organisation unless expressly published by platform staff with asset rights confirmed.

## 4. Free and GH₵50 paid designs

Free designs apply immediately. Paid designs can be previewed and adapted before purchase, including long-name and target-size previews. Purchasing must happen once at an obvious point. Applying an already licensed version and reprinting must not create another charge.

**Approved licence** — GH₵50 per event, with unlimited reprints. Proposed included usage covers edits, all attendee quantities and unlimited downloads for that event. A licence covers the same design family’s orientation variants, dimension changes and later catalogue revisions for its licensed scope. Each event copy pins an immutable version and upgrades only after an explicit preview/apply action. A genuinely different design family requires its own purchase. Saving an adapted paid template privately retains its paid source provenance and licence requirement when applied elsewhere. Bind licence uniqueness to the purchasing organisation, event and design family.

User-uploaded artwork and private organisation templates remain free to apply. Saving, changing a background or using an unlocked design does not itself trigger a payment. A paid source's licence remains required after copying or adapting that source. Preview outputs for unlicensed paid designs carry a visible sample watermark and cannot be used as clean print production files. The watermark disappears after verified payment. Before purchase, serve watermarked preview derivatives and provisional field changes through authorised server rendering. Original paid artwork/font downloads and clean PDF endpoints require the same entitlement. Client-side hiding alone cannot enforce paid access. The organiser sees **Free**, **GH₵50** or **Purchased** consistently.

### Payment and accounting boundary

Use an explicit digital-template offering in the shared commerce pipeline, alongside the existing purchase/reservation types. The seller's order ownership and the purchasing organisation/event must be separate typed associations. Never use the buying tenant's settlement account to collect MiConvener's template sale or grant it seller earnings.

Reuse provider reference claims, original-account verification, durable captured-payment evidence, idempotent confirmation, receipts, reconciliation and full-refund orchestration. Add entitlement issuance atomically with the confirmed sale through `LedgerService`. A callback/webhook replay grants one licence and one receipt. A browser redirect alone cannot unlock a design. Unknown, extra or mismatched captures stay in review and show a recoverable pending state.

Prices come from the server, stored in integer pesewas. GH₵50 is 5,000 pesewas. Freeze price, catalogue version, buyer organisation/event, seller account, currency and licence terms before provider HTTP. Extend `PriceBook` where platform price presentation applies. Charge the approved GHS amount and do not invent a USD price. Recommend GH₵50 as the customer's displayed total, with platform gateway costs absorbed in that price, subject to owner approval.

Use a platform-owned selling identity with approved terms and its correct revenue/payable treatment. Do not infer that Purpledot's 3% merchandise agreement applies to MiConvener's own digital sales. Third-party template sellers, royalties, split payments and subscriptions are outside the requested first release.

The existing event design permission governs free use and editing. Add an explicit purchase permission that an organisation administrator can delegate to an event's authorised staff. Every preview/apply/purchase/download request re-checks current tenant, event scope and paid entitlement on the server. An event-scoped organiser cannot spend another event's purchase or read another event's private template. Checkouts use an explicit buyer confirmation, with no reuse of saved billing authorisations by default.

## 5. MiPrinting and custom badge design

### Entry points and samples

Keep **Design library** and **Download badges** as the main badge actions. Add **Order with MiPrinting** and **Request a badge design** nearby. On narrow screens these remain labelled, accessible actions rather than icon-only options. Order status links back to the same event.

MiPrinting has a service page with samples, materials, finishing options and a clear request action. Show approved real work with captions identifying the material and finish. Illustrative samples must be labelled. Avoid publishing customer names, attendee rosters or institutional logos as public portfolio material without the owner's approval for that use.

### Request and quote

A print request starts with event, requested-by contact, badge dimensions, selected design version, selected attendees and quantity. Show selected people, category counts, blanks/spares, required date, collection/delivery preference and destination/contact. The organiser can exclude people, supply an updated roster or order a later batch. Price is quoted until a real print price list has been approved.

Allow relevant options such as paper/card stock, single/double-sided work, holder/lanyard inclusion and finishing. Show only options MiPrinting actually offers. An uploaded reverse-side design can be quoted without inventing a second face in the current badge editor. Explain all quoted items and delivery in the full price before payment.

Custom design requests collect a short brief, brand assets, dimensions, required formats and deadline. Staff return a quote with a stated number of revisions, scope, final deliverables and timing. A paid catalogue licence and custom design work are distinct quote lines. Do not charge GH₵50 automatically for bespoke work or count it twice when a package already includes the licence.

### Proof and production

Freeze an immutable print pack when the organiser approves a proof. Include template revision, resolved name/category data, selection/quantity, exact PDF, dimensions, material, finishing, artwork checksums, quote, delivery agreement and explicit approver/time. Generate the production PDF on the server. The organiser must review and approve that exact proof, including spelling and category information.

Later attendee or design edits do not mutate a paid or approved print pack. Show that changes exist and offer a revised proof or a separately quoted additional batch. Proof approval and payment are independent recorded states. Recommend starting production only when the agreed payment requirement and current proof approval are both satisfied. The first release can take full payment after quote acceptance. Deposits and milestones need their own explicit design if required operationally.

Keep the request journey concise. Persist the brief, show a submitted/quoted/awaiting-proof/approved/in-production/ready/dispatched/completed status and a next action. Finance state remains separate. Staff production controls cannot mark an order paid. Reuse collection/delivery handover for completed physical goods, while a digital-only design service uses protected file delivery and acceptance.

## 6. Conference paraphernalia

Expose **Conference supplies** from the event and MiPrinting page. Initial categories cover tote bags, conference cloth design, pens, T-shirts and an other-items request. A mixed request can contain several lines, each with quantity and its own specifications. One seller supplies an order in the initial release. Design and printing work that is part of manufacturing goods is quoted as cost lines on that physical order, with design proof approval before production and physical delivery governing completion. A standalone digital-only design is its own typed design-service order with secure file delivery and acceptance. A request containing independent physical and digital-only purchases is presented as clearly labelled linked quotes/orders with their own totals and actions. Mixed-offering checkout and per-line financial settlement are not promised by this first release.

Capture suitable details per item. Tote bags use size/material/colour/printing areas. Pens use model/branding/quantity. T-shirts use garment colour, size breakdown and print positions. Conference cloth design records design scope and deliverable files, with fabric production shown separately when offered. Do not assume design work includes manufacturing or delivery.

Reuse made-to-order enquiries, seller-issued quotes, versioned accepted prices, payment links, receipts, refunds and physical fulfilment. Add an event-linked request/production brief around these facilities. Goods remain purchase offerings. Pure design services receive an explicit typed handler so stock, collection passes and physical-ready messages are never applied to digital files.

## 7. Staff operations, data and communications

The organisation's event staff see their permitted requests and proofs. MiPrinting staff have a supplier work queue, production statuses, private artwork access and appropriate order permissions. Grant the minimum access to the frozen print pack. The organiser’s event finance and payment history are not necessary to print names. Entry QR codes are optional print content. If the selected design shows a QR, the organiser explicitly chooses whether to include the real entry code in the supplier pack and reviews that choice on the proof. The pack must reflect the choice without silently changing a saved design. The supplier receives an entry QR only through that authorised, frozen print job. Never include portal login, payment, collection or delivery credentials. Public samples always use illustrative QR codes.

Attendee data sharing is explicit when the organiser submits the selected print pack to MiPrinting. Share names, categories and requested print fields only. Phones/emails are included only if the organiser intentionally chooses them as printed fields. Store delivery contacts separately from badge content. Private files use authorised downloads, short-lived links and a documented retention/deletion job. Recommend keeping source rosters and proof working files for 90 days after completion, with a clear retention notice and a deletion action after any active dispute is resolved. Financial records follow the existing retention rules.

Transactional order and proof messages go to the organiser's requested contact, not to attendees. Imports and print generation do not send attendee invitations or marketing. Existing event notification settings continue to govern their existing flows. Portfolio use needs separate consent and is never implied by ordering print work.

## 8. Delivery milestones

### M1 — Cross-event library and adaptable designs

Build private organisation templates, platform catalogue, immutable published versions, safe artwork/font storage, responsive library and preview/apply/restore. Include save-from-event and field/category mapping. Deliver curated portrait and landscape compositions plus custom-size fitting. M1 releases free/private use and paid previews. Paid clean exports become available with M2’s verified entitlement flow. Preserve legacy event templates as private event-owned designs without automatic publication.

Acceptance covers cross-tenant access, restricted event staff, previous designs, publication/withdrawal, copied artwork survival after source deletion, font ownership, unsafe uploads, replaced event designs, destination ticket IDs, A4/Letter dimensions and the neonatal design in both orientations with long names.

### M2 — GH₵50 access and purchase recovery

Implement server pricing, the agreed licence scope, explicit purchase permission, digital offering, platform commercial terms and seller identity, payment verification, atomic entitlement/receipt, refund semantics and recovery UI. A fully refunded, unused licence returns to preview-only access. Record first use when a clean asset download, clean PDF or licensed production pack is generated. Watermarked previews and provisional edits do not count as use. Define the refund treatment for a design already used before enabling sales. A receipt and purchase record identify the licensed event/version.

Acceptance covers free use, preview watermark, clean-export protection, 5,000-pesewa pricing, double-click/retry/callback/webhook races, wrong account/amount/currency, pending provider outcomes, entitlement across orientation changes, staff revocation and the existing merchandise/venue finance regression suites. Verify the live licence purchase at an owner-approved amount after sandbox acceptance.

### M3 — MiPrinting and bespoke badge design

Deliver approved sample gallery, print/design briefs, event-linked staff queue, quote acceptance, server-generated proof, immutable approved print packs, payment requirement, production states and collection/delivery. Use existing quote/payment services with explicit service extensions where required. Prepare the supplier's real contact, fulfilment options, cancellation/refund terms and operating agreement before taking payable orders.

Acceptance covers selected attendees and blank spares, no attendee notifications, privacy/permissions, changed roster after approval, proof/version mismatch, duplicate approvals, missed deadlines, rejected proof, expired/revised quotes, payment recovery, refund cases, inaccessible private artwork, and the complete neonatal print order through collection or delivery.

### M4 — Paraphernalia and combined requests

Add the item-specific briefs, mixed request lines, individual proofs/specifications and quote breakdown for bags, cloth design, pens and T-shirts. Reuse approved brand assets through an explicit selection. Price and quantity remain visible per line, including printing and delivery. Support repeat orders by cloning specifications into a new request with current prices and explicit production approval.

Acceptance covers garment size totals, per-item quantities, artwork revisions, design-only delivery, mixed physical orders, deadlines, replacement/extra batches, customer-visible status and preserved existing public/member merchandise flows.

Ship each milestone as a reviewable PR or a small dependency-ordered set. Use native Laravel/React patterns, test first for the stated regressions, rebuild `public/build` for UI changes and perform one focused self-review plus browser acceptance. Record actual completion in the existing roadmap when implementation reaches each milestone. Production activation follows the verified release and approved commercial details.

## 9. Owner decisions and defaults to confirm

1. Agreed on 11 November 2026 — GH₵50 per event with unlimited reprints. Edits, downloads, orientation changes and version upgrades are proposed included usage as specified above.
2. Whether GH₵50 is the final customer total. Proposed yes, platform absorbs gateway costs.
3. Seller/service identity and authorised MiPrinting contact. Proposed a platform-operated service with an explicit supplying organisation, not an assumed existing merchant.
4. Refund/retention/production policy. Proposed unused licence refunds through existing verified refund controls, full quoted payment plus approved proof before production, and 90-day print-pack working-file retention after completion. Used-licence and started-production cancellation terms require owner wording.
5. Actual print capabilities, sample-work permissions, prices, lead times, revision inclusions and delivery coverage. Quote requests can collect these details while a catalogue is being established, but promised offers must reflect real capabilities.

These are the only commercial/operational decisions identified by the current audit. Product design and a non-charging visual proposal can proceed while they are reviewed. No new price beyond the requested GH₵50, print tariff, seller agreement or customer communication has been activated.
