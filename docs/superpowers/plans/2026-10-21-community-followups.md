# Community acceptance and follow-up plan

> **For agentic workers:** Use the native approach. Execute the approved release/acceptance work now; the remaining workstreams below are proposed for owner review. Use superpowers:executing-plans for subsequent approved implementation. Tick steps with their work commits.

**Goal:** Close the original Community acceptance gaps, then deliver clearly bounded improvements without enabling unapproved payments, bookings or public identities.

**Architecture:** Reuse the existing Laravel services, React personal app and tenant rollout controls. Separate each future product change into its own reviewed spec/implementation plan; do not bundle unrelated money, consent and marketing work into one release.

**Tech Stack:** Existing Laravel 13, React/Inertia, PostgreSQL, private object storage, Paystack and tracked Vite assets; no dependency additions assumed.

**Spec:** `docs/superpowers/specs/2026-10-15-community-design.md`; Giving/benefits/Library plans linked in `docs/superpowers/community-roadmap.md`.

## Global Constraints

- Native implementation; one proportionate self-review and one CI gate per release, avoiding repeated full local/remote cycles without a changed risk.
- Purpledot only for production pilots. Preserve other tenant flags, member dues and settlement settings. Churches and volunteer groups require no dues to use Giving, Library, service notes or polls.
- No real charge, refund, transfer, historical association/cutover or marketplace booking activation is authorised by this plan.
- Email-first verification stays intact; never manufacture recipient proofs or expose signed links/QR secrets in logs or chat.
- Test content must remain explicitly labelled. Restore temporary publication after acceptance; preserve registrations/responses as audit history.
- Changes to JS/CSS include rebuilt `public/build` in the same code commit. Affected tests first; `pint --dirty`; full existing CI before merge.

## Review Focus

1. Stale summary rows must not hide authoritative member actions or resurrect disallowed links, including staff rooms.
2. A free registration internally marked confirmed is not an issued ticket until the actual email confirmation succeeds.
3. Recurring services retain distinct occurrence IDs/dates; publishing one sermon must not reveal another occurrence or private programme notes.
4. Private documents, money and messages must stay inaccessible after expiry/sign-out and absent from offline storage.
5. Sandbox evidence is not live payment/booking readiness; public names/photos require independent explicit opt-in.

## 1. Authorised navigation release and Purpledot acceptance

**Files:** `resources/js/Pages/Public/Community/CommunitiesPanel.jsx`, `resources/js/test/portal/AttendeeInbox.test.jsx`, rebuilt assets; existing roadmap and milestone 6/7 acceptance notes.

- [x] Fetch and reconcile current main in the owned checkout, retaining Claude's room-booking/pricing/webhook work. Resolve generated conflicts by rebuilding, not by retaining stale assets.
- [x] Extend regressions for allowed and denied Library, Services, Polls and room links with duplicate inbox/organiser rows. Run 37 affected JS tests, scoped ESLint, Pint and production build.
- [x] Pass full PR CI, merge the exact approved/tested tree and verify automatic Cloud deployment SHA/assets. Do not trigger an unnecessary second deployment.
- [x] Verify one card, Messages and all eligible content links using the owner's normal verified no-dues member session; verify keyboard activation and no console errors.
- [ ] Finish narrow-width/contrast acceptance. Light/dark layouts were inspected, but the viewport override remained1680px despite requesting390px; no mobile pass is claimed. The existing Messages button has white14px text on rgb(0,216,196), calculated1.81:1 contrast; use the established `text-accent-ink` token in a scoped accessibility follow-up and audit equivalent personal-app primary actions.
- [x] Temporarily publish only the synthetic service note for the existing 25 October occurrence through `CommunityLibrary::save`; open it as the member and confirm the 1 November occurrence does not inherit it. Restore the note to draft.
- [x] Published text-document reading and poll submission previously passed. Recheck their navigation and recorded response state after deployment without duplicating the response; reopen a separate bounded fixture only if further submission testing is needed.
- [x] Finish the existing zero-cost registration's actual confirmation email and ticket/QR display. Yahoo's system Spam contained the expected confirmations. Marked only the diagnostic one Not Spam, followed its real confirmation button, then completed the deployed email/code membership recheck. Ticket workspace showed confirmed and the QR; production readback confirms email_verified=true, ticket_issued=true, amount/charged_amount0. No forged proof or private-link logging; no physical-phone scan claimed.
- [x] Restore the synthetic recurring event to draft/public after ticket verification. Registration and poll response retained; pilot active with0charges; before/after rollout-settings hashes match across all9organisations.
- [x] Verify delivery of one explicitly authorised diagnostic copy of the issued ticket: native mailable sent once22:41:21UTC withonePDF; Yahoo Inbox message opened, expected body/PDF visibly verified. No additional registration, QR or charge.
- [ ] Investigate the original automatic ticket-email path separately: its handoff was not recorded. Successful synchronous diagnostic delivery does not establish that the original queued mail was delivered; no further resend or application change authorised by this acceptance alone.
- [x] Record proof, failures, fixture cleanup and residual gates in the roadmap.

