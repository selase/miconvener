# S1b sandbox acceptance and launch gate

Status: **partially executed with owner approval, 2026-10-27**. S1b/S1c and the camera correction are deployed; production checkout remains disabled. The historical approval prerequisites below remain the general launch policy. The owner separately authorised the bounded live purchases, free camera retest and current isolated finance acceptance; none supplies a real trading agreement or general activation approval.

## Approvals before provider testing

Name an isolated non-production organisation and accountable operator; use only a separately approved Paystack test account and its test recipient/account details. Confirm permitted test email/phone recipients, init/charge/refund/transfer/OTP actions and budget (test funds only). Do not replace production credentials, change a real tenant's settlement mode, or infer permission from the Community Purpledot instruction. Own-gateway and Stripe remain outside this release.

Approve explicit test agreement values, including a zero waiver where intended: percentage/cap/bearer, original gateway-fee funding on refunds, reserve/holdback, manual payout release, collection/refund/support terms, receipt/tax presentation and who resolves uncertain outcomes. The old marketplace 10% and old venue balances are not store terms or store proceeds.

Keep catalog/checkout off outside that named environment. Test private media storage and verified sender/reply-to configuration. Verify a worker and scheduler are actually running; successful dispatch alone is not proof of delivery. Record safe operation IDs and ledger totals, never secret keys, OTPs, private checkout URLs, confirmation links or reusable gateway authorizations.

## Acceptance sequence

1. Use a synthetic church/volunteer organisation without Community membership or dues. Public items require native verified email only; private items require an active self/email member. Phone, representative and follower proof must not grant purchases/history. Confirm expired proof clears protected screens.
2. Verify quote amounts/options/terms and the ten-minute deadline; refresh with the same/different request key, edit the selection, contend for the last unit from two browser sessions, and attempt an inventory correction while held. No oversell, renewed deadline or changed quoted price. Test product takedown before starting a new charge.
3. Initialize once, simulate a lost response, recover via the original reference, and attempt retries from browser/callback/webhook. Verify GHS, amount, metadata and original account; process callbacks/webhooks in both orders. A successful provider return alone must not mark paid.
4. Verify actual fees and capture time. Exercise absent fees, a later corrected verification, late/MoMo-style capture, released stock, a second capture and conflicting financial evidence. Capture evidence must survive accounting failure. Reconciliation must either post one balanced Commerce sale/stock consumption or retain a visible review/refund liability without seller proceeds.
5. Reconcile Commerce clearing, seller payable, commission and refund liabilities independently of legacy Marketplace, event, dues and Giving balances. Verify zero orders create no provider call or money journal, yet consume stock once and retain one receipt.
6. Confirm one immutable receipt/outbox; render the real email, stop/restart the worker, fail dispatch, interrupt a sending claim and retry. Delivery ambiguity can repeat the same message, never create another order/receipt. Check the authorised inbox separately from provider acceptance. Native history must remain readable when email fails and after catalog/membership changes; include buyer-paid fees but no seller-paid commission/rate.
7. Refund a full uncollected order; test insufficient funding, an unknown response, delayed confirmation and a ledger failure after provider success. Unknown operations are not resent. Verify one confirmed refund posting and eligible restock only after confirmation; extra captures never restock original goods. Test free cancellation, foreign tenant/role refusal and refusal after collection.
8. Reserve only backed Commerce proceeds, including refund/other payout/reserve holds and transfer fees. Verify the original verified destination, parked OTP, bank edit after submission, wrong OTP, unknown release and recovery against the original transfer. Transfer fees post only at verified release. Never resend a parked/unknown transfer. Completed/failed/parked Commerce payout history must survive account deletion attempts.
9. Inspect buyer/seller screens at 320/390/768/1440, actual 200% text zoom, keyboard focus, light/dark inputs and long options. Inspect private-history revalidation, no-store responses and ticket-only offline exclusions. Browser quote→provider→capture remains open until this approved setup exists; in-process fakes are not substituted for provider acceptance.
10. Recheck existing venue pages, calendar, staff rooms, RFQs, quote/payment continuation and event/dues/Giving/ticket flows. Store rollout must not enable venue bookings or another organisation's checkout.

## Operational checks and recovery

