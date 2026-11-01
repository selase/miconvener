# S2b Venue Payments: Sandbox and Browser Acceptance Plan

**Status:** prepared 31 October 2026 for the owner's review. S2b (#60-#66) is merged and deployed with venue requests and payments **off everywhere**. Running this plan changes nothing in production.

**Purpose:** prove, in an isolated local environment with Paystack **test mode**, that the complete buyer, host and finance journeys behave as the design says, before any real venue is switched on. Passing this plan is one of the activation gates; the owner's merchant and policy approvals (section 9) are the others.

## 0. Ground rules

- **Local only.** A dedicated checkout, database, Herd site and Paystack **test** keys. No production database, key, merchant account, flag, seeder or command is touched. The production flag stays off (`venue:enable-payments` is only ever run against the local acceptance database).
- **Test-mode money only.** Never put a live Paystack key in the acceptance environment. Keys live in the acceptance `.env`, never in the repo, a log or a screenshot.
- **Locally captured email.** `MAIL_MAILER=log` (read `storage/logs`) or Herd's mail catcher. No real recipient is ever emailed. Use `@acceptance.test` addresses.
- **Label every piece of evidence** as one of: **provider** (a real Paystack test-mode response or dashboard record), **simulated** (an HTTP fake, a locally signed webhook, a forced mail failure, a manually edited clock) or **database** (a ledger or table read). Simulated evidence never stands in for provider evidence on a step marked *provider required*.
- **Stop conditions.** Stop and record a defect if: a ledger entry is unbalanced, money appears paid or confirmed that the provider did not capture, a buyer sees a commission or fee field, a user without the permission completes an action, or any step needs a production resource. Do not "fix and continue" inside the run: fix on a branch, then restart the affected section.

## 1. Prerequisites

| # | Item | Notes |
|---|------|-------|
| P1 | Acceptance checkout of `main` at the released commit (`d9f1a994` or later), on its own branch/worktree | Record the commit under test in the evidence log. |
| P2 | Separate database `miconvener_acceptance` (and `DB_DATABASE` override); `migrate:fresh`, then the **role seeder first, then** `db:seed --class=PermissionsSeeder` (permissions are granted to roles that must already exist) | Never reuse `miconvener`, `miconvener_testing*` or a shared browsing DB. |
| P3 | Herd site for the checkout, e.g. `herd link acceptance` giving `acceptance.test` with wildcard tenant subdomains; `APP_URL=http://acceptance.test`, `SESSION_DOMAIN=.acceptance.test` | Local Herd config only. |
| P4 | Paystack **test** secret/public keys in `PAYSTACK_SECRET_KEY` (settlement shares it) | Test-mode dashboard open to inspect transactions, refunds and transfers. |
| P5 | Webhook delivery path | A public tunnel to `/webhooks/paystack` (Herd Share or similar) set as the test account's webhook URL. If no tunnel is available, webhooks are **simulated** by posting a payload signed with the test secret (HMAC-SHA512 of the raw body in `x-paystack-signature`); the callback and `php artisan commerce:reconcile` still use the real provider. Record which path was used. |
| P6 | Queue and scheduler | `QUEUE_CONNECTION=database` with a worker running, and `php artisan schedule:work`, so quote emails, retries and reconciliation run as in production. |
| P7 | **Reservation agreement approval** | Approve Acceptance Hall's venue (reservation) terms on the superadmin agreements screen (`admin.store-agreements`, scope "Venue bookings"), as the application superadmin. This path is built in PR #68; it is merged (`ef9a42bc`), so the real screen is used. `venue:enable-payments` needs it. |
| P8 | Evidence folder | One folder per run: `commit.txt`, `mail/`, `ledger/` (SQL dumps), `screens/`, `provider/` (dashboard screenshots with keys cropped), `log.md`. |

### Test organisations (all local)

