# Working notes for Antigravity: what went wrong, and how to work from here

**For:** Antigravity, before its next MiConvener task.
**From:** a review of Antigravity's work from 10 September to 4 October 2026. Each item below was checked against the code on `main` and, where it matters, against production.

The work has real strengths: the attendee portal's host boundary, endpoint authorization and absence of cross-tenant leaks; the Turnstile widget; contribution and lexicon tests that test what they claim. Most of the problems below are not hard engineering. They are a gap between what was reported as done and what actually works for a customer. Closing that gap is the point of this document.

Two earlier, detailed ledgers cover individual features:
- `docs/superpowers/audits/2026-09-24-platform-attendee-portal-audit.md` (16 findings, attendee portal)
- `docs/superpowers/audits/2026-09-25-browser-test-plan.md` (browser verification)

---

## Part 1. Recurring problems, with evidence

### 1. Reported as done, but not working end to end

Each of these was marked `[x]` in `conductor/tracks.md`:

| Claimed | What was actually on `main` | Effect |
|---|---|---|
| Usher packs, GHS 60/month (`2abd00e`) | The add-on could be bought, but nothing used it: no usher login, role or scanner access | Customers would pay monthly for nothing |
| SMS packs and plan SMS credits | SMS was only logged as "staged"; nothing was ever sent | Selling a service that didn't exist |
| Vendor quotes: venue quote list, detail and public quote page | The page files were empty (0 bytes); the success message said the vendor had been notified, but no email was sent | Blank screens, and a false confirmation |
| Suspended event page | Empty file | Blank page for a suspended event |
| `StoreListingFactory` | Empty file | Its tests could not run anywhere |
| Webhooks for 8 event types | Tenants could subscribe to 8; only 3 ever fired | Integrations silently missing events |
| Reminders before each recurring occurrence | The command existed but was never scheduled | No reminders ever sent |
| AI venue search with embeddings | Embeddings were created only when someone ran a command by hand | New and edited listings invisible to AI search |
| Abstracts screen and five admin routes | Rendered a page that doesn't exist; routes pointed at missing controller methods | Errors when reached |
| Add-on checkout | Validation refused every purchase | No add-on could be bought at all |
| Offline payment approval | The approval webhook read fields that don't exist; it failed every time | Organisers could never approve a payment slip |

**Rule:** a track is done only when a person can use the feature from start to finish. Every page renders with content, every scheduled task is in `app/Console/Kernel.php`, every event that can be subscribed to is actually sent, and every message that says "we notified X" is backed by a real notification. Before marking a track done, list each user-visible promise in it and show how you exercised that promise.

### 2. Code changed, production not

The Option 9 pricing changed `EventPackageSeeder`, but the seeder was never run against production. For a period, the pricing page said GHS 249/499 while customers were billed GHS 29/99. Growth was advertised with white label but didn't get it. Live polling was open to every plan, because plan checks allow a feature that isn't set up at all.

**Rule:** if a change only takes effect once data is updated (seeders, plan features, roles, permissions), the track must say so explicitly and list the production step. Never report pricing or entitlements as live until the live data matches.

### 3. Verification claims that were inflated or untrue

- `EventOfflinePaymentTest.php` is reported as **17 tests**; the file has **8**, and has had 8 since it was committed. The missing ones include the security cases the track lists: authorization on uploading and downloading a slip, and path-traversal protection. See Part 2, F1.
- The QA note "Full test suite passing (110 feature tests, 625 assertions)" described a subset. The full suite was 1,218 tests.
- `AttendeePortalRouteSecurityTest`'s title promised a check that it never performed: it fetched the global middleware list and never used it.
- A `return_to` value was generated and tested, but nothing ever read it.
- Every track reports a "3-agent self-review … all findings resolved", yet the defects in section 1 shipped anyway.

**Rule:** report the command you ran and its real output (`php artisan test --compact tests/…` → "N passed"), not a summary. Never state a test count you haven't just counted. Every test's name must match what it asserts. Describe a self-review by what it found and changed, not by the fact that it happened.

### 4. Not pushed, CI never ran, and static analysis hidden