- `commerce:reconcile --limit=100` runs every five minutes, without overlapping, on one scheduler. It retries recorded captures, bounded original-reference verification, expired holds, refunds and submitted transfers; it does not start a new payout/charge. Monitor its nonzero exit/warnings and ageing `initializing`, `unknown`, `pending`, `review`, `needs_funding` and `awaiting_otp` states.
- The `commerce-receipts` scheduled sweep dispatches at most 50 queued/retryable receipts per run; `DeliverStoreReceipt` has a 60-second timeout, 300-second retry delay and three queue tries. Durable sending claims older than ten minutes become retryable; the outbox bounds automatic delivery to five attempts and then exposes `exhausted`. Resolve sender/worker faults before an explicitly reviewed resend. A sent state means mail transport accepted the message, not confirmed inbox delivery.
- The native Store orders recovery action reuses original payment references. Store finance reconciles original refunds/transfers, shows funding needs, and requires the owner's OTP for parked release. Never repair an unknown result by changing a reference, deleting evidence, inserting fake cash or editing a paid flag.
- Turn **checkout** off to stop new quotes/initialization. Keep money already received, refunds, receipts and recovery operable. Do not roll back financial tables or delete audit/history as a rollback strategy. Catalog can stay browse-only with its separate switch; legacy venue booking controls stay unchanged.

## Exit and owner sign-off

Record pass/fail evidence and remaining exceptions for every sequence above. Before trading, complete **S1c collection/handover acceptance**, including refund-vs-collection locking and operational ownership. Obtain explicit approval for the real pilot, numerical agreement, support/funding/reserve policy, statutory receipt requirements, sender/storage setup and production activation. A green PR, merge or deploy does not supply that trading approval.


## Execution record — 2026-10-27

See the marketplace roadmap's **Finance acceptance checkpoint** for counts, safe provider operation IDs and current boundaries. The local database is `miconvener_commerce_s1c_testing`; Paystack calls used the configured local test key, GHS only, the owner's previously approved test email, and the provider-documented synthetic MTN number. Credentials stayed in process memory; original checkout/environment files were not changed. No live money operation was performed.

1. Local native checks: 105 production-style tests /419 assertions and a separate 13 identity/real-worker tests /100 assertions passed. Covered recovery paths and actual-worker handover/refund races are distinguished from provider fault injection.
2. Real provider checks: initialisation, duplicate-reference rejection, successful MoMo capture and fee/reference verification, full-refund submission/read-back, synthetic transfer-recipient creation and OTP parking/read-back. Refunds `18864251` and `18865434` remain pending, and the original test transfer awaits owner OTP; terminal outcomes are **not passed**.
3. Real sandbox behavior exposed a refund verifier defect when the original payment becomes `reversal-pending`. The scoped correction and 11 additional regression cases are prepared on the acceptance branch. Three positive cases were RED first; related 56 tests /235 assertions and two new-sale rejection cases /10 assertions passed after correction. Native gateway calls against actual refund `18865434` now recognise pending correctly and reject a wrong operation key. No release of this correction is implied by test execution.
4. The physical camera gate is closed: owner-confirmed stop/match and collection, independently verified as exactly one QR-source collection at 05:29:05 UTC, zero charge and no provider attempt. The free listing remains a draft. Original two live payments remain GH2 total; checkout is disabled.
5. Pending owner/provider steps: approve only the named existing test-mode transfer in Paystack, verify its original reference after OTP, and wait for/refine the provider's sandbox refund completion evidence. Do not share OTPs in chat, disable protections, replay an unknown money submission, switch production keys or use a live refund/payout to manufacture acceptance.

The full numbered sequence above is not marked complete: direct provider-contract probes, local mocked integration and the prior bounded live UI journeys provide different evidence. Real pilot commercial/funding/support/receipt terms and explicit activation remain required.


## Terminal follow-through — 2026-10-28

Both original sandbox refunds are now provider-confirmed **processed**. The native gateway on the proposed correction recognises refund `18865434` as processed while its original payment is reversed, matching refund/reference/operation/transaction identity and GHS10 (1,000 pesewas). The earlier expired OTP transfer became abandoned; after explicit owner retry approval, one replacement test transfer was approved through the private Terminal prompt and verified successful for GHS10 (1,000 pesewas) with zero provider transfer fee. Read-back and the native settlement gateway agree. No OTP/key was shared in chat, no protection was disabled and no live funds were used. See the roadmap terminal-outcomes section for the replacement reference and source CI evidence.

This closes the previously pending test-provider refund and OTP completion steps. It does not mark the full numbered runbook complete: remaining hosted sandbox/fault scenarios, real operational readiness and launch terms remain explicit. PR #54 still requires review/release approval, and checkout remains off.