| Org | Settlement | Venue requests | Venue payments | Purpose |
|-----|-----------|----------------|----------------|---------|
| **Acceptance Hall** | platform default | on (`venue:enable-requests`) | on (`venue:enable-payments`) once the agreement exists | the venue under test |
| **Own Gateway Hall** | own gateway | on | refused by the command | proves the prerequisite checks |
| **Bare Hall** | platform default | on | no agreement | proves the command refuses |
| Users | Hall owner (Org Superadmin, all three venue-money permissions), Hall host-only (Org Admin, `manage venue` only), Hall finance-only (custom role: refunds + reconcile, no settlements) | proves the permission split |

Buyers: `buyer1@acceptance.test`, `buyer2@acceptance.test`, `rival@acceptance.test`.

## 2. Fixed numbers for the run

Use the same amounts as the automated tests so results can be compared: hire GHS 1,000.00, extras GHS 50.00, refundable security GHS 200.00, deposit GHS 300.00, balance GHS 750.00. Commission is the agreed 3% on hire plus extras only (contract fee GHS 31.50, deposit allocation GHS 9.00, balance allocation GHS 22.50); security carries none. The tenant bears Paystack's processing fees; record the **actual** test-mode fee reported for each capture and compute the expected ledger from it:

- Rental capture: Dr gateway clearing (amount - fee); Cr organiser payable (amount - fee - allocated commission); Cr platform revenue (allocated commission).
- Security capture: Dr gateway clearing (amount - fee); Dr gateway fees (fee); Cr refundable security (full amount). Payable and revenue unchanged.
- Rental refund: Dr organiser payable (amount - commission - fee, when the platform funds the processing fee: the fee is subtracted from the seller debit, not added); Dr platform revenue (commission); Cr gateway clearing (amount).
- Security return: Dr refundable security (amount); Dr organiser payable (fee); Cr gateway fees (fee); Cr gateway clearing (amount).

For every step that moves money, dump the ledger transaction and its entries and assert debits equal credits in integer pesewas.

## 3. Journey A: the complete happy path (provider required)

Run once end to end, in the browser, with captured email.

