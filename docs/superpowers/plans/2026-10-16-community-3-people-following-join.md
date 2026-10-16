# Community 3: People, Following and Join Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans for native execution, or superpowers:subagent-driven-development if the owner chooses delegation. Implement task-by-task; tick each step in the same commit as its work.

**Goal:** Deliver email-first Community access, safely claimed phone access, independent Following, public joining and the Communities data needed by milestone 4.

**Architecture:** Preserve existing email verification and Event authorisation. Add independent phone proof plus revocable tenant/member access credentials; no global person table or inferred email/phone links. Following is address-scoped and independent of membership. Join/approval reuses member caps and dues rules, while follower announcements reuse milestone 2's delivery and consent machinery.

**Tech Stack:** Existing Laravel 13/PHP 8.5, PostgreSQL, Pest, Inertia/React, Tailwind console primitives, queued mail/SMS adapters. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-10-15-community-design.md`, sections 4, 6.1, 6.3, 8, 10, 13 and 13.1, including the owner's email-first safeguards agreed 2026-10-16.

**Status:** Built locally on `codex/community-3`, backend source `a1f5540` plus final client-only link fix, 2026-10-16. Owner approved push, merge and deployment on 2026-10-16; PR #28 is open. Require green remote CI before merging. No merge, deployment or real recipient send has occurred at this point.

**Baseline:** Deployed milestone 2 merge `b659e66`; planning branch `codex/community-3-plan` descends from the tested milestone 2 source and carries its release/pilot notes. Local `origin/main` is stale; fetch and compare before execution. Reuse the owned linked worktree at `.worktrees/artifact-completion`; leave the original `community-1a` checkout and Claude's files untouched. Create implementation branch `codex/community-3` from the reviewed planning head after reconciling any newer main changes.

## Global Constraints

- "Email is the primary sign-in and verification method." Phone is additional or an alternative for people without email, never an automatic historical-record lookup.
- "No person table." "No transitive access." Tenant/member grants do not verify or combine identifiers.
- "Shared services, not shared tables." Preserve Event records, verified-email history and explicit registration grants; no phone expansion of ticket, certificate, abstract or contribution access.
- "Following has its own rollout switch and no plan gate, so no Community gate can hide it."
- "Members counted toward the cap: pending, active and lapsed; exited do not count." No charges or benefits for pending members.
- "Re-evaluated at send time." Deduplicate by normalised channel address, honour consent and withdrawal immediately before transport, and keep uncertain SMS reservations held.
- "Pay links authorise only that member's open charges and receipts at that organisation." A pay, unsubscribe or invitation link never creates `/my` proof or grants.
- Money remains integer minor units. Use existing dues services and LedgerService; no changes to commissions, payouts, existing charges or payment callbacks.
- All public writes require CSRF protection and bounded throttles. Credentials and codes never appear in URLs, logs, analytics, emails, SMS, Inertia history or exports; only deliberately displayed once over a protected response.
- Preserve the existing 12-hour fixed email proof; phone proof is also 12 hours. Do not extend existing authentication to the spec's 30-day maximum.
- Existing React/Tailwind console components govern the tenant UI; legacy Metronic styles are for legacy admin pages. Associate labels, use supported component props, and explicitly type non-submit buttons.
- No dependency changes. Discover Artisan generators, use Form Requests and factories, and consult Boost documentation before implementation. `PAO_DISABLE=1` for PHP checks.
- JS/CSS commits include rebuilt `public/build`; run `pint --dirty`. Never use the owner's local tenant database for destructive tests.
- Owner review is required before implementation and before merge/deploy. Main auto-deploys. Once approved and deployed, enable Purpledot under standing approval; preserve all other rollout settings and plan entitlements.

## Review Focus

1. A newly assigned number and an imported historic member match: no names, membership existence, dues or receipts leak, even through list counts, errors, downloads or cached payloads (Tasks 2–4, 8).
2. A family shares a number, two members share a name, or one member has multiple identifiers: access stays per approved member; imports cannot merge them silently (Tasks 3–4, 9).
3. Contact edits, credential rotation, disputed ownership or sign-out race a read/send: revoked access is denied on the next request; already queued service notifications cannot leak private history to a replacement number (Tasks 4, 7–8).
4. Concurrent OTP requests, code guesses, join retries and cap approvals: one valid code consumption, bounded platform SMS cost, one member/request and no cap or dues duplication (Tasks 2, 5–6).
5. Following works with Community off and on Free; unsubscription or unpublishing after scheduling prevents delivery, while existing Events continue unchanged (Tasks 1, 5, 7, 10).

## Review decisions made explicit

- Email-first is presentation and the primary access path, not a requirement to disclose email when a phone-only path is needed. A verified email opens only exact current-email records; adding phone proof does not discover contacts linked by staff.
- Existing member access is either an exact verified email match, or a live phone proof plus a valid credential session for that exact member. Representatives use an approved grant scoped to that member and read-only self-service records. No contact edits, delegation or ticket transfer privileges flow from a representative grant.
- Credentials are 32 random bytes, rendered as a recoverable selector/secret pair once; store only the selector and a password-hashed secret. A session holds grant ID, credential version and verification time, never the plaintext recovery secret. Entering a credential requires phone proof first. Do not remember the secret in browser storage.
- Claim requests disclose the claimant's own supplied details only. Staff choose the exact target member after independent verification and must record the reason/method. Approval does not deliver a credential by SMS: the claimant retrieves it using the original session-bound request capability plus fresh phone proof. Lost capability requires a new independently reviewed request. No directory of matching members is exposed.
- Proposed operating defaults for review: SMS code 6 digits, TTL 5 minutes, 5 attempts; resend cooldown 60 seconds; 5 sends per number/day; IP challenge and caps reuse existing venue-friendly email controls; platform SMS dispatch cap 500/day, UTC. Daily send budgets are configurable. Code TTL, attempt ceiling and resend cooldown are fixed security bounds in this milestone; changing them requires a code/test review. A zero cap disables phone sending while email still works. These are authentication sends, never debits to tenant SMS packs. Count uncertain submissions against the platform cap; do not automatically resend them.
- Join modes: approval required by default, immediate, invitation only. Staff select a public membership type and explicitly enable joining; no active type means no enrolment. Pending request approval rechecks caps even though it is a status transition. Join/claim approval never silently resubscribes marketing.
- A follower is one verified address/channel at one organisation, not a person. Email is offered first. Adding a second follow channel requires proving it. No implicit copying of contacts from member records. Following stores explicit consent evidence; unfollow also disables marketing to that organisation/address, including overlap with membership. Re-following an opted-out address asks explicit consent to resume.
- Announce events using an explicit reviewed send through Messages, not automatic mail on publish. A narrow event-announcement entry remains available under Following without the Community/messages rollout; its server path only accepts a published event and follower audience, and preserves scheduling entitlements and `send community messages` permission. General Community messaging retains its existing gates.
- Wrong-number reports can be submitted by a verified holder without revealing matching records. Reports alone cannot revoke a stranger's membership. Staff review and confirm, then suspend/revoke affected grants and review queued service destinations. No automatic reassignment detection or carrier lookup is claimed.
- New phone-only self-enrolment can establish access to its newly created record; it never claims another matching historic record. Ambiguous historic matches go to staff review before immediate enrolment; generic responses do not reveal the match.
- Returning phone-only members enter their recovery credential on an organisation-specific “Access my membership” form; do not list unclaimed candidate organisations or member names. This is intentionally less automatic than email history. Each family member keeps a separate credential; an approved representative may hold several separately labelled grants.
- Milestone 4 owns the five-tab `/my` redesign. This milestone supplies working email-first verification/claim/follow/join screens, an accessible basic Communities panel and data endpoints, not an unfinished API with no public way to use it.

## Existing code and file boundaries

- `app/Services/Events/PlatformAttendeeVerification.php`: email proof is fixed 12 hours, consumes codes atomically and rotates sessions. Keep the public email methods compatible.
- `app/Http/Middleware/EnsurePlatformAttendeeVerified.php`: currently email-only. Do not broaden it; add a separate Community identity guard.
- `app/Services/Events/PlatformAttendeeWorkspaceAuthorizer.php`: exact email or explicit registration grant. Keep unchanged in authority.
- `routes/attendee.php`: platform `/my` routes. `routes/subdomain.php`: tenant console/public routes. Check host middleware ordering before adding routes.
- `app/Services/Community/MemberIdentity.php`, `MemberImport.php`: shared phones already supported; the current single-ID name/phone index can hide collisions. Replace that matching behaviour in Task 9, not the earlier database migration.
- `app/Services/Community/AudienceResolver.php`, `MemberAudienceSource.php`: member source presently runs for every non-event selector. A follower-only selector must not accidentally include every member.
- `app/Services/Community/CommunityMessages.php`, `AnnouncementTransport.php`, `MessagingPreferences.php`: reuse delivery claims, credit reservations and consent; implement follower rechecks wherever execution currently rechecks member eligibility.
- `app/Services/Community/MemberCap.php`, `app/Services/Dues/DuesCharges.php`, `app/Models/CommunitySettings.php`, `app/Http/Controllers/Tenant/MemberController.php`: reuse current cap/charge paths, extract only the member create/approve action needed by joining.
- `resources/js/Pages/Public/Events/AttendeePortal/MyPortal.jsx`, `VerifyPrompt.jsx`, `resources/js/Pages/Public/Events/Show.jsx`: existing public surfaces to extend, not replace.

New class names below are proposed implementation paths, not claims that these files already exist. Generate model/factory/migration/request/controller skeletons using the project's Artisan conventions. Landlord migrations belong in `database/migrations/landlord/`; choose non-colliding timestamps at execution.

**Test harness:** Each new PHP feature file follows its sibling Community tests: `refreshTenantDatabases()`, role/permission seeding, tenant context and landlord factories, fake Mail/Queue/notification provider, fixed clock where needed. These test snippets pin representative invariants; implement every scenario named in that task as well. Direct model factories may create collision fixtures deliberately. Ordinary requests must pass validation/permissions. Do not call another test file's namespaced helper implicitly; use the established shared helpers or include the setup explicitly.

**Execution checks:** Prefix PHP commands with `PAO_DISABLE=1`, select PHP 8.5 and the isolated PostgreSQL test database explicitly. Prepend Node 24 to PATH for npm; do not use the system Node 16. Race suites use a different database from refresh-based suites and fake provider transport. Never run two destructive suites against the same database.

## Task 1: Independent Following rollout, settings and contracts

**Files:** Modify `app/Models/Tenant.php`, `app/Models/CommunitySettings.php`, `app/Http/Kernel.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `database/seeders/PermissionsSeeder.php`, `app/Libraries/RolePermissions.php`. Create `app/Http/Middleware/EnsureFollowingEnabled.php`, `app/Console/Commands/Community/EnableFollowingCommand.php`, model/factory `CommunityFollower`, and landlord migration `add_community_following_and_join_settings`. Test `tests/Feature/Community/FollowingRolloutTest.php`.