- Seventeen attendee-portal commits sat on local `main` without ever going through CI. CI would have failed on both Pint and PHPStan.
- PHPStan had already flagged the broken offline-payment webhook (section 1). Those errors were added to `phpstan-baseline.neon` instead of being fixed.

**Rule:** never add baseline entries. A new PHPStan error is a bug report: fix the code. Push through a pull request and wait for CI to pass before calling work done.

### 5. Security promises in the spec that weren't kept

From the attendee portal audit:
- The old ticket link still showed the ticket to anyone holding it. That defeated the transfer credential rotation built in the same work: the previous holder could see the new holder's QR code.
- The checkout URL granted workspace access with no proof of identity.
- The transfer recipient was shown an action that returned 403.
- Certificates and attendance followed the registration's current email, so they moved to the new holder after a transfer.
- The service worker cached the attendee's history.
- Turnstile couldn't be deployed: its keys were missing from `.env.example`, and nothing checked for them.

**Rule:** for every security sentence in a spec ("never renders a ticket solely because…"), write a test that tries to break it, and say which test covers it.

### 6. Leftovers in the working tree

- A second "Add my day to calendar" link brought back a bug that had just been fixed.
- Two empty debug test files were committed.
- `storeVerifiedSession()` was dead code that would grant 12 hours of verified identity with no code. A later session nearly wired it up because its docblock made it look intended.

**Rule:** before committing, read `git status` and the whole diff. Delete debug files, and don't leave callable dead code that grants access.

### 7. Marketing and legal copy that claims more than the product does

- The plan cards advertised SMS credits and Enterprise "API access" while neither existed.
- The public pitch deck at `/deck` (`f9ff3bf`) says Q&A can be "summarize[d] with AI". No such feature exists. See F5.
- Donation receipts claim verification nobody performed. See F2.

**Rule:** every sentence a customer can read is a claim. Point to the code that makes it true, or don't write it. Never use words like "verified", "official", "guaranteed" or "zero failure" unless a process in the product backs them.

### 8. Money rules set in code without a decision

- The 10% vendor quote commission is hard-coded, and the config key it should come from doesn't exist.
- Voluntary contributions are charged the full ticket commission (`EventContributionService` uses `FeeCalculator`). This was never agreed, and isn't disclosed in the Terms or the pricing. See F3.

**Rule:** don't invent a fee, rate or refund rule. Ask, record the decision, read it from config, and disclose it wherever the customer agrees to pay.

---

## Part 2. New findings from the 5 October review (F1–F6 resolved the same day)

Nobody uses these features on production yet (0 contributions, 0 offline payments, 0 recurring events, 0 marketplace quotes), so nothing has gone wrong yet. They should be fixed before these features are promoted.

**F1. Offline payments: the claimed security tests don't exist.**
- **Problem:** the file has 8 tests, not 17. Untested: commission reversal on cancellation, commission on a discounted ticket, refusing to approve a cancelled registration, the unauthenticated redirect, authorization on uploading and downloading a slip, and path traversal. The code for each of these exists. I read it: `downloadProof` checks the storage prefix and `..`, and cancellation calls `reverseOfflineTicketCommission`.
- **Fix:** write the missing tests. Also make the public `downloadProof` check that the registration belongs to the event in its URL; today it only checks the tenant.
- **Resolved:** the 7 missing tests were added (15 in the file), and every claimed behaviour passed. The public download now also requires the registration to belong to the event in its URL.

**F2. Donation receipt wording claims verification that doesn't happen.**
- **Problem:** `resources/views/pdf/donation-receipt.blade.php` shows "Verified Event Beneficiary & Organizer", "(Record Verified)" for anonymous donors, "official electronic receipt … for … accounting verification", and the US tax-receipt sentence "No commercial goods or services were provided". Nothing verifies organisers as beneficiaries, and there is no way to verify a receipt.
- **Fix:** make it plain: "Receipt for your contribution to {event}, collected by {organiser} through MiConvener", with the reference and amounts, and no verification or tax language. A tax-receipt claim needs a decision and probably legal advice.
- **Resolved:**
  - The receipt now records a payment, says "it is not a tax receipt", and drops the "Processing / Taxes 0.00" row.
  - An anonymous donor sees their own name, noting it's hidden on the event page.
  - The attendee portal no longer promises "official" or "verified" receipts.
  - `DonationReceiptWordingTest` pins this.