| # | Actor | Action | Expected | Evidence |
|---|-------|--------|----------|----------|
| A1 | Buyer1 | Open the venue page, submit an approval-first request | Request page requires email verification; a code arrives in the mail log; after verifying, the request shows "awaiting approval", no price committed | screens, mail |
| A2 | Host | Approve the request with a 24-hour hold | Dates held; buyer receives the hold email (no payment link); calendar shows a tentative hold | screens, mail |
| A3 | Host | Send the final quote (deposit plan, security, balance and security due dates, cancellation terms) | Quote saved; a delivery record is created and sent; the buyer email shows amounts and deadlines in the venue's timezone and **no commission**; host panel shows "sent" | mail, DB |
| A4 | Buyer1 | Follow the email link, verify, review, accept the exact terms | Acceptance recorded once; three charge orders created (deposit, balance, security) and listed (deposit due, balance not yet payable, security not yet payable); nothing charged | screens, DB |
| A5 | Buyer1 | Pay the deposit with a Paystack **test card** (success card, test PIN/OTP per Paystack's current test documentation) | Redirect to the hosted page, return via callback; booking becomes confirmed; deposit paid; receipt available; booking page shows balance and security as due | provider, screens |
| A6 | Database | Inspect | Of the three charge orders only the deposit order is paid, with one attempt, one observation, one `commerce_sale` ledger transaction (balanced), one held settlement reserve, one receipt, one confirmation email if configured | DB |
| A7 | Provider | Webhook for the same payment (real tunnel or simulated) | No second sale, ledger entry or reserve; reconcile output unchanged | provider/simulated, DB |
| A8 | Buyer1 | Pay the balance, then the security | Booking fully paid; security held as a refundable liability, no commission, no change to payable; two more receipts | provider, DB |
| A9 | Host | After the event end (move the **local** clock or use a booking in the past), mark the event complete | Allowed only with the settlement permission; reserves released; completion recorded once | DB |
| A10 | Finance | Return the security in full **before** any payout | Refund funded from the venue's available earnings (the venue covers the processing fee, so earnings must retain at least that fee); provider confirms; liability released; booking and calendar unchanged; no stock movement | provider, DB |
| A11 | Owner | Reserve a payout of the available amount **after** the security return, send it, and finalise with the transfer OTP (if the security return has not been done, leave the security processing fee out of the payout) | Reserve reduces available; payout needs the OTP; completion posts the payout once; security cash and any still-held reserves never fund it | provider, DB |

**Pass:** every expected result, balanced ledger at each step, no console errors, mail log contains exactly the expected messages.

## 4. Journey B: failure and edge cases

Each case states how to produce it and whether it is **provider** or **simulated**.

| # | Case | How | Expected |
|---|------|-----|----------|
| B1 | Declined card | Provider test decline card | Attempt fails; booking stays on hold; no ledger; buyer can retry on the same page without a duplicate attempt |
| B2 | Abandoned checkout | Close the hosted page, return to the booking page | Attempt stays ready/continuable; paying later works; no second order |
| B3 | Unknown outcome (**simulated**) | Make checkout initialisation fail (provider unreachable) | Attempt marked unknown, shown as "being prepared"; **no second attempt can be created until reconciled**; `commerce:reconcile` resolves it |
| B4 | Wrong email / signed out | Open the booking or accept with another email | No access, no acceptance, no data leak |
| B5 | Forged or stale terms | Edit the accept request hash; accept after the quote expires | Refused with a message; nothing recorded |
| B6 | Quote replaced and withdrawn | Host sends v2, then withdraws it with a reason | Buyer email for v2, then a withdrawal email with the reason; buyer page shows withdrawn; no payments offered |
| B7a | Overdue balance | Accept a quote, pay the deposit, then move the local clock past the balance due date | Balance shown overdue and still payable |
| B7b | Unaccepted quote expires | Send a quote and leave it unaccepted; move the local clock past its expiry | Expired quote offers no payment and cannot be accepted |
| B8 | Late payment, dates free | Pay at the hosted page, but let the hold lapse before anything confirms it (**simulated** by delaying the callback, the webhook **and** `commerce:reconcile`; delaying only reconciliation leaves the other two to confirm it) | Provider paid time decides timeliness: confirms if the slot is still free |
| B9 | Late payment, dates taken | Rival confirmed the dates; payment captured | Held for review, no occupancy, no sale; finance sees it and can refund it in full |
| B10 | Extra capture | Pay twice for the same charge (second via a second attempt) | Second capture held for review and refunded individually; the valid payment and booking untouched |
| B11 | Wrong amount/account/reference (**simulated** evidence) | Feed mismatched verification data in a local test harness | Held for review, never posted as a sale |
| B12 | Duplicate callback and webhook | Replay both several times | Exactly one sale, one reserve, one receipt |
| B13 | Legacy conflict | Create an older-path booking whose payment arrives after its dates were taken | Conflict listed on the finance desk; verify with the platform account; refund in full; replaying the old callback never confirms it; money the old path already posted stays blocked |
| B14 | Permission split | Repeat refund, reconcile and complete as host-only and finance-only users | Each refused (403/no button) unless holding exactly that permission; a store-finance user cannot reach venue charges |
| B15 | Organisation prerequisites | Run `venue:enable-payments` for Own Gateway Hall and Bare Hall | Refused with the missing prerequisite; nothing enabled |
| B16 | Flag off after money taken | Turn payments off mid-way | New checkouts refused; captured money still recorded and refundable |
| B17 | Mail failure (**simulated**) | Point SMTP at a closed port, send a quote | Quote committed; notice retries at 5, 30 and 180 minutes (up to four attempts), then shows failed to the host. Once a notice is terminally failed the retry command does **not** revive it: the host sees the failure and must send the quote again. Restoring SMTP *before* the last attempt lets the retry succeed |
| B18a | Worker stopped (**simulated**) | Stop the queue worker while issuing a quote | Quote committed; the job waits on the queue and is delivered when the worker returns; a notice stuck `sending` for 15 minutes is recovered by `venue:deliver-quote-notices` |
| B18b | Queue submission fails (**simulated**) | Make the queue connection reject the job (e.g. point it at an unreachable backend) while issuing a quote | Quote committed; notice stays `queued`; the scheduled `venue:deliver-quote-notices` sweep submits it once the queue is back |

## 5. Journey C: refund funding

| # | Case | Expected |
|---|------|----------|
| C1 | Refund a rental charge when the venue's available earnings cannot cover it (only this booking exists, its earnings are reserved) | Status `needs_funding`; **nothing is sent to Paystack** (provider shows no refund); desk explains it waits |
| C2 | Add a second, funded booking's earnings, then refresh the refund | Refund submitted once under the original reference; provider shows one refund; desk shows pending |
| C3 | Provider completes the refund; refresh twice | One `commerce_refund` ledger transaction; order refunded; commission returned from the stored allocation; booking cancelled only when no rental money remains |
| C4 | Provider refund submission fails (**simulated**) | Refund `unknown`, reconcilable under the same reference, never resent blindly |
| C5 | Refund requested after the earnings were released | Refused with the platform-administrator message |
| C6 | Security return when the venue cannot cover the processing fee | Waits as `needs_funding` until it can |

## 6. Journey D: payout OTP

| # | Case | Expected |
|---|------|----------|
| D1 | Reserve a payout larger than available (including held reserves and security) | Refused |
| D2 | Reserve within available, send it | Parked as awaiting OTP; sending again does not resend |
| D3 | Finalise with a wrong OTP, then the right one | Wrong code refused without duplicating the transfer; right code proceeds once |
| D4 | Verify the transfer, reconcile twice | One payout ledger transaction; transfer cost recognised only on the verified release |
| D5 | Race a security return and a payout (two browser sessions, near-simultaneous) | Together they never spend more than the available balance; no duplicate refund or transfer |

Paystack test-mode transfers need a test balance and a verifiable recipient. If creating a test recipient or transfer is not possible, record that as a blocker; the owner decides how to proceed. Hosted payment acceptance is manual (see section 11).

## 7. Browser matrix

Drive the real application with headless Chrome (the Playwright tool was not available; use the globally installed Puppeteer) and also walk each journey by hand once.

- **Surfaces:** buyer request page and booking page (quote, accept, pay, receipt, every charge state); host inquiry page (quote form, withdraw, email status, complete); finance desk (conflicts, held captures, refunds, empty state); console navigation ("Venue money" appears only with a venue-money permission).
- **Viewports:** 1280x900 and 390x844. **Themes:** light and dark.
- **Keyboard:** Tab through every flow in order; every control reachable, named and visibly focused; form errors announced and focus moved; modal-free flows so no trap.
- **States to see:** priced and price-on-request listings; full payment, deposit plus balance, security; wrong email; stale or expired quote; changed policy mid-flow; withdrawn quote; email failed; staff exception (legacy conflict); a payment under review; refund pending, needs funding and refunded.
- **Checks per screen:** no horizontal overflow, no console errors, no failed requests other than intended refusals, contrast of status colours, loading and error messages appear and take focus, a stale tab refreshes on focus.
- **Pre-activation requirement:** white text on the accent colour is 4.31:1 (the shared primary button token). Fix the token (to at least 4.5:1) and re-check the buttons before any venue is enabled.

## 8. Pass criteria and reporting

- **Pass** needs: every Journey A step; every B/C/D case behaving as stated (or explicitly recorded as simulated with its covering automated test); a balanced ledger throughout; no stop condition hit; the browser matrix with no open P1 or P2 defect.
- The report states, for each journey, the commit tested, what was provider evidence versus simulated, the defects found and their fixes (each fix its own reviewed PR), and the cleanup performed.
- **Cleanup:** drop only the acceptance database and unlink the Herd site; delete no other database or worktree (existing worktree and test-database cleanup is deferred until this plan finishes).

## 9. Approvals

### Decisions already made (reused, not re-opened)

- 3% default venue commission, adjustable per organisation by application superadmin only; commission on hire and extras only; cap applied once; security carries none.
- Deposit chosen explicitly by the host (an integer amount) with a balance due date; security held separately and returned in full; no damage deductions in this release.
- Full-capture refunds only; the venue bears Paystack's processing and refund costs; refunds are refused after earnings are released.
- Earnings are released only after the host marks the event complete; payouts stay manual with the per-transfer OTP on; no automatic payout.
- Platform collection first (the supported platform Paystack account); venue payments activated per organisation, off by default.
- Ambiguous older captures are refund-only; money the older path already posted stays blocked until a category-aware adapter exists.
- Purpledot merchandise and its agreement are unaffected.

### Outstanding, genuinely merchant-specific (needed before a real venue is enabled)

1. **Pilot venue and legal entity**, and who signs the agreement.
2. **That venue's reservation agreement values** if they differ from the defaults: rate (default 3%), any cap, `holdback_pesewas`, receipt and support wording, support contact.
3. **The venue's own cancellation and refund terms and its security-deposit policy** (the exact text buyers will see).
4. **The venue's payout recipient** (mobile money or bank) and who may release payouts and enter the OTP.
5. **Named acceptance recipients:** the test emails, the Paystack test account owner and where the test keys are kept.
6. **Go-live sign-off:** who flips `venue:enable-payments` for the first venue, with which first scope (deposit-only, or deposit, balance and security), and the date.

### Engineering gap found while preparing this plan (fixed in PR #68)

- Nothing in the application could approve a reservation-scope commercial agreement: the superadmin agreements screen only created purchase-scope agreements, so `venue:enable-payments` could never succeed for a real organisation. PR #68 lets the application superadmin choose the scope on that screen (venue terms must take the commission from the venue's side; approving one scope never touches the other) and tests that `venue:enable-payments` succeeds once the venue terms are approved there. #68 is merged and deployed.

## 10. Deployment verification already done (read-only)

The documented deploy command (`php artisan migrate --force && php artisan db:seed --class=PermissionsSeeder --force`, recorded in `AGENTS.md` and confirmed as the production environment's configured deploy command) ran as part of Cloud deployment `depl-a31d2c2f-e5f8-42e8-9134-58b38b78daf2` for `d9f1a994`, which finished with status `deployment.succeeded`. The Cloud CLI does not expose command output, so success of the deploy command is inferred from the successful deployment. Because the seeder grants a new permission to the built-in roles only on the run that creates it, the three venue-money permissions should have reached Superadmin and Org Superadmin on that deployment. A successful deployment does not prove the grants, so **verification gate:** before activation, confirm in production (read-only) that the three permission rows exist and which roles hold them; run the seeder only if they do not, with the owner's approval.

## 11. Run log: first local run (31 October 2026)

**Commit under test:** `ef9a42bc` (main after #68). This is a partial run: the browser matrix is partially completed and A5-A11 are pending. **Environment:** a separate checkout and database `miconvener_acceptance`; the app served locally with a port-mapped browser (`*.acceptance.localhost`); Paystack **test** keys; email to the log; queue worker running; headless Chrome driving the real pages. Test buyers use `@example.org` addresses because Paystack rejects `.test` emails. The production flags were not touched.

| Step | Result | Evidence |
|------|--------|----------|
| P7 venue terms on the real superadmin screen | **Pass**: surcharge refused with its message; approval shows under "venue bookings" only; 390px no overflow; select labelled | database, screenshots (`/private/tmp/claude-501/-Users-selase-Sites-miconvener/bf63d587-984d-4a41-9530-0162a26f05ea/scratchpad/acc/agreements-approved-desktop.png`, `agreements-approved-phone.png`) |
| B15 organisation prerequisites | **Pass**: own-gateway (platform account + agreement) and no-agreement (agreement) refused; the venue under test enabled, disabled and re-enabled | database |
| A1 request with emailed verification code | **Pass** | mail log |
| A2 approve dates, A3 final quote | **Pass**: hold email; quote email carries amounts, deadlines and cancellation terms and **no commission**; host sees its own commission (GHS 31.50 on GHS 1,050.00) and "the buyer was sent the quote email" | mail log; screenshots in `/private/tmp/claude-501/-Users-selase-Sites-miconvener/bf63d587-984d-4a41-9530-0162a26f05ea/scratchpad/acc/` (`a1-*`, `a2-*`, `a3-*`) |
| A4 buyer accepts the exact terms | **Pass**: three payments listed; nothing charged | database, screenshots |
| A5 hosted payment | **Initialisation passed with the provider** (a real test checkout URL was issued). **The hosted page sits behind a Cloudflare bot check, so the card payment cannot be automated**; **A5-A11 (deposit, balance, security, refund, payout, OTP) are pending**: a person completes them at the hosted page | provider (initialisation only) |
| F1 definitive rejection at initialisation (not an uncertain-outcome B3 test) | **Observed with the real provider**: Paystack rejected an invalid email (HTTP 400); the attempt was held as unknown, the buyer told to wait, and `commerce:reconcile` reported it unresolved. **Finding F1** below. B3 proper (timeout or unreachable provider) was not run | provider, database |
| B4 wrong email, signed out | **Pass** (404; redirected to verify) | HTTP |
| B5 forged terms hash | **Pass**: no acceptance recorded (0 accepted decisions) | database |
| B6 withdraw with reason | **Pass**: buyer sees the reason and no payment is offered; withdrawal email sent; the host can issue a replacement | mail log, screenshots |
| B14 permission split | **Pass**: host-only user gets 403 on the venue money desk; the owner sees it with its empty states | HTTP, screenshots |
| Browser matrix (real data) | **Partially completed**: buyer (accepted, withdrawn), host inquiry and finance desk at desktop and 390px, light and dark: no horizontal overflow, every focus stop named and visible. Not yet covered: remaining states in section 7 and the keyboard walk-through by hand. Known: white on the accent colour is 4.31:1 (shared token) | screenshots (`/private/tmp/claude-501/-Users-selase-Sites-miconvener/bf63d587-984d-4a41-9530-0162a26f05ea/scratchpad/shots/*.png`: buyer, host, finance by viewport and theme) |
| B1, B2, B7-B13, B16-B18, C, D | **Not run in the browser**; covered by the automated feature and real-worker tests named in the plan; B17/B18 (mail and queue outage) are covered by `VenueQuoteNoticesTest` | automated, simulated |

**Evidence location:** screenshots and driver scripts are local scratch files under `/private/tmp/claude-501/-Users-selase-Sites-miconvener/bf63d587-984d-4a41-9530-0162a26f05ea/scratchpad` (not committed); the mail log and the `miconvener_acceptance` database hold the rest. Anyone confirming independently should re-run the listed steps against `ef9a42bc`.

### Findings from this run

- **F1 (decided: fix first, separate PR):** a definitive provider rejection at checkout initialisation (an HTTP 400 such as "Invalid Email Address Passed") is stored as an *unknown* attempt, so the buyer cannot retry until staff resolve it. The fix goes in its own PR with merchandise regression tests: recognised definitive rejections may permit a retry; timeouts, malformed responses and duplicate-reference ambiguity stay unresolved. Not every 4xx proves nothing was created. Final financial acceptance resumes after it merges.
- **F2 (fixed in #70):** the buyer's hold panel said payment was unavailable and the price indicative while a "Pay now" button was shown.
- **F3 (known):** accent-colour button text contrast is 4.31:1.

### Still needed before any activation

1. F1 fixed and merged (separate PR), then the failed-initialisation retry re-checked.
2. A person completes the Paystack **test-card** payments (deposit, balance, security) at the hosted page, then the test-mode refund and payout/OTP steps (A5-A11, D), recorded with provider evidence. Simulated or automated coverage is not a substitute.
3. Contrast of the accent button token fixed to at least 4.5:1.
4. The rest of the browser matrix and the by-hand keyboard walk-through (section 7).
5. The permission verification gate in section 10.
6. The merchant-specific approvals in section 9, and the owner's explicit go-live approval (who flips `venue:enable-payments`, first scope, date).