**Schema:** Followers: UUID id, tenant_id, channel (`email|sms`), encrypted address, keyed address_hash, consented_at, consent_source (`organisation|event`), optional source_event_id, unfollowed_at, timestamps; unique tenant/channel/hash, tenant foreign key and tenant-safe event checks. Extend settings with join_mode default approval_required, public_join_enabled default false, nullable public_membership_type_id. Following uses feature key `community_following`; no package gate or implicit enable of Community.

**Interfaces:** `Tenant::followingEnabled(): bool`; command `community:following-enable {tenant} {--off}` follows the existing enable-command convention, is idempotent and edits only the selected rollout. Existing `manage members` manages followers/join settings/claims. Existing `send community messages` permits event announcements; no new permission unless a concrete uncovered operation requires one, then update seeder/RolePermissions together.

- [x] Write the failing matrix for Following on/off × Community on/off × Free/paid, cross-tenant source event/type and missing feature metadata. Include:

```php
it('does not couple Following to Community', function (): void {
    $tenant = \App\Models\Tenant::factory()->create();
    \Illuminate\Support\Facades\Artisan::call('community:following-enable', ['tenant' => $tenant->slug]);
    expect($tenant->fresh()->followingEnabled())->toBeTrue();
    expect($tenant->fresh()->communityEnabled())->toBeFalse();
});
```

- [x] Run `php artisan test --compact tests/Feature/Community/FollowingRolloutTest.php`; establish failure on the absent command/contract.
- [x] Generate schema/classes, register middleware and share `community_following` independently. Missing-only feature registration must preserve existing tenant overrides, prices and roles. Model all new tables on the landlord connection and scope every public query explicitly by tenant.
- [x] Pass the matrix and permission/component contracts; Pint; commit `feat(community): add independent Following rollout and settings` with this task ticked.

**Ruling:** Cross-tenant event/type validation is exercised in Tasks 5/6 where those write endpoints exist, rather than adding model-side partial public actions here. Existing manage-members/send permissions suffice; their catalog is unchanged. Migration timestamps follow existing milestone 2 migrations (already dated October 18).

## Task 2: Independent phone proof and platform-paid abuse budget

**Files:** Create model/factory `app/Models/PlatformPhoneAccessCode.php`, model `PlatformPhoneSendReservation.php`, landlord migrations for both, `app/Services/Community/PhoneVerification.php`, `PhoneSignInBudget.php`, `app/Jobs/Community/SendPhoneAccessCode.php`, `app/Http/Controllers/Public/CommunityPhoneAccessController.php`, Form Requests under `app/Http/Requests/Attendee/`, `app/Http/Middleware/EnsureCommunityIdentityVerified.php`; modify `config/attendee_portal.php`, `.env.example`, `app/Http/Kernel.php`, `routes/attendee.php`. Test `PhoneVerificationTest.php`, `PhoneVerificationRaceTest.php` under `tests/Feature/Community/`.

**Schema:** Challenge UUID, phone HMAC, encrypted normalised number, hashed code, attempts, expiry, consumed_at, unique queued request ID, timestamps. Reservations store request ID, UTC day, status (`reserved|submitted|failed|unknown`), submission time and expiry; unique request, never raw secret/provider payload. Use the existing notification adapter with a platform sender, no tenant allowance debit. A shared atomic lock/transaction bounds concurrent platform reservations. Only known pre-submission failures release capacity; uncertain acceptance stays counted until operator reconciliation or the day ends. Never hold a database lock while calling a provider.

**Interfaces:** `PhoneVerification::requestSend(string $phone, string $ip, ?string $challenge): array{status:string, request_id?:string}`; `confirm(Session $session, string $phone, string $code, string $ip): bool`; `verifiedPhone(Session $session): ?string`; `forget(Session $session): void`. `PhoneSignInBudget::reserve(string $requestId): bool`, `finish(string $requestId, string $outcome): void`. HMAC domain must be distinct from messaging. Community identity guard accepts a current email OR current phone proof, with a separate request attribute for each; it does not grant record access.

- [x] Write failing normalisation, 5-minute expiry, fixed 12-hour proof, single consumption, resend/guess limits, enumeration-neutral responses, shared-IP capacity, global cap and fail-closed transport tests. Include:

```php
it('does not turn phone proof into email proof', function (): void {
    $session = app('session.store');
    $session->put('community_verified_phone', ['phone' => '+233241234567', 'expires_at' => now()->addHours(12)->timestamp]);
    expect(app(\App\Services\Events\PlatformAttendeeVerification::class)->verifiedEmail($session))->toBeNull();
    expect(app(\App\Services\Community\PhoneVerification::class)->verifiedPhone($session))->toBe('+233241234567');
});
```

- [x] Run the focused file and record meaningful failure. Use a dedicated race database for actual two-worker simultaneous confirm and last-budget-slot tests; do not treat sequential calls as concurrency coverage.
- [x] Implement the contracts. Reserve capacity and number cooldown atomically before queueing; jobs are idempotent and do not resubmit after uncertain transport acceptance. New code supersedes old code without resetting consecutive guess limits. Rotate session ID on successful proof. Reject malformed numbers; never guess missing international country codes beyond existing Ghana normalisation.
- [x] Add `attendee_portal.phone.enabled` default false and sender/limit config in `.env.example`. Require an explicitly supplied, active organisation context with either Community or Following rollout enabled before issuing phone challenges; no member lookup. This is an abuse/rollout check, not organisation membership evidence. Persist only the allowlisted return path, not an arbitrary redirect URL. Email remains usable when the phone switch/provider/budget is unavailable.
- [x] Add `POST /my/verify/phone/send`, `/confirm`, `/forget`; preserve existing email endpoints and email-only protected history routes. Codes send for valid numbers regardless of whether a membership exists; apply the same response shape/timing controls for both. Add pruning for expired challenges/reservations using existing scheduler patterns; do not prune unresolved submission evidence prematurely.
- [x] Pass focused/race tests and existing `PlatformAttendeeVerificationTest`; Pint; commit `feat(community): add bounded independent phone verification`.