## 2. Payment sandbox acceptance — next recommended workstream

**Existing references:** `docs/superpowers/plans/2026-10-17-community-5-giving.md`, `docs/superpowers/plans/2026-10-18-community-6-member-benefits.md`, `app/Services/Giving/GivingRollout.php`, existing Giving/payment/benefit test suites.

- [ ] Confirm isolated sandbox provider/account, test recipient and test-mode scope with the owner. Never swap production credentials for a test or enable Purpledot online Giving implicitly.
- [ ] For both a church and a volunteer-group scenario with no dues, verify public voluntary contribution, receipt and native Money/inbox access. Test failed, abandoned and duplicate payment callbacks; check exact minor-unit amounts and ledger idempotency.
- [ ] Verify active-member ticket pricing and members-only admission, quote expiry and membership changing during the quote window; verify ordinary/nonmember prices and all existing identity checks.
- [ ] Sandbox-test Giving corrections/refunds and payout OTP/reconciliation where applicable, using the existing finance invariants. Capture references and balances without secrets/contact dumps.
- [ ] Present a readiness report and request a separate decision on Purpledot online activation. Historical contribution associations/cutover require their own bounded inventory, reconciliation and approved UTC boundary; do not alter old ledger entries.

**Deliverable:** sandbox acceptance evidence and an explicit online-readiness recommendation, not automatic production activation. Estimate: one focused test session; implementation only for demonstrated defects.

## 3. Library storage allowances and attachment acceptance

**Existing code:** `app/Services/Community/CommunityContentStorage.php`, `CommunityLibrary.php`, feature-limit/package configuration and `tests/Feature/Community/CommunityLibrary*`.

- [ ] Read effective `storage_bytes` allowances and actual usage first. Aggregate enforcement already exists; the unresolved issue is the chosen/configured allowance, not inventing another quota system.
- [ ] Propose per-plan allowances and how versions/other stored assets count; inventory existing usage without downloading private content. Owner reviews values and over-limit treatment before configuration changes.
- [ ] In an isolated test, write failing boundary tests for the selected allowance, concurrent uploads and retained editions; ensure a rejected upload cannot orphan an object or remove a committed file.
- [ ] Apply the approved missing-only limits using existing conventions; existing readable content remains readable when over allowance. Include ordinary user-facing capacity/error messaging if absent and cover it with focused tests.
- [ ] Verify a real synthetic PDF attachment upload, authenticated reading/download, expired access, foreign tenant denial and unsigned object denial. Do not weaken browser file permissions; arrange the normal owner file-selection step if required.

**Deliverable:** approved allowance policy and private attachment acceptance. Estimate: policy review plus one small implementation/review cycle if configuration/UI changes are needed.

## 4. Device, accessibility, offline and representative acceptance

**Existing references:** milestone 4/4b/5/6/7 acceptance sections, `resources/js/lib/offlineTicketStore*`, personal app tests.

- [ ] Test an actual phone at normal and 200% text/zoom, keyboard-only desktop, light/dark inputs, menu/dialog placement and focus return. Viewport emulation does not count as a physical-device check.
- [ ] Save only explicitly ticked ticket passes. Cold-open offline, then sign out/remove proof in another tab and verify clearing; messages, money, Library and service notes stay online-only.
- [ ] Test authorised representative versus personal-member contexts with local synthetic identities first. Verify no shared/reassigned phone grants membership history; email remains primary.
- [ ] Record pass/fail per scenario; implement only reproducible gaps with regression tests and a scoped release.

