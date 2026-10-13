# Member dues: design

Date: 2026-10-13. Status: awaiting review. Path: architectural (new subsystem that touches payments, the ledger and payouts).

## 1. Purpose

Organisations such as unions, old-school and alumni groups, and congregations collect recurring dues from a known list of members. MiConvener should let them keep a member register, set what each class of member owes and how often, remind members automatically with a link they can pay from, and see who has paid and who owes. The money moves the way ticket money already does, with MiConvener keeping a commission.

Success looks like this:

- An organisation can set up classes, import its members, and have dues charged, reminded and collected without chasing people by hand.
- A member can pay from a link on their phone, by card or mobile money, with no account.
- The organisation sees who has paid and who is in arrears, and can be paid out what it has collected.
- Every payout, in every part of the platform, is tied to an accounting category, and the books show dues apart from ticket money.

## 2. Decisions already made

These came out of the discovery questions on 2026-10-13 and are not reopened here.

| Topic | Decision |
|---|---|
| Purpose | Membership dues with a member register and a recurring amount. One-off levies are out of scope. |
| Money | Follows the organisation's settlement mode. Platform default: MiConvener collects and pays out. Own gateway: paid direct, with the commission split. |
| Commission | 1%, uncapped, borne by the organisation (deducted), the same as contributions. |
| Members | An admin adds members or imports a spreadsheet. Each member gets a personal pay link by email or SMS. No member accounts. |
| Amounts | Membership classes (name, amount, period), with a per-member override or waiver. |
| Reminders | An automatic schedule plus a manual "remind everyone in arrears" button. |
| Plans | Starter and above, with a member cap. Starter 200, Growth 2,000, Enterprise unlimited, Free none. |
| Payouts | Allow payouts that are not tied to an event, and require every payout to carry an accounting category. |

Defaults chosen in this document, open to veto: no partial payments; no refunds (an admin can waive or void a charge); cash and transfer payments recorded by an admin carry no commission.

## 3. Out of scope for the first version

Member accounts or a member portal; a public "join" page; one-off levies and pledges; partial or advance payments; refunds of dues; recurring card auto-charge (members pay from a link each period); a fee-bearer choice (the organisation always bears the commission); import of payment history.

## 4. Accounting categories

### 4.1 The rule

Every payout belongs to exactly one category. The category is data, enforced by the database, so a payout without one cannot be created.

| Category | What it pays out | Event required |
|---|---|---|
| `event` | Ticket sales and contributions of one event | Yes |
| `dues` | Membership dues | No |
| `commission_credit` | Commission the platform owes an organisation back (see the commission credit feature) | No |
| `marketplace` | A business's balance from Event Store bookings and quotes | Optional (a booking may or may not relate to an event) |

A new money stream must be added to this list, with a decision about its event requirement, before it can pay out.

### 4.2 Where the category lives

`category` becomes a required column on `event_payouts`, `ledger_accounts` and `ledger_transactions`. Ledger entries inherit it through their account.

A Postgres check constraint on each of the three tables ties the category to the event: `event` requires an event id; `dues` and `commission_credit` require none; `marketplace` allows either. This makes the pairing impossible to get wrong where it matters.

`marketplace` is included because marketplace bookings already post to the ledger as a seller's payable, with an optional event, so they must fit the rule. There is no marketplace payout today; the category exists so that when one is built, it is accounted for from the start.

The existing table and model names stay (`event_payouts`, `EventPayout`). Renaming them would touch about 14 application files and 14 test files for no behavioural gain. The model's documentation says the "event" in the name is historical.

### 4.3 Backfill

The migration backfills before it adds the constraint, in this order:

- A ledger transaction of type `marketplace_booking`, and the accounts it posts to, becomes `marketplace`, whether or not it has an event.
- A payout transaction with no event id (the commission credit payouts) becomes `commission_credit`, with its accounts.
- Any other row with an event id becomes `event`.

The migration fails loudly if any row fits none of these rules, instead of guessing, and a test builds one of each kind to prove each lands in the right category.

### 4.4 Ledger accounts

`LedgerService::ensureAccountsExist` takes a category. For `event` it behaves as today. For `dues`, `marketplace` (without an event) and `commission_credit` it creates one set of accounts per organisation and currency, with no event. Account lookup includes the category, so dues payable can never be confused with commission-credit payable.

New transaction types: `dues_payment` for a collected charge, and `dues_offline` for an admin-recorded cash or transfer payment. `PlatformEarnings` labels them "Dues commission" so earnings show dues apart from tickets.