**Ruling:** Real worker races live in Infrastructure with DatabaseTruncation and a separate database, matching project conventions. Phone send/confirm reuse the venue-friendly identity limiter, not the unrelated 10/min registration throttle (15-person HTTP regression RED→GREEN). Request validation composes rule sets because Pint makes concrete requests final. Operational daily caps/IP controls are configurable; code lifetime (5 minutes), attempt ceiling (5) and resend floor (60 seconds) remain fixed security bounds. Tests: 27 focused phone/email tests, 117 assertions; 2 real-worker races, 14 assertions.

## Task 3: Member claims and explicit representative grants

**Files:** Create models/factories `CommunityAccessClaim`, `CommunityAccessCredential`, `CommunityAccessAudit`; their landlord migrations; `app/Services/Community/MemberAccess.php`, `MemberClaims.php`; public/tenant claim controllers and Form Requests. Modify `routes/attendee.php`, `routes/subdomain.php`. Test `tests/Feature/Community/MemberAccessTest.php`, `MemberClaimsTest.php`.

**Schema:** Claims: tenant, claimant phone encrypted/HMAC, claimant-supplied name, session-capability verifier, optional staff-selected member ID, requested role (`self|representative`), pending/approved/rejected/expired status, expiry 7 days, reviewer/reason, timestamps. Credentials: UUID selector, tenant/member, claimant phone HMAC/encrypted address, role, secret_hash hidden, version, member access_version snapshot, approved_by/method/at, revoked_at, timestamps. Audit entries capture actor/tenant/member/action/reason, no secrets or raw code. Add members.access_version integer default 1. A representative's phone is on the credential, not inferred from or copied into the member's phone field.

**Interfaces:**

```php
MemberAccess::canView(\Illuminate\Contracts\Session\Session $session, \App\Models\Member $member): bool;
MemberAccess::establish(\Illuminate\Contracts\Session\Session $session, string $selector, string $secret): bool;
MemberAccess::revoke(\App\Models\Member $member, \App\Models\User $actor, string $reason): void;
MemberClaims::request(\App\Models\Tenant $tenant, \Illuminate\Contracts\Session\Session $session, string $name, string $role): \App\Models\CommunityAccessClaim;
MemberClaims::approve(\App\Models\CommunityAccessClaim $claim, \App\Models\Member $member, \App\Models\User $actor, string $method, string $reason): void;
MemberClaims::complete(\Illuminate\Contracts\Session\Session $session, string $claimId, string $capability): array;
// complete returns {selector:string, secret:string} once, or a generic denied/expired response.
```

- [x] Write failing exact-email, phone-without-grant, different tenant/member, representative, guessed selector, revoked/version mismatch and one-time claim-completion cases. Include:

```php
it('does not reveal members through phone proof alone', function (): void {
    $member = \App\Models\Member::factory()->create(['phone' => '+233241234567']);
    $session = app('session.store');
    $session->put('community_verified_phone', ['phone' => $member->phone, 'expires_at' => now()->addHours(12)->timestamp]);
    expect(app(\App\Services\Community\MemberAccess::class)->canView($session, $member))->toBeFalse();
});
```

- [x] Run `MemberAccessTest.php` and `MemberClaimsTest.php`; confirm failure before implementation.
- [x] Implement exact-email access first, then phone+credential check. Require phone proof matching credential, live version, matching tenant/member, approved role and current membership state; lapsed/exited can view retained history but gain no benefits. Staff approval requires `manage members`, tenant scope and recorded independent-check method/reason; require the claimant to return with both request capability and current matching phone proof. Approval alone reveals no member details to the claimant.
- [x] Add email-authorised phone enrollment: current verified email must exactly match that member and added phone must be independently verified; issue a member-scoped credential. Do not infer proof of any email on the record from phone enrollment.
- [x] Pass focused tests including parallel claim completion and credential rotation; verify hashes/plaintext never enter JSON list/detail exports. Pint; commit `feat(community): add member-scoped claims and representative access`.

**Ruling:** Existing-email phone enrollment requires the verified phone to match the current member contact; a different contact needs an organisation edit/independent claim. Pending claim capabilities survive proof expiry in the same browser, but completing still needs fresh matching phone proof; explicit phone removal clears capabilities. Staff gates reload the actor after changing permission team (cached-role regression RED→GREEN). Returning claims/recovery use no-store JSON, never Inertia secret props. Tests: 14 access/claim tests, 60 assertions; combined phone/access/claim 25/120; two-worker claim completion 1/8.

## Task 4: Revocation, contact edits and wrong-number review

**Files:** Modify `app/Models/Member.php`, `app/Http/Controllers/Tenant/MemberController.php`, `app/Services/Community/MemberAccess.php`, `MemberClaims.php`, `app/Services/Dues/DuesReminders.php`, `app/Http/Controllers/Public/PlatformAttendeeAccessController.php`, public service-worker/cache handling discovered from `PlatformAttendeePwaTest.php`. Create `MemberContactChanges.php`, `CommunityContactNotice` mail and existing-adapter SMS notice, `CommunityWrongNumberReport` model/factory/migration and public/tenant report controllers/requests. Test `MemberAccessRevocationTest.php`, `WrongNumberReportTest.php`.

**Interfaces:** `MemberContactChanges::update(Member $member, array $validated, User $actor): Member`; `MemberAccess::forget(Session $session): void`. Contact changes transactionally increment access_version, revoke direct phone credentials, suspend representatives and expire pending claims; exact-email checks use the current member row. A restored contact never resurrects old credentials. Public report accepts a known organisation plus the verified claimant number, not a list of matching members; staff matches it privately.

- [x] Write failing tests for an already-open session after phone/email edits, representative suspension, import-driven edit, same-value normalised edit, credential rotation, report flooding, cross-tenant reviewer and generic notices. Include:

```php
it('does not retain exact email access after a contact edit', function (): void {
    $member = \App\Models\Member::factory()->create(['email' => 'old@example.test']);
    $session = app('session.store');
    $session->put(\App\Services\Events\PlatformAttendeeVerification::SESSION_KEY, ['email' => 'old@example.test', 'expires_at' => now()->addHour()->timestamp]);
    $member->update(['email' => 'new@example.test']);
    expect(app(\App\Services\Community\MemberAccess::class)->canView($session, $member->fresh()))->toBeFalse();
});
```

- [x] Run focused tests; confirm the access/version/review cases fail against the preceding task.
- [x] Implement one contact-update action used by UI and import. After-commit notices to old/new channels are generic (no names, balances or full replacement contacts); provider failure cannot roll back or re-enable old access. Check wrong-number/revocation flags before sending service notifications to a disputed destination; retain history and give staff an actionable suppressed-delivery state.
- [x] Enforce access_version increment in Member's existing update hook for normalised contact changes, including writes outside the controller action; unchanged normalised values do not increment. Claims/credentials record that version, so change-away-and-back cannot revive access. The action owns the audit/review/notice workflow without incrementing twice. Queued reminder destinations carry the contact version and are rechecked before transport; skip known changed/disputed destinations rather than substituting a replacement number into an old personalised payload. Add a direct model-update regression test and a queued-reminder contact-edit test.
- [x] Extend attendee sign-out to clear all portal proof/grant keys, registration grants and personal caches without unexpectedly signing the same browser out of the staff console. Removing only phone clears phone-derived grants; verified email stays independent. Personal endpoints return `Cache-Control: private, no-store`; no service-worker caching of claims, recovery responses or member data.
- [x] Pass focused tests and existing PWA/sign-out tests. Pint; rebuild if frontend touched; commit `fix(community): revoke member access on contact and ownership changes`.