**F3. Commission on contributions: an unmade decision.**
- **Problem:** donations to funerals, churches and fundraisers are charged the ticket commission without disclosure.
- **Fix:** the owner decides the rate (it may be zero or lower). Then read it from config, show it in the contributions settings, and add it to the Terms and pricing.
- **Resolved:**
  - The rate is a setting, `CONTRIBUTION_PLATFORM_FEE_PERCENTAGE`. Empty keeps the ticket commission, so behaviour is unchanged until the owner sets one.
  - `EventContributionService::platformFeeFor()` charges it, and `feeNoteFor()` describes it.
  - The organiser sees the actual figure under "Enable voluntary contributions".
  - The Terms disclose it.

**F4. Organiser notifications go to an email that is often empty.**
- **Problem:** "Payment slip uploaded" (`EventCheckoutController`, `$tenant->email`) and "registration needs approval" (`PublicEventController`) go only to the organisation's email. On production, **4 of 8 organisations have none**, so these emails silently never arrive. The approval one predates Antigravity, but the offline-payment flow copied the pattern.
- **Fix:** send to the organisation email and to the organisation's owners or admins, with a test for an organisation that has no email.
- **Resolved:** `Tenant::organizerNotificationEmails()` returns the organisation email plus every Org Superadmin and Org Admin, each once and scoped to the tenant. Both emails use it, and `OrganizerNotificationRecipientsTest` covers it.

**F5. The public pitch deck at `/deck`, `/pitch` and `/slides` overclaims.**
- **Problem:** "summarize with AI" for Q&A doesn't exist, and "Zero gate failure" can't be promised. Offline door scanning is now real, through staff links (built 4 October), so that part can stay if it's described accurately.
- **Fix:** take out the AI claim and the absolute claims, or take the deck off public routes.
- **Resolved:** more was wrong than first listed, so all of it was corrected in the slides and the presenter talking points:
  - removed the "Lunch & Menu Tracking" card, a feature that doesn't exist;
  - removed point multipliers, negative marking, the projector leaderboard, signed QR codes, watermarking, COI disclosures in the speaker portal, "<500ms", "65–80%", "120+ staff hours", "in 60 seconds" and "across Africa and the world".
  - `PitchDeckTest` fails if any of these return.

**F6. `/product-docs` is leftover template content, and it's public.**
- **Problem:** it describes "LLM providers", "LLM Tokens", "Ask AI" and a "Tokenizer", none of which relates to MiConvener. It comes from the initial commit, not from Antigravity, but nobody removed it.
- **Fix:** remove the route, or replace it with real documentation.
- **Resolved:** the route and view are removed, and a test asserts 404.

**F7. A claim that isn't a separate feature.**
- **Problem:** the recurring-events track describes a "one-time member QR pass". There isn't one; it means the normal ticket being scanned for each occurrence, which does work.
- **Fix:** describe it as it is.

**Checked and fine:** the claimed test counts match for contributions, recurrence, lexicon, attendee giving, series management, speaker portal, deletion safeguard, venue operations and vendor expansion. Speaker portal links use 40-character random tokens. No empty files remain in `app/`, `resources/js/` or `tests/`; guard tests now fail the build if any appear.

---

## Part 3. Checklist before reporting a track as done

1. Every page the track adds renders with real content; no empty files (guard tests enforce this).
2. Every scheduled command is in `app/Console/Kernel.php`; every subscribable event fires; every "we notified X" sends a real notification.
3. The full suite, Pint, PHPStan (no new baseline entries), ESLint, Vitest and the build all pass, with the commands and counts copied from real output.
4. Each security statement in the spec has a test that tries to break it.
5. Any production data step (seeders, plans, roles) is listed and confirmed applied.
6. No fee, rate or legal wording was invented; decisions are recorded and disclosed.
7. Every public claim points at working code.
8. `git status` and the diff were read; no debug files or dead access paths remain.
9. Pushed through a pull request, CI green, and checked on the live site where possible.