**Deliverable:** concise acceptance checklist with real-device checks marked honestly. Estimate: one assisted device session; fixes estimated only after reproduction.

## 5. Marketplace booking readiness — keep browsing available

**Existing references:** marketplace/venue controllers, availability service and staff-room privacy tests; daily reminder remains active.

- [ ] Confirm which merchants/venues genuinely participate, their permissions, availability, pricing, cancellation terms and notification recipients.
- [ ] In a sandbox, walk enquiry/booking, conflicting availability, approval/payment/failure/cancellation and merchant handling. Distinguish internal staff room bookings from public marketplace bookings.
- [ ] Confirm staff-only listings never leak into browsing or public booking paths. Existing listings remain visible as approved and homepage links open in a new tab; leave public booking settings unchanged.
- [ ] Present readiness evidence and obtain explicit approval before enabling or promoting bookings. Stop the reminder only when the owner confirms readiness or asks to stop.

**Deliverable:** readiness decision, not a marketing claim. Estimate depends on owner/vendor participation; code investigation is one focused session.

## 6. Public attendee opt-in profiles — separate product feature

**Existing code:** `resources/js/Pages/Public/Events/Show.jsx` Going count and registration/identity/privacy flows.

- [ ] Prepare a short consent/visibility design: verified attendee opt-in, optional display name/photo, preview, immediate withdrawal, retention and image validation. Public Going count must continue independently; never publish existing attendees automatically.
- [ ] Show a visual preview and obtain owner design approval before implementing headshot storage or public identity endpoints.
- [ ] Prepare the detailed test-first plan, covering consent absent/withdrawn, cancellation/refund, unverified identity, tenant/event isolation, image safety and public-cache invalidation. Reuse current event features and verification flows.

**Deliverable:** reviewed spec/preview, then separately approved implementation. Estimate: small design round; build estimate follows the agreed consent and image scope.

## 7. Editable Word marketing and proposal pack

**Existing sources:** live feature inventory, `docs/pricing/enterprise-dossier.md`, current marketing/use-case pages and roadmap. Existing editable pack located at `/Users/selase/.codex/visualizations/2026/10/05/01a10dd2-07d8-71a0-9249-e0dd9badc0a5/marketing-pack/MiConvener Marketing and Proposal Pack.docx`, with QA PDFs alongside it. Review/refresh that pack; do not regenerate it blindly.

- [x] Locate the existing Word output and QA PDFs in task artifacts.
- [ ] Review the existing pack for stale features/pricing before updating it; presence is not proof of current accuracy or delivery.
- [ ] Draft a core platform overview plus tailored proposals for churches, associations, volunteer/NGO groups, academic conferences, social/memorial events and venue/facility operators. Emphasise dues-independent contributions, service notes, library, communication and recurring gatherings where relevant.
- [ ] Distinguish available features from pilot/booking/payment readiness gates; validate pricing against current approved product sources. Never advertise unapproved bookings or opt-in headshots as ready.
- [ ] Use the Word document skills to produce editable branded DOCX files, render every document and visually review them. Provide clear placeholders for customer details and commercial terms; owner reviews before external distribution.

**Deliverable:** editable Word pack plus concise index. Estimate: one content/formatting cycle, then owner edits. No sending to prospective customers is authorised.

## 8. CI warning and runtime hygiene

- [ ] Identify the single risky PHP test using a named/JUnit result from the existing CI runner or a targeted reproduction; current compact parallel output does not name it. It appears in both preceding main run39596694266 and navigation run39670618926 (3646 passed,17406 assertions), so it is not introduced by the navigation fix.
- [ ] Correct only the demonstrated test defect, preserving assertions and production behaviour. Do not suppress risky tests to obtain a cleaner headline.
- [ ] Retain one full CI gate per release and targeted local tests. Record job duration and runner/setup time before proposing parallelism or infrastructure changes; the navigation PHP job completed in8m32s, not hours.

**Deliverable:** one identified/triaged warning and measured CI runtime. This tooling/test maintenance does not expand the product feature scope.

## Execution evidence — 2026-10-21