**Implemented interfaces for later tasks:** `WrongNumberReports::report(Tenant, Session, string $note, ?Member = null): CommunityWrongNumberReport`, `review(CommunityWrongNumberReport, User, bool $confirmed, string $reason): void`, `resolve(CommunityWrongNumberReport, User, string $method, string $reason): void`, `isBlocked(Tenant, string $channel, string $address): bool`; `MemberAccess::revokeCredential(CommunityAccessCredential, User, string $reason): void`; `ContactNotices::deliver(string $noticeId): void`. Staff routes are `tenant.community.reports.index/review/resolve`, `credentials.index/revoke/revoke-all`. Public report route is `attendee.my.wrong-number`.

**Rulings:** Confirmed phone holds block phone grants and private service SMS until independently reconfirmed; clearing a hold never revives an old credential. Contact SMS notices use the tenant's existing credit/monthly budget and audited reconciliation, not the platform sign-in budget. Delivery-unit jobs snapshot contact versions; legacy units with no snapshot fail closed for that day's send. Historical dues reminders remain available after lapse/exit. Claim completion/approval and contact updates lock the member before the claim; a real two-worker deadlock regression was RED→GREEN. Test workers must emit a completion marker as well as exit successfully. `/my` responses are no-store; portal diagnostics/session credentials and outgoing SMS codes are excluded even locally. Local test runs use a per-database lock after an overlap was caught; invalid overlap outputs were discarded.

**Evidence:** Access/claim/contact integration27 tests/106 assertions; dues reminders49/478; contact/notice/reconciliation/mail/PWA30/98; phone and telemetry13/65; staff review HTTP1/9; final portal privacy/host/PWA31/116; actual-worker races4/39. Pint and diff whitespace checks pass. No JS/CSS changed in this task, so no Vite rebuild is needed. No production writes or messages.

## Task 5: Follow, unfollow and independent Followers management

**Files:** Create `app/Services/Community/Following.php`, `app/Http/Controllers/Public/CommunityFollowController.php`, `app/Http/Controllers/Tenant/CommunityFollowerController.php`, public/tenant follow Form Requests; modify `MessagingPreferences.php`, `routes/attendee.php`, `routes/subdomain.php`. Test `FollowingTest.php`, `FollowingConsentTest.php` in `tests/Feature/Community/`.

**Interfaces:** `Following::follow(Tenant $tenant, Session $session, string $channel, bool $resumeMarketing, ?Event $source): CommunityFollower`; `unfollow(Tenant $tenant, Session $session, string $channel): void`; `isFollowing(Tenant $tenant, Session $session, string $channel): bool`; `MessagingPreferences::enableMarketing(Tenant $tenant, string $channel, string $address): void` is callable only after explicit verified re-consent, with audit evidence. Use `email`/`sms` channel values consistently; phone proofs use the sms channel but never email proof.

- [x] Write failing tests for Free and Community-off follows, two organisations, anonymous/unverified writes, forged address/source event, idempotent concurrent follow, member/follower overlap and explicit re-consent. Include:

```php
it('does not follow a second contact just because staff recorded it', function (): void {
    $tenant = \App\Models\Tenant::factory()->create();
    $session = app('session.store');
    $session->put(\App\Services\Events\PlatformAttendeeVerification::SESSION_KEY, ['email' => 'one@example.test', 'expires_at' => now()->addHour()->timestamp]);
    expect(fn () => app(\App\Services\Community\Following::class)->follow($tenant, $session, 'sms', false, null))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});
```

- [x] Run the two focused files and confirm failure, then implement source-bound consent and unique upsert under the tenant/channel key. Follow writes derive the address from session proof, never a submitted contact. Refollow without explicit opt-in cannot clear a previous preference opt-out. Following neither creates a Member nor grants access.
- [x] Implement `POST/DELETE /my/communities/{tenantSlug}/follow`; staff `GET /community/followers` under Following, staff authentication and `manage members`, outside Community middleware. Token unfollow links reuse preferences' GET confirmation/POST mutation; POST marks matching follower inactive and disables marketing atomically. Existing preference tokens remain valid with rollout switches off. Show shared-address scope: “This stops announcements from this organisation to this address.”
- [x] Pass focused tests plus `CommunityPreferencesTest.php`. Pint; commit `feat(community): add consent-aware Following and follower management`.

**Evidence:** Following/consent/preference links11 tests/39 assertions; two real-worker follow/withdrawal races2/22. The independent Followers endpoint enforces manage-members and works with Community off. Its HTML console page is delivered in Task10; this task supplies the usable JSON contract. Following and preference withdrawal acquire the same tenant/channel/address lock, so a withdrawal cannot be lost to a duplicate follow. No new dependency or JS/CSS change.

## Task 6: Joining, staff approval and cap-safe membership transitions

**Files:** Create `app/Services/Community/MembershipJoining.php`, `JoinSettings.php`, model/factory/migration `CommunityJoinRequest` (idempotent request envelope, not a second membership table); public `CommunityJoinController` and tenant `CommunityJoinReviewController` with Form Requests. Modify MemberController, CommunitySettings, `routes/attendee.php`, `routes/subdomain.php`; extract the shared create action into `app/Services/Community/MemberEnrollment.php`. Test `CommunityJoinTest.php`, `CommunityJoinApprovalTest.php`, `CommunityJoinRaceTest.php`.

**Schema:** Join envelope: UUID, tenant, proof channel/hash, client request key, submitted name, selected public type snapshot, nullable member_id, status (`pending|approved|rejected|review_required`), reviewed_by/reason, submitted_at/reviewed_at, unique tenant/proof-channel/hash/request-key. Normal new approval-required joins create one pending Member in the same transaction. Ambiguous historic matches create a review_required envelope with no new Member and no disclosure. Retain only necessary supplied fields; no imported record snapshot in the public response.

**Interfaces:** `MembershipJoining::submit(Tenant $tenant, Session $session, array $input, string $requestKey): CommunityJoinRequest`; `approve(CommunityJoinRequest $request, User $actor): Member`; `reject(CommunityJoinRequest $request, User $actor, string $reason): void`; `MemberEnrollment::create(Tenant $tenant, array $validated, MemberStatusEnum $status): Member`. The actor controls type/configuration; public callers cannot set status, amount, waiver, joined date, contact ownership or charge options.

- [x] Write failures for all three modes, no active type, Community off, foreign type, pending no charges, immediate active, cap reached/downgraded, rejected/repeated requests, ambiguous contact and concurrent duplicate approval. Include:

```php
it('never charges a pending member', function (): void {
    $member = \App\Models\Member::factory()->create(['status' => \App\Enum\MemberStatusEnum::Pending]);
    expect(fn () => app(\App\Services\Dues\DuesCharges::class)->createFor($member, \Carbon\CarbonImmutable::today()))
        ->toThrow(\DomainException::class);
    expect($member->charges()->count())->toBe(0);
});
```

Also create the pending member through `MembershipJoining::submit`, run `DuesScheduler::generateFor($tenant, CarbonImmutable::today())` on an enabled active tenant, and assert zero charges for that pending member. The direct createFor check above is an existing invariant; the join-path test must be red before joining is implemented.

- [x] Run join/approval files and see failures before implementation. Add actual two-worker last-slot and duplicate-request tests on a separate race database.
- [x] Implement with MemberCap's tenant lock; normal pending/immediate creation consumes one slot. Approving an existing pending member adds zero slots, but explicitly block approval when the tenant is already over cap per spec; do not use `assertRoomFor($tenant, 1)` and double-count it. Lock the request/member and re-read mode/type/cap. Immediate joins use existing member hooks/dues services and default next full period; no duplicate current charge.
- [x] Pending rejection marks its member exited (preserve audit, no charges), allowing a future reviewed rejoin. Existing/lapsed/exited ambiguous contacts go through review without changing historic status or resuming old liabilities. Invitation-only disables public submit; staff may still add members or review existing-member claims. A successful membership approval is not approval for any other record or representative access.
- [x] Return only the claimant's request state with no historic match hint. Store its session capability for the basic request screen. For a newly created phone-only record, provision a recoverable credential scoped only to that record; on ambiguous review, use Task 3's independent claim instead. Do not trust caller-supplied member IDs.
- [x] Pass focused join/race/cap/dues-period tests; Pint; commit `feat(community): add safe joining and membership approval`.

