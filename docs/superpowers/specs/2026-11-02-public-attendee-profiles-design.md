# Event attendee profiles: explicit public opt-in

**Status:** proposed design for owner visual/privacy review; not approved for implementation. No public identities, schema or endpoints are added by this document.
**Parent:** project completionPhase 6; Community follow-ups §6.

## Intent and existing behaviour

The public event page currently shows only an anonymous Going count (`PublicEventController` public payload `going_count`; `resources/js/Pages/Public/Events/Show.jsx`). The owner requested the visible attendee/headshot area and specifically chose anonymous counts until explicit opt-in is built. Keep the existing square-edged teal event design, existing features, ticket verification and anonymous count.

## Recommended experience

1. In a verified buyer's own My ticket view, an optional section says “Let others know you’re going”. It starts private. It never appears as a prerequisite to registration, payment, admission, a certificate or a member benefit.
2. Choose a public display name (required only if publishing), optional photo, and inspect an exact preview. Do not prefill/publicise a legal name, account photo, email, phone, employer, member role or registration answers. Initials are generated from the chosen display name if there is no photo.
3. An unchecked consent checkbox states: “Show this display name and optional photo publicly on this event page. Anyone can see or copy them. I can remove my profile at any time.” Publishing requires a separate explicit button after consent. No implied consent from terms, following, membership or check-in.
4. The event's existing Going area keeps the confirmed count and adds up to six consenting profiles, then an accessible “See public attendees” list. Label the visible subset: “Shared by attendees who chose to appear.” No email/contact links, profile search, social graph or cross-event lookup.
5. My ticket displays a clear Published state, preview, Edit and Remove from event page. Removing needs no extra email code beyond current valid email proof. It immediately hides name/photo and makes its image endpoint unavailable; a successful response confirms removal. Existing copies/screenshots outside MiConvener cannot be recalled, and the consent copy says so.

## Eligibility and scope

- Recommended first release: adults publishing only their own confirmed issued ticket for a public, published event. Include an adult-self confirmation; do not collect date of birth. Guardian/minor publication is deferred pending a separate policy.
- Eligibility is re-read under the same identity/registration locks on every write. The exact independently verified registration email owns the choice; the group payer alone cannot publish another attendee. At most one self-designated profile per verified email/event is allowed, even where a shared email owns several tickets. The attendee explicitly confirms it is their own ticket; other attendees must use their own existing verified ticket-ownership/transfer flow before opting in. Phone proof, follower status, staff role and representative access do not grant publication rights. Staff can hide an abusive profile, never opt another attendee in.
- Private events have no public profiles even if an old opt-in exists. Event cancellation, ticket cancellation/full refund/transfer, publication suspension or expiry hides the profile. A new recipient or restored ticket starts private; never resurrect previous consent automatically. Existing anonymous count keeps its current definition independent of opt-in.
- Recurring events: consent belongs to that exact registration/event, not every later service or another event. Event-level profiles should not claim a person attends each occurrence. No automatic check-in/location broadcast.
- Event organisers can turn off the public profile feature for an event. Default rollout is off; owner approves Purpledot pilot after acceptance. Switching it off hides profiles and revokes publication; switching on never republishes old choices.

## Data, images and privacy

- One profile per registration/event with chosen display_name, optional private image reference, consent version/time, publication revision, current visibility and withdrawal time. Immutable minimal consent/withdrawal events contain no photo or copied private registration payload. Never put public profile choices into ticket QR/offline snapshots.
- Proposed retention: stop showing profiles no later than 30 days after the event ends or 90 days after consent, whichever comes first. The 90-day maximum also covers recurring/open-ended events; renewal always needs fresh explicit consent. Purge associated display name/photo within 24 hours of removal/expiry. Keep minimal pseudonymous consent event identifiers/version/timestamps for 90 days after withdrawal/expiry, then purge them. This is a proposed operational retention policy, not a statutory/legal claim; owner approval is required before code.
- Photos: JPEG/PNG/WebP only, 2 MiB maximum, decode/validate pixel dimensions, reject SVG/remote URLs/animations, strip metadata and re-encode a bounded square derivative using installed image facilities. Store privately; no public object URLs or raw storage keys. No new dependency without approval. A photo failure leaves the old choice unchanged; abandoned uploads are purged by an idempotent bounded job.
- Public payload is an explicit minimal DTO: opaque profile/revision ID, display name, controlled image URL and initials. Never include buyer email, phone, registration ID/code, membership, consent logs or financial data. Responses are no-store; public image requests recheck current consent/event eligibility and revision, returning 404 after withdrawal. Page/application CDN caches must bypass profile data. Already cached/downloaded material cannot be recalled.
- Preserve normal verified-session blocking/signout safety and no-store rules. Upload/delete/withdraw require CSRF, exact ownership, bounded rate limits and idempotency. Do not use an existing offline snapshot as proof of consent or identity.

## Moderation and failure handling

- Minimal first release: tenant-scoped `manage public attendee profiles` permission (Org Superadmin built-in role only initially) to hide a specific profile with a bounded reason and append-only audit; no organiser edits or impersonation. Use existing organiser-contact UI for complaints rather than inventing an anonymous reporting system. A hidden profile stays private until a platform-authorised moderator releases the hold and the attendee gives fresh explicit consent. Both permissions need normal seeded/gated navigation and owner rollout approval; organiser hiding is not a payment/admission sanction.
- Empty: anonymous count plus “Attendees can choose to appear here.” No names if nobody opts in. Loading failure hides the optional profile list without breaking programme, tickets, live polls/Q&A/surveys or registration. Expired proof prompts normal verification before saving; typed changes remain local and are not published.
- Accessible labels, keyboard focus return, clear visibility status, both themes, 390px/200% text, long display names and no-photo initials. Prefer existing primitives; no new visual framework.

## Owner review calls

Recommend: event-specific explicit self-consent, adult-only first release, optional photo, no external profile links, and 30-days-after-event visibility capped at 90 days from consent, immediate withdrawal, and 90-day minimal consent-audit retention. These are proposed product/privacy decisions, not previous owner approvals. Review the visual before implementation. No real attendee data is used in the preview.

## Planning/acceptance requirements after design approval

Native implementation plan must name persistence/migrations, original-email proof guards, event/registration eligibility hooks, moderation permission/seeders/navigation, image pipeline/cleanup, and the exact public cache bypass. Tests fail first for missing consent, wrong/phone-only/representative ownership, cancellation/transfer/private conversion, stale cached image revocation, duplicate submissions, unsafe images, retained offline data and tenant isolation. One independent code review and final CI before merge; Purpledot-only activation remains a separate owner decision.

Builder-check resolved group-payer ambiguity with exact registration-email ownership and one self-profile/email/event; recurring/open-ended visibility is bounded at 90 days; moderation actors/hold release are explicit. The proposed privacy/retention choices still await owner approval, not implementation.