Navigation PR45 merged as `75ba65777c51b2864c997a584d622a19e08f57b2`; merged tree equals tested head `3faaf29e`. CI39670618926 succeeded:3646 PHP passed/17406 assertions,457 JS passed, quality/static/build passed. One risky PHP test also appeared on preceding main CI39596694266; its identity remains a maintenance follow-up, not silently reported as a fully clean suite. Cloud deployment `depl-a309928f-c46c-48b5-9097-d18ec070831f` succeeded20:38:09–20:40:17UTC,2m08s; direct currentDeploymentId/exact SHA confirmed. No manual duplicate deployment.

Live verified no-dues member: three content links now present beside Messages on one card; Library text read passed;25October09:00 service-note read passed and1November withheld the other occurrence's note; poll shows existing response-received and disabled submit, no duplicate response; inbox opens normally and browser console errors0. Keyboard Tab→Library→Enter passed. No staff-room eligibility was granted to the member.

Both synthetic documents/service notes restored to draft by `cexe-a309949c-5dcc-443b-bb2d-1505efeedc25`; poll remains closed, exactly1response retained. Pilot remains active with0charges. Synthetic free event remains published/private until actual confirmation/ticket issuance; ticket not issued. Owner approved exactly1fresh resend; queued `cexe-a30990a1-d8fa-4f27-9d68-ef5aae8117a7`, SMTP handoff20:32:49UTC. Owner still did not receive it. SES sender/DKIM verification successful; suppression lookup AccessDeniedException. No verification bypass, additional resend or provider-security change.

Queue read `cexe-a3099638-935b-41cd-87ae-fff65ec2c45b` at20:48:25UTC: drivercloud, default waiting0, failed jobs last7days0, latest failure null, failed pilot registration jobs0. Cloud managed queue available/unpaused, configured default worker and0–3replica autoscaling; readiness and successful earlier processing are evidenced, but these fields do not prove an executing PID at every instant. No restart or synthetic probe job sent.

Further diagnostic evidence: existing AWS read access ruled out account suppression. SES Delivery event confirms the diagnostic confirmation reached the exact Yahoo address on21October21:24:43UTC; mailbox placement and real confirmation remain unverified. No application, DNS, sender-default or worker change was justified by that evidence. The diagnostic sink was removed after capture; Yahoo sign-in handoff is pending.

The remaining plan is proposed for owner review. New documents are versioned on a separate planning branch, avoiding a production redeployment just for handover notes.

## Plan self-review

This plan separates existing acceptance from future implementation and preserves the original dues-independent Community design. Storage quota enforcement already exists and is not duplicated. Staff room booking work on main is preserved and receives navigation coverage; it does not imply marketplace readiness. Current CI must gate the release, while the missing ticket email and actual-device checks remain real blockers. No new feature or online-payment/booking switch is approved by merely merging this planning document.


## Mailbox resolution and checkout fix review — 2026-10-21

Yahoo system Spam contained all three confirmations. The latest diagnostic message was moved to Inbox using Not Spam and its actual confirmation link successfully verified the registration email. The stale member quote correctly required fresh verification; a separate frontend payload mismatch blocked that flow. The prepared fix removes registration-mode props from MemberBenefitVerification's VerifyPrompt so it sends/accepts the email payload expected by the member-verification endpoints. Server access and personal eligibility remain authoritative. Live ticket/QR acceptance remains incomplete until this fix is deployed and the native code flow finishes; synthetic event remains published/private for that bounded check.

### Self-Review Report

**Touchpoints updated:** checkout verification component, integration-style component regression tests, rebuilt tracked assets and this roadmap/handover. AGENTS/memory, seeders, roles/permissions, navigation, environment and configuration require no changes: no new convention, feature, endpoint, permission or dependency.

**UI Gap Analysis:** existing Verify my membership action remains discoverable; empty email has a labelled required field; email-only verification preserves personal membership gates. Existing busy, challenge, cooldown and failed-code states remain. No layout change beyond using the existing responsive email form. Keyboard labels and accent-ink button styles are reused. Physical-device acceptance remains outstanding.

**Solved:** removed the incorrect registration-mode props; replaced the test's mocked verification button with real send/confirm/recovery interactions. A fresh scoped reviewer found no correctness/security issues.

**Recommended for user decision:** deploy the narrow fix, then complete the designated zero-cost member verification and QR check; durable provider delivery-status recording remains a separate proposed follow-up.