## Task 7: Follower audiences and reviewed event announcements

**Files:** Create `app/Services/Community/FollowerAudienceSource.php`, `app/Http/Controllers/Tenant/FollowingEventAnnouncementController.php`, its Form Request; modify `AudienceResolver.php`, `MemberAudienceSource.php`, `CommunityMessages.php`, `AnnouncementTransport.php`, message request validation, `routes/subdomain.php`. Test `FollowerAudienceTest.php`, `FollowingEventAnnouncementTest.php`, existing `CommunityAudienceTest.php` and `CommunityMessagesTest.php`.

**Interfaces:** FollowerAudienceSource implements existing `AudienceSource::addresses(Tenant $tenant, array $selector, string $channel): iterable` and adds `contains(Tenant $tenant, array $selector, string $channel, string $address): bool`. Add audience kinds `followers` and `members_and_followers`, preserving `all_members`, `status`, `groups`, `arrears`, `event`. Member source returns no addresses for `followers`; follower source returns none for old member-only selectors. `members_and_followers` means active members plus consented active followers. Email and SMS dedupe independently; never infer a person's second contact.

- [x] Write failures for phone/email normalisation, member overlap, follower-only excluding unrelated members, Community-off Free event send, missing send permission, inactive source event, opt-out/unfollow between preview and dispatch, and follow arriving after send start. Include:

```php
it('does not include members in a follower-only audience', function (): void {
    $tenant = \App\Models\Tenant::factory()->create();
    \App\Models\Member::factory()->create(['tenant_id' => $tenant->id, 'email' => 'member@example.test']);
    $addresses = iterator_to_array(app(\App\Services\Community\AudienceResolver::class)->resolve($tenant, ['kind' => 'followers'], 'email'));
    expect($addresses)->toBe([]);
});
```

- [x] Run focused files and confirm failure, then register the follower source and explicit selector dispatch. Update BOTH resolution and CommunityMessages' current member-only pre-transport `contains` check; frozen recipients are still rechecked against current consent, Following flag and event publication. Retain durable claims, exact refunds and unknown handling. Re-follow/new follow after the send snapshot is for the next announcement.
- [x] Add event context to message records (nullable source_event_id plus purpose `community|following_event`); event remains in its existing table. Following-event controller restricts event to this tenant's published/non-ended event, and audience to followers; review subject/body, published URL, channel costs and authority before send. Require current send permission. Expose this same record under Followers' announcement history when Community is off, including preview, detail and cancel routes. A narrow purpose check permits this record only; never bypass general Community gates.
- [x] Prevent arbitrary reuse of following_event purpose via Community store/edit endpoints. Reject disallowed scheduling on Free; Starter+ retains schedule access. Pause unsent announcements if the event is unpublished/cancelled or Following disabled. Existing Event blast tracking/templates stay in Event records and do not automatically announce on publish.
- [x] Pass follower tests, all Event blast/SMS regression tests and existing Community messaging cancellation/retry tests. Pint; commit `feat(community): announce published events to consented followers`.

## Task 8: Private Communities data without widening Event access

**Files:** Create `app/Services/Community/CommunityHistory.php`, `app/Http/Controllers/Public/MyCommunitiesController.php`, `app/Http/Resources/Community/MyCommunityResource.php`, `MyMemberResource.php`; modify `routes/attendee.php`, platform access controller session/forget handling only where needed. Test `MyCommunitiesTest.php`, `MyCommunitiesPrivacyTest.php`, `MyCommunityReceiptsTest.php`.

**Interfaces:** `CommunityHistory::communities(Session $session): array` returns organisations followed by proven addresses or holding members authorised by MemberAccess; `members(Tenant $tenant, Session $session): iterable` returns authorised members only. No phone-based candidate list. API accepts only explicit proven identifiers, never inferred contacts, admin notes, other family members or private staff fields.

- [x] Write failures across two organisations where A(email)+B(phone) occurs on one record, B occurs elsewhere, and a separate relative shares B. Include:

```php
it('keeps phone-only verification outside existing email history', function (): void {
    $host = mb_ltrim((string) config('session.domain'), '.');
    $this->withServerVariables(['HTTP_HOST' => $host])
        ->withSession(['community_verified_phone' => ['phone' => '+233241234567', 'expires_at' => now()->addHours(12)->timestamp]])
        ->getJson('/my/events')->assertUnauthorized();
});
```

The host setup matches `PlatformAttendeeAccessControllerTest.php`; retain it so the test reaches the intended host/middleware. Add cases for revoked grants on detail/receipt download, arbitrary tenant/member IDs, email expiry with a still-live phone proof, rollout-off history and a pay-link bearer attempting `/my` access.

- [x] Run focused files and confirm failures. Implement `GET /my/identity` (masked proofs, expiry, available actions), `/my/communities`, `/my/communities/{tenantSlug}`, `/my/communities/{tenantSlug}/members/{member}`, plus scoped charges/receipt download routes. Use Community identity middleware then per-record authorisation on every request. Sign-in alone is not a history query.
- [x] Return stable IDs/organisation branding, follow state for each proven channel, member status/type, represented/self label, open dues and paid receipts for that member only. Hide grant credentials, other contact addresses, staff notes and raw pay tokens. Use the existing pay page for checkout; generate an authorised member's current pay URL server-side without making it a portal credential. Paginate histories and bound queries; no broad response filtered only in React.
- [x] Gate new follow/join actions on current rollout; retain authorised existing financial history and receipts when rollout is disabled, consistent with downgrade invariants. Mark every response/download no-store and exclude it from the service worker. Do not expose a combined empty email history as evidence that phone access covers Events.
- [x] Pass focused privacy/download tests and all existing platform attendee route/host/history/workspace tests. Pint; commit `feat(community): expose scoped Communities and member history`.

## Task 9: Import collision handling without silent merges

**Files:** Add a migration removing raw/canonical name+phone uniqueness for explicitly reviewed distinct people (safe rollback refuses when reviewed duplicates exist), and staff candidate request/endpoint. Modify `MemberFormModal.jsx`, `MemberIdentity.php`, `MemberImport.php`, `ImportPreview.php`, `ConfirmMemberImportRequest.php`, MemberRequest validation, `MemberController.php`, `resources/js/Pages/Tenant/Community/ImportMembers.jsx`. Tests: extend `MemberImportTest.php`, add `MemberIdentityCollisionTest.php` and `resources/js/test/communityImportDecisions.test.jsx`.

**Interfaces:** Add `MemberIdentity::candidates(Tenant $tenant, string $name, ?string $phone, ?string $email): array` returning all tenant-bound possible IDs/reasons to authorised staff. Do not overwrite one member ID with another in a map. Extend `MemberImport::confirm(Tenant $tenant, string $token, string $existingPolicy, int $userId, array $decisions = []): ImportResult`; decisions keyed by preview line are `{action:skip|update|create_distinct, member_id?:string}`. Preserve default skip/update for unambiguous existing-email matches; all name/phone-only and contradictory multi-match rows require explicit decisions. Create-distinct applies to family/name/phone collisions, not an override that silently duplicates an existing exact email.

- [x] Write failures for two members sharing name/phone but holding different emails, phone-only collisions, email A pointing to one row and phone/name to another, same-file duplicates, foreign member choice, stale preview and changed contact after preview. Include:

```php
it('keeps every candidate when family names and phones collide', function (): void {
    $tenant = \App\Models\Tenant::factory()->create();
    $a = \App\Models\Member::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Alex Mensah', 'phone' => '+233241234567', 'email' => 'a@example.test']);
    $b = \App\Models\Member::factory()->create(['tenant_id' => $tenant->id, 'name' => $a->name, 'phone' => $a->phone, 'email' => 'b@example.test']);
    $candidates = app(\App\Services\Community\MemberIdentity::class)->candidates($tenant, $a->name, $a->phone, null);
    expect(array_column($candidates, 'id'))->toContain($a->id, $b->id);
});
```