## 5. Data model

All new tables are on the landlord connection and carry `tenant_id`, in line with the rest of the app.

### 5.1 `dues_classes`

`id`, `tenant_id`, `name`, `amount` (minor units), `currency`, `period` (`monthly`, `quarterly`, `yearly`), `is_active`, timestamps. Unique on `(tenant_id, name)`. A class with members cannot be deleted, only deactivated.

### 5.2 `members`

`id`, `tenant_id`, `dues_class_id`, `name`, `email` (nullable), `phone` (nullable), `amount_override` (nullable, minor units), `status` (`active`, `lapsed`, `exited`), `joined_on`, `exited_on`, `pay_token` (unique, random, 40 characters), `notes`, timestamps. At least one of email or phone is required, because a member with neither cannot be reminded. A member is unique within an organisation by phone, or by email when there is no phone.

`amount_override` of 0 is a waiver. The pay token is rotated on request, which invalidates old links.

### 5.3 `dues_charges`

`id`, `tenant_id`, `member_id`, `dues_class_id`, `period_start`, `period_end`, `due_on`, `amount`, `currency`, `status` (`open`, `paid`, `waived`, `void`), `paid_at`, `payment_method` (`online`, `offline`), `payment_reference`, `charged_amount`, `gateway_fee_amount`, `commission_amount`, `recorded_by`, `note`, timestamps.

Unique on `(member_id, period_start)`, so generating charges twice never produces two. A charge's amount is frozen when it is created: later changes to a class or an override affect only future charges.

### 5.4 `dues_reminders`

`id`, `tenant_id`, `charge_id`, `step` (`before`, `on_day`, `after_7`, `after_30`, `manual`), `channel` (`email`, `sms`), `sent_at`. Unique on `(charge_id, step, channel)` for the automatic steps, which makes the daily command idempotent.

### 5.5 `tenant_dues_settings`

`tenant_id` (unique), `reminders_enabled` (default true), `schedule` (JSON: days offset for each step), `generate_days_ahead` (default 7), timestamps.

## 6. Money flow

### 6.1 Collecting a charge

The member opens `/pay/{token}` on the organisation's own domain, sees their open charges, and pays one or all. The payment is created with metadata `type = member_dues` and the charge ids.

- **Platform default:** a Paystack checkout on the platform account, as for tickets.
- **Own gateway:** the same, through `TenantPaystackResolver`, which routes the commission with the verified split and nets any commission credit. This is the existing mechanism, with category `dues`.

The existing payment webhook already dispatches on the metadata type; `member_dues` is a new branch. It is idempotent: a charge already `paid` is left alone, so redelivery does nothing.

### 6.2 Ledger entries

Platform-default, per paid charge, in the organisation's `dues` accounts:

- Debit Gateway Clearing: the amount received, net of the gateway fee.
- Credit Organiser Payable (dues): that amount less the commission.
- Credit Platform Revenue: the commission (1% of the charge, rounded, never below zero).

Own gateway uses `recordOwnGatewaySale`, generalised to take a category, so it books only the commission (received, netted against credit, or owed). Admin-recorded offline payments post no ledger entry: the money never reached the platform, and no commission is taken.

A balanced-ledger check runs in the tests for every case.

### 6.3 Payouts

A "Dues" finance screen shows the organisation's available dues balance (payable, less payouts requested or paid), and lets an authorised person request a payout to a verified payout account. Release uses the existing one-time-code flow, which does not depend on an event. A payout requested here is created with category `dues` and no event.

Automatic payout schedules stay event-only. Dues payouts are requested manually in the first version.

The platform-wide payout oversight page lists payouts of every category, with the category shown.

## 7. Charge generation and reminders

A daily command, `dues:process`, does two jobs in order.

**Generate.** For each active member, find the next period that starts within `generate_days_ahead` days and has no charge, and create one. Members who are `exited` or `lapsed` get none. A charge for a member with a zero amount (a waiver) is created as `waived`.

**Remind.** For each open charge, send each step that has become due and has not been sent: 7 days before the due date, on the due date, 7 days after, and 30 days after. Email always; SMS when the member has a phone and the organisation has SMS credit, drawing on the organisation's own email and SMS balances. A reminder that cannot be sent for lack of credit is skipped and recorded as skipped, not retried forever.

Every reminder carries the member's pay link, the organisation's name, the amount and the due date. Members can reply to the organisation: mail uses the organisation's display name and Reply-To, as the white-label setting already provides.