**Not gaps:** an emailed confirmation and an expired member quote are independent gates; email verification alone cannot bypass current personal membership eligibility. Paid recovery is tested locally but no live payment is authorised or attempted.


**Checkout-fix validation:** regression RED reproduced the absent email field in both existing-registration recovery modes; GREEN: focused JS5/5, affected PHP64/64 with250assertions, lint, Pint and production build passed. Full JS run:457passed/1failed due to the existing badge-editor controls test exceeding its5second limit under parallel load; the unchanged BadgeDesignEditor file passed all11tests when rerun alone. This is not reported as a clean full-suite pass. Build retains its existing large-chunk warning. Diff whitespace check passed. Fresh scoped code review approved. No production code deployment has been performed for this fix; owner review/merge go-ahead and live native acceptance remain outstanding.


## Approved release and live acceptance — 2026-10-21

Owner approved PR46 merge/deploy. Merged commit `d86142e3f50f242f9226e648009b1c6ae7449bd4` has exactly the tested/reviewed head49abbf74 tree. GitHub merged immediately despite running CI after the auto-merge command; root acknowledged the sequencing error. PR CI39682798276 subsequently succeeded:3646PHP/17406assertions,1pre-existing riskyPHP;458JS across73files; quality/static/build passed. Recommend requiring the existing status checks as branch-protection gates, subject to owner's repository-settings approval. No branch protection was changed.

Automatic Cloud deployment `depl-a309afad-aa80-4ce6-b387-20f9b9c25a4e` succeeded21:59:34–22:02:22UTC,2m48s; monitor exit0, currentDeploymentId and exactSHA verified. No duplicate deploy. Native live recheck displayed the corrected labelled email field; one requested platform code arrived in Yahoo Inbox and was entered privately. It redirected to the real ticket workspace: confirmed, QR displayed, member benefit personal/nontransferable. Read `cexe-a309b1b0-83c3-43ae-aa07-cb6ad6cf1d6d` confirmed issued ticket, verified email and zero amounts. No QR token/code or private URL included in saved public proof; no physical scan/check-in claimed.

Cleanup write `cexe-a309b21b-4c64-4ae7-bc96-d1618573d587` restored only the labelled event to draft/public; subsequent read `cexe-a309b2a0-9929-4ef5-9816-194faf94103f` confirmed event restoration, issued ticket retained and document/service-note drafts. Post snapshot `cexe-a309b267-f1f0-46cb-b4ac-4d4cf8be023b` matches pre snapshot `cexe-a309af50-40d1-42c4-9f7a-64e340a9c94f`: rollout hash8959c291f6f8358f8053ed0ad69efaaa17e8e856ebe9ba00562fe4176dd68478,9tenants,active pilot0charges,poll response1. Queue read22:08:28UTC waiting0/failed7days0. Final ticket-email receipt remains unverified; confirmation and platform OTP delivery are proven. Screenshot artifact `purpledot-ticket-confirmed-live.png` crops out the QR/entry code. Original/Claude checkouts and all other tenant settings untouched.


Merged-main CI39683132566 subsequently completed successfully: both PHP and quality jobs passed. PR and merged-main CI are green on the identical release tree; no duplicate local full run was started. No new application changes after release; this final handover update is documentation only.


**Physical QR follow-up:** owner phone test21October22:37UTC passed first admission and duplicate detection. Server read `cexe-a309bdc5-6063-460b-92cf-65d5628f294c`: admitted22:37:22,already_in22:37:24,statuschecked_in,zeroamounts. Owner independently reported the same messages. Retain records as test history. Offline/audio/vibration/general mobile checks and final ticket-email delivery remain separate outstanding checks.


**Ticket delivery acceptance:** owner authorised one diagnostic email21October; `cexe-a309be99-2f3c-4eb1-b9e8-fd2a16eb477e` sent native EventRegistrationConfirmed once22:41:21UTC,onePDF,oneSentEmail record. Yahoo Inbox received it10:41PM; actual message opened and expected body/PDF verified. Diagnostic recipient delivery PASS. Original automatic queue handoff remains a distinct investigation; do not present a synchronous send as proof of that path. No application deploy, provider-setting change, payment or new registration.