- [x] Run the focused PHP/JS files and confirm failure. Keep import tokens tenant/user-bound, expiring, single-use. Sign the stored preview revision; re-read candidates and selected member under the existing import/tenant lock. A changed match requires a new preview, not a silent fallback. Recheck cap and type; imports use Task 4's contact change action so they cannot evade grant revocation.
- [x] Replace blanket name/phone uniqueness in staff add/edit with an explicit possible-duplicate confirmation for creating a distinct member. Ordinary callers cannot bypass it with arbitrary IDs. Render candidate choices only for authorised staff; no public lookup or automatic financial-history merge. Keep existing histories and give a before-confirm summary of create/update/skip actions.
- [x] Pass import/collision/cap regression tests, JS interactions, lint, build and Pint; commit `fix(community): require explicit decisions for ambiguous member imports` with rebuilt assets.

## Task 10: Complete public and staff flows with accessible UI

**Files:** Modify `resources/js/Pages/Public/Events/AttendeePortal/MyPortal.jsx`, `VerifyPrompt.jsx`, `resources/js/Pages/Public/Events/Show.jsx`, `resources/js/Pages/Tenant/Community/People.jsx`, `Overview.jsx`, `MessageComposer.jsx`, `resources/js/Layouts/consoleNav.js`. Create public `resources/js/Pages/Public/Community/{Organisation,CommunitiesPanel,MemberClaim,Join}.jsx` and tenant `resources/js/Pages/Tenant/Community/{Followers,ClaimsPanel,JoinSettingsPanel}.jsx`. Create `app/Http/Controllers/Public/CommunityOrganisationController.php` with public tenant `GET /community/about` outside the authenticated route group; keep console `/community` unchanged. Add public follow/join CTAs and explicit return URLs through platform `/my`, allowlisted to current platform/tenant hosts. Test `resources/js/test/communityIdentity.test.jsx`, `communityFollowing.test.jsx`, `communityJoining.test.jsx`, `communityClaims.test.jsx`, and `tests/Feature/Community/CommunityPublicPagesTest.php`.

**UI contract:** Email is the default tab, “Use a phone number instead” is secondary. Offer a basic Communities panel after existing verification, without replacing current ticket/history UI or introducing milestone 4 navigation. Add Follow/Unfollow on public organisation and published event pages; Follow never silently joins. Phone-only claimant sees “Verify your membership” and their submitted request status, not matching member cards. Credential display is a dedicated no-store page with copy/print/download controls and a warning to keep it private; not a toast that disappears or an Inertia prop retained in history. After leaving the page, retrieve only status, never the secret again.

- [x] Write failing interaction tests for email-first focus, optional phone, code cooldown/retry, claim-required empty state, distinct family member cards after approval, role labels, explicit re-follow consent, disabled join/no type, approval/rejection controls, unsaved dismissal and stale preview. Example:

```jsx
it('offers email before phone', () => {
    render(<VerifyPrompt onVerified={() => {}} />);
    expect(screen.getByLabelText(/email/i)).toBeVisible();
    expect(screen.getByRole('button', { name: /use a phone number instead/i })).toBeVisible();
    expect(screen.queryByLabelText(/phone number/i)).not.toBeInTheDocument();
});
```

Use the existing VerifyPrompt test setup/actual props and mocked Inertia routing. Test the user interaction rather than snapshotting implementation classes. When registrationId is supplied, preserve its existing email-specific confirmation flow and do not offer phone verification as a substitute for ticket ownership. Add an explicit regression test for that mode.

- [x] Run focused JS files and the public-pages PHP file; confirm meaningful failures. Implement against Tasks 2–8's endpoints. Staff People shows Members/Groups/Followers when independently allowed; when Community is off, navigation links directly to Followers with event-announcement history, not a gated People route. Overview exposes pending join/claim/report queues only to manage-members staff; financial data remains permission-gated.
- [x] Public organisation page `/{tenant host}/community/about` shows public branding, upcoming published events, follow and configured join options. Never render membership counts/status or private contacts from an unclaimed number. Disable duplicate submit, retain errors, label all fields, use explicit button types and supported Modal props. Handle 401 expiry, 403 rollout/permission, 409 stale preview, 410 consumed claim, 422 validation and 429 budget/cooldown with useful recovery copy.
- [x] Add staff claim approval/denial, credential revocation/representative review, wrong-number review and join settings. Require independent-check method/reason; hide credential secret from staff. For explicit event announcements show audience/channel estimate, current consent exclusions, published event link and confirm action; never send on page load or publish.
- [x] Browser acceptance on isolated fake-data QA: email-only, phone-only new applicant, historic claim, family representative, revoked credential, follow with Community off, Free event announcement, unsubscribed overlap, light/dark, keyboard, 390px and desktop. Verify native dropdown expansion and modal geometry visually; do not rely on selectOption alone after the test-browser popup issue. No real phone, email, payment or production record creation. Pass focused tests/lint/build/Pint; commit `feat(community): complete email-first follow join and claim screens` with rebuilt assets.

## Task 11: Final review, evidence and controlled release handover

**Files:** Update this plan, `docs/superpowers/community-roadmap.md`, `AGENTS.md` only if a new durable invariant is genuinely needed. No new dependency or unrelated cleanup.

- [x] Re-read spec sections 6.1/6.3/8/13.1 against every endpoint, permission, screen, queue and cache. Ask: “Is there anything I have overlooked? Is the solution optimal? Let me take a step back.” Record uncovered limitations and explicit recommendations; no hidden placeholders. Run the post-implementation-review skill before calling the milestone built.
- [x] Run focused tests during each task, not a full suite per task. At the final source revision obtain one full quality gate: `PAO_DISABLE=1 php artisan test --parallel --processes=4 --compact`, `vendor/bin/pint --dirty`, existing PHPStan command with zero errors/no baseline growth, `npm run lint`, `npm run test`, `npm run build`. Prefer the existing CI full gate after focused local checks; run the full PHP suite locally only if CI is unavailable or a specific failure needs it, not routinely in addition to CI. Confirm CI's command covers the roadmap's full parallel suite. Use the dedicated PostgreSQL test database; separate race tests from refreshing suites. Retain passing output, not an empty formatter result. Verify `PayoutOtpFlowTest` and `finance:verify-categories` through existing test coverage/isolated test DB.
- [x] Obtain one fresh whole-branch independent review after the final source change; request particular scrutiny of phone recovery, representative boundaries, cross-tenant history, member-cap races, no-Community Following and SMS cost claims. Fix substantive findings with RED→GREEN tests. Avoid repeated broad reviews/checks without a new failure or change. The owner chooses native or delegated implementation during plan review; independent final review is required either way by the roadmap.
- [x] Record source/tree, test counts, browser evidence and remaining operational limits. Mark roadmap built only when those checks pass. Push/open PR for owner review when requested; do not merge or deploy merely because CI is green. Keep rollback additive: disable Following/new join/phone entry, revoke affected grants; never drop financial history or invalidate existing email ticket access.
- [ ] Before an owner-approved main merge, confirm a server-side production session driver (not `cookie`) and successful remote CI. After the successful auto-deploy, verify the exact production release and permission/migration success. Activate Following/approved Community switches for Purpledot under standing approval, preserving existing join default (joining still needs a public type) and all other organisations' rollout flags. Confirm platform SMS sender/cap configuration before enabling phone entry; if unavailable, keep email live and phone visibly unavailable rather than pretend delivery works. Never spend tenant credits for sign-in.
- [ ] Live read-only acceptance after sign-in: email-first entry, Followers/People/claim review availability, modal/dropdown layout, public follow/join availability and existing ticket access. Any real follow/join/OTP send requires applicable user test authorisation and a designated recipient; deployment approval alone does not authorise messages. Record deployed state and Purpledot flags in roadmap; leave other organisations unchanged.


## Self-review of this plan (2026-10-16)