An organisation can turn reminders off or change the timing, and can press "Remind everyone in arrears", which sends a `manual` reminder to every member with an overdue charge, at most once per charge per day.

"In arrears" means at least one open charge whose due date has passed.

## 8. Screens, permissions and plan gating

### 8.1 Console screens

Built with Inertia and React, matching the existing console components:

- **Members:** list with search and filters (class, status, in arrears), add, edit, exit, rotate pay link, and a spreadsheet import (CSV or XLSX: name, phone, email, class) with a preview and a per-row error report before anything is saved.
- **Classes:** create, edit, deactivate.
- **Dues overview:** collected this month, outstanding, in arrears, collection rate, and a list of members who owe.
- **Charges:** per member and per period, with waive, void and record-offline-payment actions.
- **Reminders:** the on/off switch, the schedule, and the arrears button.
- **Dues payouts:** balance, request, and history.

### 8.2 Public pay page

`/pay/{token}` on the organisation's domain. No sign-in. Shows the organisation's name and logo, the member's name, open charges and a pay button. Rate limited, and it reveals nothing beyond the member's own name and charges. An unknown or rotated token returns a plain "this link is no longer valid" page, never a distinction between never-existed and rotated. After payment the member lands on a receipt page, and is emailed a receipt.

### 8.3 Permissions

A new `manage members` permission covers the register, classes and charges. The money screens reuse the existing `read finance` and `manage payouts`. The Org Superadmin and Org Admin roles get `manage members` by default.

### 8.4 Plan gating

A new limit feature `members`. Free 0, Starter 200, Growth 2,000, Enterprise unlimited. The cap counts `active` and `lapsed` members. Add and import refuse to go over it, with a message that names the plan and the number. Existing tenants receive the feature through the nightly `tenants:sync-features` sync, and production runs it once after deploy.

## 9. Privacy and data handling

The register holds personal data about people who are not MiConvener users. The privacy page is updated to say the organisation is the controller and MiConvener the processor for member data. Reminders say who they are from and why. Exiting a member stops charges and reminders but keeps their payment history, because the organisation needs its records. A member's data can be erased on the organisation's request, except where payment records must be kept.

## 10. Edge cases

- **Class change mid-period:** affects only charges created afterwards.
- **Currency:** one currency per class; charges carry it; a member pays in the charge's currency.
- **Duplicate import rows:** matched on phone, then email; existing members are updated only if the admin chooses "update", otherwise skipped and reported.
- **Webhook before redirect:** the charge is confirmed by the webhook, not by the redirect, as with tickets.
- **Payment for an already-paid or voided charge:** recorded as received but flagged for the organisation to resolve, since a refund is out of scope.
- **Member without email or phone:** cannot be added.
- **Time zones:** due dates are dates in the organisation's time zone; the daily command runs once a day in the organisation-neutral platform time and is idempotent.

## 11. Testing

Feature tests for each action and each permission boundary; ledger-balance tests for every money case (platform default, own gateway with and without credit, offline, redelivered webhook); a migration test for the backfill and for the constraint rejecting a payout with no category or with the wrong event pairing; command tests for generation, idempotency, reminder steps and skipped reminders; import tests for errors, duplicates and the member cap; pay-page tests for valid, invalid and rotated tokens and for rate limiting.

## 12. Rollout in three milestones

Each milestone is deployable and leaves the app consistent.

1. **Category foundation.** Migration with backfill and constraints, `ensureAccountsExist` and payout creation taking a category, oversight page showing categories. No dues screens. Existing behaviour unchanged.
2. **Collecting dues.** Classes, members (manual add), charges, pay page, webhook branch, ledger, dues balance and payout request, offline recording, waive and void.
3. **Running it.** `dues:process` generation and reminders, the arrears button, import, overview, plan cap and feature sync, privacy text, and updating the communities use-case page (and removing the dues guard in its test).

## 13. Risks

- **Payment-processor classification.** Recurring member dues, collected for organisations, is a different kind of payment from event tickets. Paystack's review of the business is under way. Before milestone 2 goes live, confirm that collecting dues for organisations is within the approved use.
- **Reminder volume.** SMS credits are paid for by the organisation. A large register with a short schedule can use credits fast. The overview shows the balance and what the next run will use.
- **Existing ledger rows.** The commission credit and marketplace postings predate categories; milestone 1 must label them without changing any balance, and a before-and-after trial balance check proves it.