- Coverage: independent Following/Free gates (Tasks 1, 5, 7, 10); email-first and independent phone proof (2, 10); historical claims/representatives (3); revocation/disputes/cache boundary (4, 8); join modes/caps/dues (6); audiences/consent/event announcements (5, 7); shared-name import collisions (9); review/release evidence (11).
- Existing work preserved: shared-phone schema and pending status already exist; do not rebuild them. Current 12-hour email proof and Event workspace authoriser remain authoritative. No milestone 4 redesign, Giving cutover or milestone 6 member prices is included.
- Corrections found during self-review: approval of an already-counted pending member must not consume a second cap slot; follower-only audiences must not run the current member-default source; follower sends must replace the current member-only transport eligibility check; Following needs its own staff page/announcement history when Community is off; public organisation route must not collide with console `/community`.
- Remaining limits disclosed for review: email remains the existing mailbox-control trust model, not proof of legal identity; administrator-assisted checks rely on documented staff judgement; no carrier-reassignment detection; shared phone holders can receive each other's OTPs, so private recovery credentials must remain separate. A compromised recovery credential plus control of the shared number compromises that one grant. Lost credentials require assisted recovery, not SMS-only reset.
- Existing pay links remain intentionally scoped bearer credentials. Legacy service messages can contain personal dues information/pay links; phone-claim safeguards protect portal access and do not retroactively prove ownership of every stored service contact. Task 4 suppresses known disputed destinations and stale queued contacts; completely replacing the service-message contact trust model is outside this milestone and must not be claimed as solved.
- Scope recommendation: implement natively in this session with focused tests and one final independent review. These tasks share tightly coupled access and consent contracts; repeating full contexts/reviews per task would add cost without removing the need for the whole-branch security review. Do not begin before the owner's plan review.


## Implementation self-review (pre-merge, 2026-10-16)

“Is there anything I have overlooked? Is the solution optimal? Let me take a step back.”

### Touchpoints updated

- Navigation: independent Followers/announcements, People access-review queues and Overview join settings; public organisation and event links; basic My Communities panel alongside existing Events.
- Permissions/seeders: reuse existing manage-members and send-community-message permissions. No new permission or role is required; independent Following remains outside Community/package gating. Existing deployment permission propagation retained.
- Configuration: platform phone enable/caps documented in `.env.example`; phone entry defaults off, no new dependency. Sign-in uses platform allowance, never tenant credits.
- Documentation: spec/plan/roadmap carry the approved email-first model and progress. No separate memory file is required. Existing AGENTS finance invariants retained.
- Tests: per-task RED→GREEN, private endpoint and receipt authorization, real-worker concurrency, consent/credits and import review, React interactions. Final whole-branch review and local verification results are recorded below; remote CI remains pending.

### UI gap analysis

- Discoverability, empty states, permission gates, duplicate-submit protection, loading/error recovery: present in public/staff flows. Milestone 4 navigation redesign remains out of scope.
- Dark/light desktop and 390px browser checks: readable fields, modal fits viewport, no horizontal overflow. Keyboard controls exercised. Native select controls were clicked and screenshots inspected; the browser screenshot captures the page, not the OS-native popup itself. Popup rendering across external browsers therefore remains a manual visual limitation, not a claimed screenshot pass.
- Browser QA: email-only exact membership access; phone-only no historical candidates before review; two independent representative approvals yield two labelled cards; one credential revoked removes only its card on refresh; pending join status survives refresh; Following works with Community off; explicit re-follow consent; Free scheduling disabled; opted-out address excluded from event-preview audience. Announcement was previewed, not sent. No production records, real emails, SMS or payments created. Prepared local hashed codes + mocked send responses tested real confirmation without transport; transport/budget paths are covered by fake-provider PHP tests.

### Solved during self-review

- Standard Laravel Resource collection constructor restored; contact-version/held-number checks, reviewed import stale snapshots and join exact-email collision safeguarded.
- Existing key recovery and wrong-number reporting survive rollout pause; new claims/enrollment remain gated. Confirmed wrong-number reports stop marketing, independent resolution does not revive old keys or consent.
- Scheduled event invitation cannot follow event end; permanently removing source event retains announcement history and pauses unsent work.
- Challenge widget cleanup, generic wrong-number report UI, accurate 12-hour key warning, private key page cleanup, dark form contrast and portal document title.
- Static analysis contracts corrected without baseline growth. Race-test worker host inherits configured database host for CI portability.

### Recommended for owner decision

- Before live phone enablement: confirm platform SMS sender and operating cap (proposed 500/day; five per number/day), recipient-authorised live transport test, and independent staff verification process. Keep phone entry off until then; email remains primary. No production enablement has occurred.
- Key loss requires fresh independent staff review. No carrier reassignment detection exists. Email mailbox control remains the existing ownership assumption; shared/reassigned email is not a universal person identifier.
- After an organisation pauses all phone-capable entry, new phone code requests remain unavailable; an existing key does not bypass SMS operational availability. Retained verified sessions and email access still work.

### Not gaps

- Pending new membership does not grant historical access or charge dues. Marketing consent remains a separate explicit action.
- Representative authority is per member; it grants no contact editing, delegation or ticket transfer. Existing bearer pay-link rules remain unchanged.
- Already accepted/in-flight provider messages cannot be recalled. Eligibility is rechecked immediately before transport; unknown submissions retain budget/credit claims for reconciliation.
- No merge, deployment, production migrations or real recipient tests are included in this authorization.


## Independent review and fix pass (2026-10-16)

Fresh `gpt-6-astra` whole-branch review of `b659e66..4ec3b85`: two Critical and six Important findings; no stylistic minors. Initial verdict was **not ready to merge**. Each finding was confirmed against source and reproduced in a focused regression before correction:

1. Persisted old session IDs survived sign-out/removal and proof/grant rotations. All portal rotations now destroy the old persisted record while retaining staff attributes. Native session blocking covers all stateful web requests using shared PostgreSQL locks, preventing an overlapping ordinary writer from restoring removed proofs. Dedicated additive lock-table migration; no identity/person model or new dependency. Persisted replay and actual-worker overlap tests cover it. The 600-second lease bounds supported request duration; longer work must be queued. Unknown aliases created before this release cannot be retroactively enumerated; legacy email proofs retain their original fixed 12-hour expiry.
2. Phone joining differed for historic and unseen numbers in immediate mode and at the cap. Every phone application now awaits review. Follow-up self-review found a second oracle through slot consumption: all pending phone envelopes now reserve one slot, without creating/linking a member. Approval converts that reservation inside the same cap transaction; rejection releases it. Staff explicitly confirms creation of a separate membership. Unknown and historic numbers have the same pre-review status, history/recovery denial and cap behavior. Immediate email joining remains available. This is an explicit security amendment to the spec, not a hidden UI-only mapping.
3. Distinct-person approval inherited default marketing eligibility. It now preserves only existing explicit follower consent using the stored channel/address; it never uses staff-session proof or enables an opted-out address.
4. Soft-deleted event sources were eligible during their recovery window. Source lookup preserves SoftDeletes and the final event check rejects trashed events, before dispatch and immediately before transport.
5. Email-first users could not add phone proof. Additive phone verification preserves email sign-in and reaches the exact-member recovery-enrolment action.
6. Community-off UI hid existing private-key recovery and generic wrong-number reporting. Both remain reachable with phone proof; new access requests remain closed.
7. Only the latest family claim could be completed. An owned-request selector exposes all capability-held requests by submitted name/role/status; each approval remains member-scoped.
8. A resend challenge was invisible and could reuse a consumed token. The challenge is available in both code stages, successful sends clear it, and failed/expired challenges require fresh completion.

Reviewer's declined-to-judge items retained explicitly: actual carrier/sender/cap readiness and staff identity-check quality need operational acceptance; native OS popup visuals remain a browser-capture limitation; unreported legacy service contacts keep the approved trust model. Full-suite results and built assets are verified by the implementer below. No second broad review is scheduled; substantive fixes use RED→GREEN plus final quality gates.

Final gate environment ruling: local PostgreSQL's stock lock table (100 connections ×64 locks) was exhausted by four simultaneous schema rebuilds even after removing the extra race suite. Fail-fast reproduction:4 SQLSTATE53200 setup failures/23passes in28s; no application assertion was implicated. Run the identical full Unit/Feature suite with two local workers and Infrastructure serially; do not tune/restart the shared local server. CI retains four workers and explicitly increases only its disposable PostgreSQL container's lock allocation to256 before testing. No production database or infrastructure setting is changed.

Post-fix browser confirmation: additive phone verification retained the email banner; paused Community exposed existing key restoration and generic wrong-number reporting but no new claim action; staff authentication survived these portal rotations. Remaining minor usability recommendation: recovery downloads currently have a generic filename; use distinct saved-key labels for family members (a small follow-up could add approved-member context to the one-time response and filename). Existing member cards and owned-request choices are labelled; this does not widen authority or block this milestone.


## Execution rulings retained for continuation

- Isolation: reuse the owned artifact-completion worktree, not Claude’s original checkout. Migrations sort after the existing October18 migrations; no production migration has run. Validation of source-event/public-type ownership lives in the endpoints consuming them, not unused setup code.
- Identity: email and phone each retain fixed, non-sliding12-hour proofs. Expired phone proof drops active grants but an owned pending claim/join capability may survive until fresh proof of the same phone; explicit removal/sign-out clears those capabilities. No person graph or phone-to-email inference. Direct phone enrolment requires independently proved matching email and recorded phone; representatives are approved separately per member.
- Security thresholds: OTP length/TTL/attempt/cooldown are fixed code bounds; daily platform/per-number budgets are configurable and default phone sending is off. The existing venue-friendly identity limiter replaces the unrelated registration limiter for phone sign-in. Unknown provider submissions stay counted; worker payloads contain no plaintext codes.
- Contact changes: revoke direct credentials, conservatively suspend representatives and expire outstanding approvals. Generic old/new contact notices use existing tenant delivery allowances; uncertain SMS outcomes use the existing reconciliation process. Missing legacy contact-version snapshots skip delivery rather than guessing. Existing lapsed/exited liabilities remain payable/remindable.
- Consent: follow only the explicitly selected proven address. New public membership never implies marketing consent, including distinct-person staff approval. Confirmed wrong-number reports withdraw marketing and access; independent resolution revives neither old credentials nor consent.
- Joining: uniform phone review/reservation is the security exception to immediate joining. Pending phone envelopes consume one cap slot without creating/linking a member; approval atomically converts the reservation, and rejection releases it. Approving an already-counted pending email member adds no extra slot. A retired requested type needs an explicitly chosen active replacement, with original and approved types audited. The cost of uniform phone handling is staff review even for genuinely new holders.
- Imports: keep every candidate, require an explicit skip/update/distinct decision and recheck the selected record under lock. Existing exact email is reserved even when a phone exists. Remove only the name/phone uniqueness constraints that contradict reviewed distinct people; rollback refuses legitimate duplicates rather than deleting/merging them. Previews are server-HMAC-bound to actor/tenant/stored rows, with25 candidates per row and10,000 references per preview; excessive rows error explicitly, requiring smaller batches or individual review.
- Audience delivery: separate bounded member/follower cursors and microsecond consent cutoff; new follows after dispatch begins belong to the next announcement. Trusted event context selects the narrow Following purpose, never a caller-supplied purpose switch. Published private event sources are eligible with an explicit access-rule warning; public organisation listings still exclude them. Soft deletion/unpublication and consent are rechecked before transport.
- Existing history: retain authorised financial history and valid-key restoration with Community paused. New enrolment/claim/join actions still check live switches; new OTP sends still need an enabled organisation context. SQL-paginate authorised IDs/email only, never unclaimed phone candidates. Receipts reuse the existing PDF service and re-authorise every request.
- UI: follow the actual React/Tailwind Console patterns rather than stale Metronic examples in the design skill. Same-origin tenant action mirrors reuse the guarded services; return URLs are server-allowlisted. The basic Communities panel is complete for this milestone; the five-tab app/navigation rebuild belongs to milestone4. Secrets appear once in dedicated transient UI, not Inertia props/history/storage.
- Concurrency: member-before-claim lock order was proved against actual workers. Persisted session replay requires destructive rotations plus shared native blocking across ordinary web writers too. The only delegated work was the final fresh-context review; implementation stayed native.
- Verification: formatter exit0/empty output is not a test result; require actual counts and worker completion markers. Use PostgreSQL-only isolated databases, cluster-serialise broad/race suites, and preserve the owner’s server configuration. Local full gate uses two workers; disposable CI is configured for four. Do not run a second full suite merely to satisfy a bookkeeping wrapper after the verified command already ran.
- Release: keep the branch/worktree for owner review; no merge/deploy/push is implied by green local tests. Production permission/migration checks, exact-release verification and Purpledot activation remain the conditional release steps below. Standing approval for approved/deployed Purpledot pilots remains; operational SMS enablement still needs its sender/cap/recipient acceptance.


## Final pre-merge evidence (2026-10-16)

Backend source: `a1f5540` on `codex/community-3`, based on `b659e66`. The final follow-up only changes the client link, its regression test and rebuilt assets; PHP source is unchanged from the full PHP verification. Implementation, review fixes and final evidence are committed locally.

- Full Unit/Feature suite: **2,844 passed, 13,638 assertions**, exit0, `PAO_DISABLE=1` with two local parallel workers;1,215.24seconds. The earlier inventory count did not represent the final parametrised run count; the actual completed report is authoritative.
- Full Infrastructure suite, separate isolated database: **27 passed, 200 assertions**, exit0,57.29seconds. Total PHP: **2,871 passed /13,838 assertions**. Includes real session-writer/OTP/claim/follow/join/import/delivery races, `PayoutOtpFlowTest` and `finance:verify-categories` coverage.
- Full JavaScript suite: **318 passed in46 files**, exit0. Local CLI used two workers/20-second timeout under CPU contention; repository/CI timeout was not weakened. The strengthened fourth-send consumed-token assertion also passed in the focused7-test review suite.
- PHPStan:0 errors, baseline unchanged. Pint passed; ESLint passed; Node24 asset build passed. All4 manifest entries point to existing tracked assets. Existing large-chunk warning remains; no dependency manifests/locks changed.
- Browser: isolated fake-data QA only. Email/phone independence; zero historic cards before approval; two member-scoped family grants; revoking one leaves the other; pending joining; Community-off Following; Free scheduling denied; opted-out preview audience excluded; dark/light desktop/390px layout and readable fields; transient key UI/storage cleanup; additive phone verification and paused recovery/report controls after fixes. Private history responded200 with `Cache-Control: no-store, private`. No real outbound transport or payments used.
- Fresh independent reviewer found2 Critical and6 Important issues at `4ec3b85`; all were accepted and corrected in `a1f5540`, with RED→GREEN tests and the full gates above. The reviewer did not re-review the fix commit; the implementer verified the targeted changes and final regression results. The generic recovery-download naming improvement remains a disclosed minor follow-up.
- Final UI self-review: retained private history no longer links to an unavailable public organisation page when both Community and Following are disabled. The new regression failed before the fix and passed afterward; the complete318-test JavaScript suite, ESLint and rebuilt assets passed. Isolated browser acceptance confirmed the public link was absent while the owner’s private history remained available. No PHP source changed, so the full PHP gate was not repeated for this client-only fix.
- Remote CI has **not** run: this branch has not been pushed or made a PR. The CI-only lock-capacity change parsed as valid YAML; container execution is to be verified on the PR before merging. Four local rebuilds were invalidated by stock PostgreSQL lock exhaustion; no shared local server setting was changed. Do not claim those failed/aborted attempts as passes.
- Claude’s original checkout remains clean on `community-1a` at `0173494`; its `.env`, branches, uncommitted files and owner data were not modified. Production was not changed. Keep the owned worktree for review. Task11's final two release steps remain pending the owner's go-ahead.

## Approved release verification (2026-10-16)

- Owner explicitly authorised push, merge and deployment; PR #28 targets main, which still matched base `b659e66` before release. Production uses Redis sessions, meeting the server-side session requirement. Purpledot Community was already on; Following was off. All eight other organisations had both switches off.
- First remote run `38893022753`: quality job passed; PHP2,870 passed/one failed. A focused local parallel reproduction confirmed that Laravel’s parallel cache prefix was not inherited by the session-race subprocess. Pass the actual parent lock-store prefix through `CACHE_PREFIX`; do not weaken the lock assertion or change production code. Local serial acceptance had not exercised this parallel cache-prefix boundary.
- Corrected fixture: focused local parallel `PhoneVerificationRaceTest` passed3 tests/24 assertions in52.01seconds; Pint passed. Application/backend code and assets are unchanged by this test-only correction.
