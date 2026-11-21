# Safe multi-organisation membership — audit and implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans only after the user authorizes implementation. Implementation authorized by the user on 20 November 2026. Production rollout remains a separate controlled action.

**Goal:** One person can use the same verified email and credentials to own or join multiple organisations, with independent roles, event assignments and revocation in each.

**Architecture:** Retain global `User` identity, extend the existing landlord `tenant_user` relationship into the authoritative membership lifecycle, retain tenant-scoped Spatie roles, and use explicit verified tenant context at every entry point. Do not create duplicate identities or a parallel role system.

**Tech stack:** Existing Laravel, Spatie Permission teams, Sanctum, Inertia/React console, Blade authentication, landlord database and queues. No new dependency is proposed.

**Spec:** User's 19 November 2026 request: audit current membership/login flows, plan safe application-wide support, self-review, and do not implement yet. Superseded for local implementation by the user’s 20 November instruction: “update the plan and proceed.” Production deployment remains outside this approval.

## Audit scope and evidence

Read-only source audit against `origin/main` at `647d1310759e7d3315c229cdc962871403251923`. The checkout is `ef418e270c404372d98e0d2860af812ba62dd7a8`; its four differing files were compared with main. Two independent audit passes covered team administration and non-browser isolation. During that original audit, no application code, dependencies, configuration or production data were changed, and no live exploit, database reconciliation or test execution was performed. Local implementation was subsequently authorized; its results and remaining release gates are recorded below. Findings below describe source behaviour; they do not establish historical exploitation.

### What already works as a foundation

- `tenant_user` has a composite tenant/user primary key; `User::tenants()` already supports multiple memberships.
- Spatie teams are enabled with `tenant_id`. Existing tenant role ceilings, owner guards, event-scoped organisers and membership-aware event checks should be reused and strengthened.
- `TenantSwitchController` already has a membership-based switch and audit entry. Request context reset and substantial attendee-portal proof/session protection already exist.
- Existing tests cover tenant switching, role ceilings, event restrictions and sequential team seat limits. These are useful regression coverage, not proof that alternate routes or concurrent lifecycle operations are safe.

### Findings requiring containment before rollout

1. **Legacy central account routes bypass newer tenant protections.** `routes/web.php:83–97` puts user management behind authentication/2FA/onboarding, without a platform-only boundary. `Admin/ResendAccountPasswordController.php:23` rotates a bound user's password without target authorization. `Admin/UsersController.php:275,337` exposes edit/deletion without corresponding action authorization. `Requests/User/UpdateUserRequest.php:21,52` and `Admin/UsersController.php:292` allow weaker target/role checks than the tenant flow. An authenticated qualifying caller can reach these routes; actual production use was not tested. Password resend alone establishes unauthorized disruption, not disclosure to the caller.
2. **Removing a team member deletes their global identity.** `Tenant/UserController.php:205` detaches one membership then deletes `User`; the membership FK cascades and Spatie removes roles across teams. This can remove access or the final owner in another organisation. Selected badge-history guards do not make global deletion safe.
3. **Organisation administrators edit global identity.** `Tenant/UserController.php:153` changes global email, phone, names and status. Email edits do not clear verification. Browser `LoginRequest.php:45`, API `Api/V1/AuthController.php:48` and general membership resolution do not consistently enforce global account status; some event-specific checks do. Enforcing status alone would still let one organisation disable the person everywhere.
4. **Two incompatible membership representations remain.** Team listing/update/removal use `users.tenant_id` (`Tenant/UserController.php:37,143,179`), while admission and seats use the pivot. Existing emails cannot be invited (`StoreTeamMemberRequest.php:33`). Legacy `Admin/TenantController.php:512` changes the global primary field when navigating. Secondary members can therefore consume seats and have access while being absent from the team screen.
5. **Alternate team writers bypass safeguards.** `Admin/TeamController.php:32,185` lacks equivalent target authorization, validated role scope and seat enforcement. `Admin/UsersController.php:209` is another creation path accepting a tenant and omitting seat checks. Tenant role selection excludes Superadmin but does not explicitly exclude all platform-only roles; assigning those under a tenant is not itself proof of global platform access.
6. **Invitations currently deliver passwords.** `Tenant/UserController.php:104` immediately creates identity/access and queues `SendAccountDetails`; the reusable password is serialized and printed in mail. No acceptance lifecycle exists. Dispatch happens inside a transaction while queue `after_commit` defaults are false. Rollback/delivery races depend on queue topology and were not reproduced.
7. **Seat and last-owner checks are not serialized.** Seat counting precedes the creation transaction; owner counting is not locked through mutation. Two simultaneous changes can defeat each check. Existing final-owner tests partly exercise earlier self-protection guards rather than the owner-count race.

### Other application surfaces that must move together

- **HTTP/session:** `TenantResolver`, `ResolveTenant`, login controller and login listener have overlapping selection paths. `ResolveTenant` has a session fallback outside resolver validation. `Handler.php:47–63` logs users out globally on HTML membership failure; visiting an inaccessible workspace should instead preserve legitimate unrelated access. A session selection is not an access grant.
- **Workers:** `AppServiceProvider.php:183` restores tenant context only for a payload naming an existing tenant; there is no matching lifecycle cleanup. Tenantless/deleted-tenant/failing jobs can inherit database, storage, permission team and URL state from the prior job. No misdirected production job was demonstrated.
- **API credentials:** `AuthenticateWithApiKey.php:60–82` does not recheck its linked user's membership/status or set the permission team; inspected routes do not enforce stored key scopes. Sanctum tokens are user identities, not organisation membership grants. Existing independent mobile registration must remain possible.
- **Broadcasting:** `routes/channels.php:24,39` checks the primary tenant field before event permissions, blocking valid secondary members. Authorizing a subscription once also does not provide immediate revocation of an already-open connection.
- **Impersonation:** `Admin/ImpersonateUserController.php:39,62,88` bases target scope on the primary tenant, then logs in as the whole user; stop leaves `active_tenant_id`. Cross-organisation exposure is conditional on granting impersonation to a non-platform actor.
- **Caching:** `AuthServiceProvider.php:48` caches platform-superadmin access for an hour without an identified revocation invalidation path. Changing Spatie team context also requires clearing loaded roles/permissions on user instances.
- **Domain services:** primary-tenant-or-membership fallbacks occur in Community payouts, dues reminders, Giving policies/services, community audiences/messages/access, store access and collection controllers. Revocation must not depend on updating both representations. Existing permissions remain separate checks; these fallbacks do not prove unrestricted financial access.

## Target behaviour and recommended policy decisions

1. **Identity belongs to the person.** Global email, password, profile, account status and 2FA are controlled by the account holder or an explicitly privileged platform recovery operation. Tenant administrators manage membership only. Existing addresses are invited without revealing other memberships, resetting credentials or modifying the account's profile.
2. **Membership belongs to the organisation.** Extend `tenant_user` with `status` (`active`, `suspended`, `revoked`), accepted/joined/suspended/revoked timestamps and actor metadata. Retain the composite key. Use a custom pivot/domain model and one membership service; roles remain in Spatie's team-scoped tables. Suspension preserves the configured role/assignments but blocks access; revocation removes only that tenant's role/direct-permission and event grants. Rejoining a revoked membership needs a new accepted invitation and fresh explicit grants.
3. **Ownership stays an existing role.** An active owner is an active global identity plus active membership plus tenant-scoped Org Superadmin role. Do not introduce a second competing owner flag. Protect the last active owner through tenant-row serialization; include platform account suspension/deletion and organisation closure, not only team edits. Exceptional platform emergency action must explicitly transfer ownership or suspend the organisation, with audit evidence.
4. **Tenant context is explicit.** A validated host/custom domain determines browser console context. Platform workspace selection only chooses navigation. API tenant selectors require authentication plus active membership or an explicit organisation service credential; headers never override an established host. Unknown, banned or inaccessible tenants fail closed. Public event browsing and attendee capabilities remain separate from console membership.
5. **Invitations require consent and mailbox ownership.** Separate landlord invitation records carry tenant, normalized invited email, intended tenant-safe role/event grants, inviter, hashed random token, expiry and lifecycle. Proposed validity: seven days. GET displays only; acceptance is POST after verified matching email and authentication/required 2FA. A fresh mailbox proof is required for an existing unverified account; never allow an invite to take over a preclaimed identity. New users set their own password and accept without automatically creating an organisation. Wrong-account sessions get a safe sign-in choice, not automatic reassignment.
6. **Invitation changes are constrained.** Acceptance rechecks tenant availability, inviter's current authority and grant ceiling, role/event validity, quota and expiry under lock. If the inviter lost authority, require an authorized administrator to reissue. Revoke/resend rotate tokens; old tokens stop working. Already-active members get a no-op response; invitations never overwrite their grants. Resending is rate-limited and does not repeatedly reserve seats. No tokens/passwords in logs or audit payloads.
7. **Seats count active and suspended memberships plus unexpired pending invitations.** Suspension must not become a quota bypass. Revoked memberships do not consume seats. A pending invite to an active/suspended existing membership does not add another seat; suspension is handled through the member action, not an invite. Reservations expire by timestamp without depending on a scheduler. A downgrade does not evict current members or invalidate already-reserved seats; it blocks net-new reservations until within allowance. Owner creation remains supported on existing plans.
8. **Staff revocation applies to every entry point.** Recheck membership on each console/API request and sensitive queued operation. Tenant-linked user API credentials lose access in that tenant; personal/mobile tokens retain independent non-tenant capabilities and other permitted memberships. Explicit organisation service keys need their own scoped/revocable authority, not accidental access through a former user's identity. Classify existing credentials before migration; ambiguous ones cannot gain new authority.
9. **One identity can create another organisation.** A verified signed-in person can choose Create organisation without another password/account. New organisation ownership, billing authority, plan eligibility, provisioning and onboarding follow existing rules; existing memberships remain unchanged. Platform-origin account/workspace pages work with zero memberships, allowing safe recovery from a revoked final membership. No automatic merge by similar names, phone numbers or Gmail-specific email rewriting.
10. **Keep global 2FA coherent.** Every target organisation enforces its policy on entry. Recommended account setting: disabling global 2FA is refused while any active membership requires it, preventing a weaker organisation's settings from bypassing a stronger one's policy.

## Global constraints

- Local implementation authorized on 20 November 2026. Do not modify production or live memberships under this implementation approval.
- Keep all money, ledgers, ownership of historical transactions, billing authority, commercial terms, live-event protection and attribution unchanged. Membership is access, not payment ownership.
- Keep attendee/import/Giving/store verified-email capabilities independent from staff login and organisation membership. Keep shared landlord session blocking, destructive session regeneration and persisted-cookie replay protections. Do not log out unrelated staff or rotate attendee proofs as a side effect of ordinary workspace switching.
- Keep null-tenant platform roles distinct. Existing tenant-safe permission lists, `PermissionCeiling`, `PermissionsSeeder` and `RolePermissions` are authoritative; never authorize by email.
- Landlord membership mutations lock tenant first, then membership/invitation rows in deterministic order. Operations spanning several tenants lock sorted tenant IDs first. Do not call event admission/finance/calendar writers while holding these locks; preserve their existing lock contracts.
- Do not infer new access from historical financial rows, a mutable primary tenant field, or email-domain similarity. Migration discrepancies require explicit review.

## Review refinements — 20 November 2026

- **Email ownership/recovery:** normal email changes require recent password/2FA confirmation, proof of the new mailbox, and notification to the old mailbox. Never automatically attach membership by a changed address or a recycled work mailbox. Invitations bind the exact verified address/revision; old invitation proofs become invalid after an address change. Recovery without the old mailbox is an audited platform operation, not an organisation administrator override; preserve financial/attendee history ownership independently.
- **Account-wide security:** password reset/confirmed compromise/global suspension revoke staff sessions and personal API tokens account-wide; organisation suspension/revocation invalidates only that organisation authority. Use a global security epoch stored on User and captured on staff sessions/tokens where native revocation is insufficient. Test remember-cookie replay, pre-existing sessions and recovery across two organisations. Keep independent attendee grants governed by their existing proof contracts.
- **Invitation abuse:** apply sender, organisation, recipient and IP limits, including acceptance attempts. Defaults: sender 20/hour, organisation 50/hour, recipient 3/hour, IP 60/hour; existing application mail budgets still apply. Revoke frees a reservation immediately; expired reservations stop counting without a scheduler. Cleanup removes secret/delivery payload after 30 days while retaining minimal audit outcomes. Repeated sends do not extend a reservation beyond seven days from its original creation.
- **Revocation contract:** requests beginning after commit must deny revoked authority. Already-running sensitive operations revalidate at their existing transaction/commit boundary; legitimate accepted payment reconciliation continues. Background user-directed operations check authority on execution. Open sensitive broadcast connections must stop receiving new data after revocation commit; inspect supported disconnect capability first and use versioned publication channels only if disconnect alone cannot enforce the contract. Explicitly test in-flight publication races. Offline exports cannot be recalled; online sync denies after commit.
- **Operations:** the platform administrator reviews migration discrepancy reports and owner conflicts; the organisation owner manages invitation resend/cancel and seat conflicts. Platform support resolves uncertain delivery after provider reconciliation and resumes failed provisioning through an idempotent retry. Expose actionable state, timestamps and safe failure details; do not silently retry ambiguous delivery or provision duplicate tenants.
- **Policy scope:** seven-day expiry, reserved pending seats, suspended seats counting and platform-only impersonation are the defaults for this implementation. Keep existing single-account 2FA and enforce the target tenant policy; refuse disabling it when another active membership requires it. Reuse native infrastructure before adding channel generations or a new epoch mechanism where existing facilities already satisfy the tests.
- **Rollout control:** `MULTI_ORGANISATION_ACCESS_ENABLED` defaults to false. Enable only after reconciliation and all application/worker instances run the new readers. Tests explicitly enable it and cover the disabled boundary.
- **Release sequence:** containment is independently shippable; new membership lifecycle writers remain disabled until every relevant reader has migrated. No partial rollout may expose suspension/revocation with membership-by-existence authorization still active.

## Delivery plan

Each task is a small reviewable change or closely related set of PRs. Implement test-first, run only the affected tests, run Pint on changed PHP, then review the user-facing touchpoints. The original task checklists below describe acceptance gates; they are not a claim that staging or production checks ran. The implementation record below identifies the actual files and verification performed; some proposed test names were consolidated into existing suites.

### 1. Contain legacy account/team entry points

**Modify:** `routes/web.php`; `Admin/UsersController.php`; `Admin/TeamController.php`; `Admin/ResendAccountPasswordController.php`; `Requests/User/{StoreUserRequest,UpdateUserRequest}.php`; `Services/Authorization/PermissionCeiling.php`; relevant role-selection UI. Resolve paths under `app/Http` unless a service is named.

**Interface:** every central account/team action requires an explicit existing platform gate plus target policy; ordinary organisation actions go through the tenant endpoints. Retire credential-generating resend, replacing it with an authorized, throttled account-setup/recovery link that does not change a password before redemption. Do not simply hide navigation.

- [ ] Add `tests/Feature/Users/CentralUserManagementAuthorizationTest.php` (new): ordinary authenticated user and tenant owner cannot read/edit/delete/reset foreign or platform identities; no self-escalation through central update; legacy team creation cannot choose a foreign tenant or platform role.
- [ ] Add regression assertions for every resource action and old URL; authorized platform operations retain owner/history protections. Determine legitimate central consumers from route/UI references before retiring endpoints.
- [ ] Implement route and action checks, tenant-role allowlist and recovery-link flow. During containment, refuse global deletion/deactivation of an identity with memberships unless the existing operation can prove all ownership/history invariants; task 3 adds the complete shared lifecycle. Ensure no remaining duplicate writer can bypass the containment boundary.
- [ ] Run `php artisan test --compact tests/Feature/Users/CentralUserManagementAuthorizationTest.php` and affected existing user/role tests. Expected: all pass. This is the first release gate, before invitation or switcher UI.

### 2. Expand membership schema and reconcile legacy data

**Modify:** `app/Models/{User,Tenant}.php`. **Create using Artisan:** landlord migration extending `tenant_user`, `app/Models/TenantMembership.php` custom pivot, and `app/Console/Commands/AuditOrganisationMemberships.php`.

**Interfaces:** `TenantMembership` exposes typed status/timestamps; `OrganisationMemberships::isActive(User $user, Tenant $tenant): bool` becomes the canonical access predicate in task 3. The audit command is dry by default, tenant-bounded, and reports counts/identifiers without emails or secrets unless explicitly requested securely.

- [ ] Add `tests/Feature/Tenancy/OrganisationMembershipsTest.php` (new): idempotent backfill, composite uniqueness, legacy global inactive/null statuses, pivot-only secondary member, orphan role, primary-only user, and missing-final-owner discrepancy.
- [ ] Add columns without dropping the primary field. Backfill existing pivot rows only; preserve global account restrictions. Report mismatches rather than attaching primary-only users or promoting owners automatically. Canonical-email audit detects case collisions before any new canonical unique constraint; do not silently merge.
- [ ] Define the transition explicitly: old pivot rows remain readable during expansion; only enable authoritative status enforcement after reconciliation/backfill validation. New states cannot be rolled back to old membership-by-existence code once suspension/revocation is used.
- [ ] Run the new migration tests and existing `tests/Feature/Tenancy/RbacScopingTest.php`. Expected: pass, with no additional access granted by backfill.

### 3. Centralize membership mutation and ownership/seat rules

**Create:** `app/Services/Tenancy/OrganisationMemberships.php`. **Modify:** tenant team controller/requests, legacy writers retained by task 1, `resources/js/Pages/Tenant/Team/{Index,TeamFormModal,Form}.jsx`, and global account deletion/suspension paths.

**Interfaces:** `isActive(User, Tenant): bool`; `updateAccess(Tenant, User $actor, User $subject, Role $role, array $eventIds, string $status): TenantMembership`; `revoke(Tenant, User $actor, User $subject): void`. Validate explicit status enum and actor ceiling; role/event/status mutations are atomic on landlord with tenant-first locks. Central writers delegate rather than implementing a second lifecycle.

- [ ] Extend `TeamIndexTest`, `TeamMemberInvitationTest`, `PermissionCeilingTest`, `TeamSeatLimitTest` and `EventOrganiserScopeTest`: a shared A/B user is listed in both; A cannot edit identity or grant B roles/events; removal from A preserves B, global credentials and audit history; rejoin cannot revive stale direct permissions.
- [ ] Add `tests/Infrastructure/Tenancy/MembershipConcurrencyTest.php` (new), using real PostgreSQL workers: competing last-seat claims, two owners demoting each other, revoke versus update/accept, and global identity deactivation spanning multiple owned tenants. Assert one consistent result and at least one active owner in any active organisation.
- [ ] Implement team membership status/role/event controls; remove global profile/status/delete fields from team payloads. Replace global user deletion with membership revocation in organisation flows, preserving retained history.
- [ ] Run the named feature files and the new concurrency file. Expected: pass. Seat rules must include plan add-ons and existing over-limit organisations.

### 4. Make request, login and worker context consistent

**Modify:** `TenantResolver`, `TenantContext`, `TenantSwitchController`, `ResolveTenant`, `ResetTenantContext`, `AuthenticatedSessionController`, `LoginRequest`, `SetTenantIdInSession`, `Handler`, `AppServiceProvider`, `Jobs/Middleware/TenantAwareJob`, `AuthServiceProvider`, and legacy `Admin/TenantController::changeTenant`.

**Interface:** resolver returns only server-validated context; no middleware fallback can revive a rejected selection. Every team change clears loaded role/permission relations before authorization. Active membership is checked fresh, not through a loaded collection. User global inactive status is enforced independently of tenant suspension; reconcile legacy null-status mobile identities explicitly rather than silently locking them out. Worker scope is established and restored/cleared with a finally-equivalent lifecycle on success, exception and tenantless work.

- [ ] Extend `AuthenticationTest`, `SetTenantIdInSessionTest`, `TenantSwitchControllerTest`, `ResolveTenantMiddlewareTest`, `TenantResolverTest` and `CrossTenantSafetyTest`: stale/deleted/suspended selections; host versus conflicting session/header; safe intended redirects; two tabs on different tenants; authenticated foreign public event browsing; no global logout from membership denial; no-membership workspace access; global disabled account denied.
- [ ] Extend `JobDispatchTest` and `TenantAwareJobMiddlewareTest`: same worker runs A → tenantless → missing tenant → B, with exceptions and child dispatch. Assert database, disk, URL defaults, logging context, permission team and user relation cleanup. A declared missing tenant fails closed; it never runs in inherited context.
- [ ] Remove or synchronously invalidate the one-hour platform-access cache on every role mutation path. Test immediate platform-role revocation and reuse of a user object across team changes.
- [ ] Preserve public Giving exception and independent portal proof handling. Re-run `PortalSessionRevocationTest` including persisted-cookie/overlapping-request scenarios. Expected: all affected suites pass.

### 5. Add secure new/existing-account invitations

**Create using Artisan:** landlord `organisation_invitations` migration, `OrganisationInvitation` model, `OrganisationInvitations` service, invitation Form Requests/controller and queued invitation mail. **Modify:** tenant team request/controller/UI, `routes/auth.php`, `routes/subdomain.php`, account-setup Blade views, public mail rate limits and legacy password-mail callers.

**Interfaces:** `invite(Tenant, User $actor, string $email, Role $role, array $eventIds): OrganisationInvitation`; `accept(string $token, User $actor): TenantMembership`; `revoke(OrganisationInvitation, User $actor): void`; `resend(OrganisationInvitation, User $actor): void`. Persist a token hash for verification and encrypted delivery material until delivery/retention cleanup; never retain a plaintext token. Tokens bind tenant, invited email and invitation revision. Email links use a public invitation UUID with the proof in a URL fragment, then submit it in the POST body so access logs do not receive the secret. Use landlord transactions, tenant-first locks, after-commit dispatch and a durable delivery record.

- [ ] Add `tests/Feature/Tenant/OrganisationInvitationsTest.php` (new): new user, existing verified/unverified user, wrong signed-in user, suspended/revoked membership, duplicate active invitation, already-active member, expired/revoked/rotated token, email-case collision, changed role/event, revoked inviter, scanner GET, replay/concurrent accept, and full-seat acceptance against a valid reservation.
- [ ] Implement password-free invitations, authenticated POST acceptance and verified invited-email binding. Pending acceptance must survive login/verification redirects using server-side state, without open redirects or leaking token URLs through analytics/referrers. Invitation responses must not reveal other organisations or global account existence.
- [ ] Add rollback-with-no-mail and send-retry tests. Delivery retries are deduplicated locally; ambiguous provider outcomes remain visible/uncertain rather than automatically replayed. Do not promise exactly-once external email delivery. Redact tokens from queue/error telemetry; confirm transport envelopes do not serialize reusable passwords.
- [ ] Reuse signup/reset recipient/IP/application mail budgets. Extend `PublicMailRateLimitTest` and invitation rate-limit tests. Expected: pass without disabling legitimate tenant transactional email.

### 6. Add workspace selection and existing-user organisation creation

**Modify:** `RegisteredUserController`, `TenantProvisioner`, `TenantSwitchController`, `HandleInertiaRequests`, `resources/js/Layouts/ConsoleLayout.jsx`, `resources/views/auth/{login,register}.blade.php`, tenant account UI and onboarding/billing handoff. **Create:** platform-origin workspace list/create controller, Form Requests and Inertia page in the existing console structure.

**Interface:** signed-in verified `createOrganisation(User $owner, array $validated, string $requestKey): Tenant` reuses existing provisioning, plan/region and billing rules; session switching changes navigation only. Workspace DTOs contain id/name/slug and safe membership display, never secrets or full Tenant models.

- [ ] Extend `RegistrationTest` and add `tests/Feature/Tenancy/OrganisationCreationTest.php` (new): existing identity creates B without altering A; repeated request key returns the same result; failed dedicated provisioning remains recoverable and never advertises a ready workspace; paid/free onboarding and owner billing permissions preserved; tenantless mobile user can explicitly create an organisation.
- [ ] Model provisioning as resumable stages. Dedicated database creation cannot be rolled back inside a landlord transaction; do not wrap external provisioning in a transaction and claim atomicity. Navigation/owner access only activate at the supported ready stage; retries do not create extra users/tenants or billing charges.
- [ ] Implement mobile-friendly switcher, active organisation label, safe unavailable-workspace state and Create organisation. Scope intended redirect to validated local routes/tenant origins. Preserve platform staff destinations and required 2FA; account email changes require proof, reset verification and use existing capability-revocation rules without transferring attendee history.
- [ ] Add frontend tests for switching/mobile/loading/failure states and browser verification for two tabs, invite-login-return, zero memberships and stronger-2FA target. Expected: correct organisation branding/data and no permissions crossing tabs.

### 7. Move APIs, channels, impersonation and domain services to canonical membership

**Modify:** `AuthenticateWithApiKey`, `Api/V1/AuthController`, `Settings/ApiTokenController`, `UserProfileResource`, `routes/channels.php`, `Admin/ImpersonateUserController`, `EventStaffScope`, primary-field consumers in Community/Dues/Finance/Giving/Marketplace and staff-door authorization/cache handlers.

**Interfaces:** tenant operations require task 3's active predicate plus existing ability/event checks; personal tokens authenticate identity only. Organisation-scoped keys declare tenant/scopes and are checked every request. Impersonation is platform-only, explicitly audited, with a bound tenant context and blocked workspace/token-creation escape; stopping restores original identity/context without reviving stale grants.

- [ ] Extend `ApiKeyAuthenticationTest`, API auth tests, `StoreOrderAccessTest`, `DuesPayoutTest`, event organiser and Giving permission tests. Include revocation with stale `users.tenant_id`, retained roles, old key/token, queued sensitive action and two simultaneous sessions. Preserve mobile-only/non-tenant API capabilities.
- [ ] Add `tests/Feature/Tenancy/BroadcastMembershipTest.php` (new): permitted secondary member succeeds; foreign event and revoked/suspended member fail. Protect already-open subscribers by sending only refresh notices and fetching private data through a fresh authorized HTTP request. Do not send names/seat details on a once-authorized socket. Channel generations are unnecessary with this contract. Test publication after revocation, not merely fresh channel authorization.
- [ ] Revalidate actor authority when executing user-requested sensitive jobs; preserve already-accepted payment fulfilment, refunds/reconciliation and immutable commercial snapshots even if the initiating staff member leaves. Classify jobs rather than cancelling all tenant work on membership change.
- [ ] Audit all primary-field reads structurally, including aliases and query-builder filters, not only a `user->tenant_id` search. Classification must cover CLI seed/provision/recovery commands, user resources, reports/exports, audiences, storage, notifications and both central/tenant UI. Replace authorization/recipient selection with explicit membership/context; retain a documented temporary compatibility field only where it is not authority.
- [ ] Clear staff offline caches on account/organisation changes, isolate cache keys by identity/tenant and refuse revoked online synchronization. State the limit: already-downloaded/exported data cannot be remotely erased from an offline device. Test device cache transitions and existing attendee service-worker separation.
- [ ] Run the affected feature tests plus `MembershipConcurrencyTest`. Expected: no cross-organisation access and preserved financial/portal invariants.

### 8. Roll out with explicit migration and rollback gates

**Modify:** existing deployment/check procedures only where needed; `PermissionsSeeder` and `RolePermissions` together only if a new permission is introduced. Add no dependencies or broad RBAC rewrite.

- [ ] First ship task 1 containment; then additive schema and worker/context fixes. Run read-only reconciliation in each environment with an explicitly reviewed report before status enforcement. Resolve missing owners, primary-only users, orphan grants and canonical-email collisions without silently assigning access.
- [ ] Enable authoritative membership enforcement and move every entry point before exposing invitations/create-organisation UI. Keep suspension/revocation writers disabled until all legacy pivot-existence readers are replaced: a custom pivot alone does not filter `User::tenants()` queries. Distinguish active-workspace navigation from all-membership history explicitly. Existing membership data must not be judged safe merely because the new screen is hidden.
- [ ] Staging release gate: A owner/B event organiser, B owner/A restricted member, platform staff, user with no memberships, legacy global inactive account, shared DB and dedicated DB tenant. Verify invitation delivery/login, seat races, immediate revocation, 2FA, API scopes, poll channels, team screens and financial/portal access.
- [ ] After deployment authorization, run controlled production smoke tests using disposable approved test identities in two test organisations. Confirm invitation email arrival and no password email, intended roles/event restrictions, removal preserving the other organisation, and fresh plus already-open poll access after revocation. Remove test memberships/invites/credentials while retaining required audit evidence. Do not use Akuorkor's live account as a destructive test fixture.
- [ ] Monitor authorization denials, invitation delivery states, provisioning failures and context mismatches without secrets. Restart/drain persistent workers for the new lifecycle. Inspect and cancel any lingering legacy credential-email jobs without printing their payloads; new writers do not create them, and retained legacy templates never display passwords. Set a rollback floor: after new lifecycle states are used, roll back UI only or roll forward fixes; never restore membership-by-existence or global-delete code. Keep legacy field/schema until consumer inventory is closed and rollback compatibility is proven.

## Review Focus and acceptance gates

The five highest-risk cases identified during self-review are explicitly assigned above:

1. Alternate central routes bypass the new service — task 1 route/action negative tests, task 7 complete consumer inventory.
2. Concurrent revoke/accept or two last-owner changes — task 3 real PostgreSQL concurrency, task 5 invitation replay tests.
3. An existing connection/worker/loaded user retains old access — task 4 persistent-worker and relation-cache tests; task 7 channel-publication revocation tests.
4. Migration accidentally grants access or rollback restores revoked access — task 2 discrepancy/backfill tests; task 8 rollback floor.
5. Staff access becomes proof of ownership of attendee/payment history — task 4 persisted-session replay regression; task 7 independent capability and finance tests.

## Self-review of this plan

I stepped back and asked: “Is there anything I have overlooked? Is the solution optimal?”

- **Coverage:** The initial focus on signup/team UI would have missed central administrative routes, credential APIs, worker lifecycle, broadcast subscriptions, stale primary-field finance checks, account deactivation and recovery commands. They are now explicit tasks and release gates.
- **Design economy:** Reuse the existing membership pivot, tenant-scoped roles, event assignment service patterns, quota features and auth stack. Do not introduce an external identity provider, duplicate owner system or tenant-specific passwords.
- **Corrected failure modes:** Added scanner-safe acceptance, preclaimed unverified accounts, inviter authority at acceptance, last-owner concurrency across global account actions, suspended-seat policy, duplicate-invite behaviour, dedicated-DB provisioning recovery and already-open channel revocation. These are absent from simple “attach another tenant” designs.
- **Interfaces:** Membership authority is defined once in task 3 and reused by HTTP, API, jobs and domain services. Invitations do not mutate global credentials; workspace creation does not call registration to create a second identity. Pending invitations are not active memberships.
- **Tests:** At the original planning audit, existing source/tests were inspected without execution. Implementation verification is recorded separately below; the audit itself did not reproduce production exploitation. Concurrency, persistent-worker, subscription and persisted-cookie checks are required because ordinary request tests alone would miss the identified boundaries.
- **Remaining uncertainty:** Exact production discrepancies, actual existing key usage, live websocket generation handling and production queue topology need controlled validation during implementation/staging. The audit does not establish that any vulnerability was exploited. These uncertainties are release blockers for the affected slice, not permission to silently keep unsafe fallbacks. Seven-day invitation expiry, reserved pending seats, suspended-seat counting, global 2FA policy and platform-only impersonation are implementation defaults authorized on 20 November, not descriptions of the audited baseline.
- **Recommended sequence:** Contain alternate-route exposure first, establish one lifecycle/context authority second, then enable invitations and multi-workspace creation. Do not ship just the switcher. Local implementation was authorized on 20 November; the execution summary below records implemented safeguards and outstanding release gates.

## Supporting references

Repository paths above are relative to this checkout. External guidance used to cross-check the proposed boundaries:

- [Spatie teams documentation](https://github.com/spatie/laravel-permission/blob/main/docs/basic-usage/teams-permissions.md): switching team context requires clearing loaded role/permission relations. Verify the installed package version before implementation.
- [OWASP Multi-Tenant Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html): bind tenant context to authenticated authorization and keep tenant boundaries in background operations, resources and caches.

## Local implementation record — 20 November 2026 (historical snapshot)

Implemented on `codex/safe-organisation-memberships`; no production configuration, data, deployment, push or merge was performed. Tasks 1–7 have local code and automated coverage. Task 8 remains a release checklist, including data reconciliation, browser walkthroughs, staging validation and authorized production smoke tests.

- **Identity and membership:** additive membership lifecycle and canonical email migrations; active-only authorization relations and explicit historical relations; one tenant-locked mutation service; preserved global identity and unrelated grants; owner, quota and invitation reservation protections. `organisations:audit-memberships` is read-only, with optional tenant bounding and safe JSON identifiers.
- **Administration:** platform-only central identity operations; tenant team management changes only local access; verified password-free invitations replace duplicate account creation/password mail; global suspension revokes credentials while preserving grants and protecting active organisation owners. Global deletion and admission serialize against the same fresh user row.
- **Login and context:** authoritative host/workspace context, active membership checks, explicit permission-team relation resets, account-wide security epoch and token revocation, safe impersonation, API key scope and membership enforcement, required-MFA checks across every active membership, and worker attempt/nested-context isolation.
- **Invitation lifecycle:** scanner-safe GET, authenticated POST acceptance, exact invited identity/security binding, fragment-held proof, encrypted delivery material, reservation expiry, resend/cancel controls, pending/sending/sent/uncertain evidence and scheduled bounded maintenance. Existing active accounts retain their credentials. Uncertain delivery is not blindly retried.
- **Workspace and account UI:** existing-account workspace creation without replacing the identity, Free-plan provisioning and idempotent resume, console workspace navigation, zero-membership account/security access, membership-only team form, invite delivery controls, and verified step-up account email changes without transferring independent attendee/payment history.
- **Domain and delayed work:** Community/Dues/Finance/Giving/store/event access readers use canonical membership. Organiser notification jobs retain the staff identity and exact email, then recheck membership/event authority before transport or metering. Accepted payment fulfilment and attendee receipts keep their separate contracts. Staff-private websocket messages are refresh notices only; protected HTTP fetches reauthorize and discard stale private UI after denial.
- **Offline boundary:** console membership data is not added to an offline cache. The existing usher scanner uses an independent staff-link capability and a per-link IndexedDB store; link revocation returns 410 and drops its guest pack, while synchronization revalidates the link. Attendee ticket storage remains separate. Organisation removal is not revocation of an independently issued usher link, and already-downloaded/exported information cannot be remotely erased. No identity/tenant cache generation was invented for this unrelated capability.
- **Operations:** rollout flag defaults false, test environment enables it explicitly, obsolete credential-provisioning CLI is retired, existing legacy email templates cannot display retained reusable passwords, new secrets are excluded from telemetry, and rollback guards prevent reviving revoked state. No dependency changes or new permission names were needed.

### Verification evidence

Checks use PHP 8.5, Node 24 and isolated local PostgreSQL test databases. Counts below overlap and must not be summed.

- Entire JavaScript suite: **110 files / 685 tests passed**, default timeouts, serial execution. An earlier concurrent run timed out under contention; its affected files also passed independently without raising timeouts.
- Final central administration, tenant invitations and canonical membership rerun: **63 tests / 311 assertions passed**. This includes corrected fragment-link expectations and the malformed-role regression (confirmed failing before the fix).
- Integration run across membership, central administration, account security, password-reset races, workspace creation and worker isolation: **137/138 passed initially**; its only failure was a newly added logout test using the wrong API route. Corrected named-route and isolated browser/token cases passed in the final 63-test rerun.
- Notification scope: **64 tests passed**; final organiser safety rerun **23 tests / 124 assertions passed**, covering revocation, secondary membership, changed email, absent evidence and transport/metering refusal.
- Invitation scope: **58 tests / 273 assertions passed** before final type-only corrections, with **4 focused tests / 43 assertions** afterward. Account-email proof flow: **21 tests / 113 assertions** plus frontend coverage. Worker scope: **23 tests / 329 assertions**; request/portal boundary scope: **24 tests / 91 assertions**, including persisted-cookie/session protection.
- Real PostgreSQL concurrency rerun: **11 tests / 203 assertions passed**, covering competing owners/seats, invitations/revocation and global deletion/admission in both orders.
- PHPStan full configured project analysis: **zero errors**. Removed 28 resolved historical baseline exclusions; added no suppressions. ESLint and production Vite build passed. Pint applied the final formatting corrections; source whitespace checks passed. The minified production JavaScript retains vendor template-string whitespace flagged by Git; it is generated output and was not manually rewritten.
- No full PHP suite, live mail test, browser walkthrough, staging release, production data audit or production smoke test is claimed. Current worktree is not bound to the existing local browser site, so that site would not validate this branch.

## Self-Review Report

I stepped back and asked: “Is there anything I have overlooked? Is the solution optimal?” The review expanded the implementation to cover delayed notifications, global suspension/deletion races, fresh-account MFA, account email recovery and zero-membership navigation. Independent source review closed the admission/deletion, external Inertia redirect and reset/email-change race findings; automated race coverage supplements source review.

### Touchpoints updated

- [x] **AGENTS.md:** authoritative membership, lock order, invitations, epochs, worker scope, private broadcasts, rollout and delayed staff-delivery contracts recorded.
- [x] **Memory/progress:** local execution ledger updated. No new user-memory store or personal facts required.
- [x] **Seeders/roles/permissions:** existing seeded permissions and role ceilings reused; no new permission requiring propagation. Relevant fixture/legacy tests now exercise consent rather than credential mail.
- [x] **Navigation:** console workspace link, workspace creation/resume, zero-membership My account, team invitation/resend/cancel and account email controls are discoverable.
- [x] **Configuration:** `.env.example`, `config/organisation_access.php`, explicit test enablement, migration/maintenance and middleware registration updated.
- [x] **Tests:** HTTP/action negatives, service boundaries, API/token/MFA, persistent sessions, workers, queues, browser components and real PostgreSQL races cover the changed contracts. Full PHP suite remains unrun; this is targeted verification, not a claim that every unrelated test passes.

### UI Gap Analysis

- **Discoverability: present.** Workspace access is linked from the console; team and account controls reuse existing pages. Rollout-disabled creation/invitation controls deliberately remain unavailable.
- **Empty state: present.** Zero memberships, unavailable creation, pending setup and empty team/invite states have explicit guidance/resume paths.
- **Permission gating: present.** Server authorization, event scope, role ceiling and rollout checks accompany UI controls; hiding buttons is not the access boundary.
- **Loading/error states: present in source/component tests.** Forms prevent duplicate submissions and show validation/setup failures; revoked private refresh clears sensitive UI. Invitation delivery uncertainty is visible rather than presented as success.
- **Mobile/responsive: source and component coverage only.** Flexible layouts and narrow-width form structure are present; browser-width/real-device verification is still missing and is a release gate.
- **Accessibility: semantic headings, labels, buttons, status/error text and keyboard-operable controls are present in source.** No manual keyboard/screen-reader/contrast walkthrough has been performed; complete it during staging review.

### Solved (auto-fixed)

- `app/Http/Controllers/Admin/UsersController.php:300`: serializes global suspension with owner checks, revokes global credentials, preserves memberships, and prevents unsafe deletion/admission races.
- `app/Services/Tenancy/OrganisationMemberships.php:35`: restores permission and tenant context after nested work; removal changes only the target organisation and cannot remove its final active owner.
- `app/Http/Controllers/Workspaces/WorkspaceController.php:58`: uses the Inertia external-location response for cross-origin onboarding instead of a broken XHR redirect.
- `app/Services/Notifications/NotificationGatewayService.php:563`: rechecks frozen staff identity/email and event access before delayed delivery, including missing-evidence legacy organiser jobs.
- `resources/js/Pages/Tenant/Events/panels/SessionOccupancyPanel.jsx`: denied refresh clears previously displayed private room data; component regression verifies the behaviour.
- Canonical email validation, invitation fragment expectations, typed Sanctum null/transient/personal token handling, secret-safe activity logging and malformed central role selection were corrected during integration.

### Recommended for user decision

- **Controlled staging and rollout:** recommend a separate staged release, starting with read-only discrepancy review and all-reader/worker upgrade, then disposable A/B identities and mobile/email walkthroughs before enabling lifecycle writers. Engineering smoke checks are approximately half a day after staging is ready; reconciliation effort depends on actual discrepancies and must not be guessed in advance. No production action is required to review this local implementation.
- **Dedicated-database self-service creation:** new additional workspaces intentionally use the existing Free/shared onboarding path; paid upgrades remain available after setup. Recommend keeping dedicated provisioning operator-controlled until its infrastructure failure/retry smoke test is verified in staging. No separate database orchestration system was introduced.

### Not gaps

- A single global email/password is intentional; organisation membership and event scope remain independent grants.
- Existing `users.tenant_id` is retained for compatibility/reporting, not workspace authority or navigation mutation. No automatic merge or primary-only access grant was performed.
- No external identity provider, duplicate owner system, new permission vocabulary or new package is needed for these boundaries.
- Refresh-only staff channels avoid sending private data to an old socket. Public aggregate polls intentionally remain public; historical downloads cannot be revoked retroactively.
- Rollout-disabled defaults do not replace reconciliation: migrations, changed readers and global credential safety still require a coordinated release, even before invitation UI is enabled.


## Independent completion review — 21 November 2026

The worktree was compared with current `origin/main` (`4448c3c8`), fast-forwarded to that exact base, and the uncommitted membership feature reapplied. Existing guest-poll work is retained rather than recommitted. Recovery diff/stash copies were kept outside the release. Automatically regenerated assistant guidance was backed up and excluded. No dependency changes were introduced.

This record supersedes the historical snapshot's local-verification limitations. Production remains unchanged. Opening a review PR is not approval to merge, deploy, migrate, reconcile identities or enable lifecycle writers.

### Corrections reproduced and resolved

- **MFA disable/admission race:** both the account controller and legacy Livewire screen now use `TwoFactorSettings`, which locks the current identity before checking status, security epoch, current password and active memberships requiring MFA. A real PostgreSQL worker regression failed against the old implementation: it disabled MFA after a competing required membership committed. It now refuses the change.
- **Stale invitation MFA enrolment:** confirmation compares current status and credential epoch under the user lock, including invitations issued before the account existed. A reset occurring after request admission cannot install the old session's factor.
- **Stale recovery-code rotation:** the same fresh identity/password/epoch boundary protects replacement of recovery codes. The reproduction failed before the correction and verifies the original codes remain unchanged on refusal.
- **Global account blank page:** real browser testing found that tenant navigation required a missing subdomain on `/workspaces/account`. Global account settings now have tenant-independent workspace/theme/sign-out navigation; tenant account navigation retains its existing layout. Component regressions exercise both branches without mocking away the layout.
- **Pre-migration audit:** the read-only audit originally required lifecycle columns, making an old-schema preflight impossible. It now detects that schema and audits existing pivot/owner grants without new-column hydration. A migration-down regression reproduced the original database error and now passes.

- **Public financial/proof continuation:** review found that stale staff credentials could redirect a payment return or reject valid independent attendee proof. Staff authentication is now removed first, then only independently authorised public namespaces continue through their own provider/capability/proof checks. Resolved staff-auth/verification/authorization middleware prevents this continuation even under a public-looking route name. Actual store and Giving callback regressions failed before the correction; the new group passes six tests and confirms money, guest identity and proof boundaries.
- **Race harness compatibility and legacy fixtures:** new race guards now recognise both dedicated serial and native CI-worker test database names, while retaining environment/driver/actual-database checks. A pure naming dataset rejects production/malformed names. Older attendee-profile races no longer require another person's specific local database, and reject unsafe databases before truncation/bootstrap. Venue race actors are explicitly attached as members instead of relying on primary affiliation. No concurrency or money-outcome assertion was weakened. The old commission-console expectation now verifies denial without destroying the unaffiliated identity's session; paid-design removal verifies revoked organisation access while retaining the exact identity, purchase and licence records.

Independent source review accepted these corrections and found no additional definite blocker within the reviewed identity, lifecycle, migration and corrected UI scope. This is not a production security certification.

### Verification evidence for this review

- Full integration run: **5,317 tests executed; 5,139 passed, 40 failed, 138 errored, one risky**. This exploratory run overlapped corrections and is not claimed as a final-head pass. Every observed failure/error was classified and addressed; the affected suites or exact failing cases subsequently passed, as listed below. A fresh full CI run on the pushed review head remains the gate. The earlier serial run exhausted its 512 MiB budget in unchanged Dompdf rendering; the integration rerun used four isolated databases and a temporary local 1 GiB per-process configuration. Project/production limits were not changed. No shared or production database was used.
- Security/MFA/invitation/real-worker regression run before the public-continuation correction: **137 passed, 687 assertions**.
- Membership and migration/preflight suite: **28 passed, 81 assertions**. It verifies preservation of membership rows, passwords, primary affiliations and scoped roles; canonical-email collisions stop without merging or changing either identity; rollback guards retain revoked/credential state.
- Authentication, MFA and workspace rollout regressions: **40 passed, 217 assertions**, including existing tenant login, dashboard and team access with lifecycle writers disabled.
- Final entire JavaScript suite: **112 files, 693 tests passed** after the browser-discovered account correction; affected account/workspace/email files also passed **12 tests**. Default timeouts were retained.
- Final integrated account/MFA/store/Giving run: **114 passed, 418 assertions**; paid-template checkout/retention suite: **29 passed, 101 assertions**.
- Native CI-shaped database guard/membership/invitation race suite: **25 passed, 223 assertions**. Corrected venue calendar races: **23 passed, 227 assertions**. The legacy reconciliation race with its explicit finance membership passes **one test, 14 assertions**; the preceding broader profile/venue/commission run passed 19 of 20 before that final fixture correction.
- Corrected venue finance/quote/notice/settlement/permissions/journey files: **155 of 156 passed** in their combined rerun; its remaining outdated redirect expectation was corrected and the exact journey case then passed with **6 assertions**. No payment/refund/calendar outcome assertion was weakened.
- Identity-phone and invitation role-assignment files: **54 passed, 105 assertions**; own-gateway commission split/webhook file: **30 passed, 114 assertions**. Phone acceptance/refusal remains enforced on identity forms; membership forms exclude phone identity. Logger spies now honour the native shared-context array contract.
- Deterministic marketplace MCP and meaningful advertised-cap assertions: **41 passed, 202 assertions**. The MCP examples use a frozen clock rather than a date that became past; uncapped plans are asserted rather than producing a zero-assertion risky test.
- The CI without-Telescope gate passes **81 tests**, with **3 skipped**, **381 assertions**.
- Full configured PHPStan: **zero errors**. ESLint passed, production assets rebuilt, and Pint applied. No new suppressions or dependencies.
- Local browser: shared identity signs into Alpha and opens Beta; chooser links use each organisation's host. Invitation creation shows queued delivery, log-only mail transport processes it, wrong-account viewing does not silently sign out, explicit switching returns after login, and acceptance grants only the intended workspace. Revoked Beta access denies immediately while Alpha remains accessible. A user with no active memberships retains the identity, sees recovery guidance and can open account/security settings. Free additional-workspace creation reaches the native onboarding wizard; reserved addresses show validation feedback. Workspace chooser and repaired account page were checked at desktop and 390px, in light/dark appearance, with named keyboard-focusable controls and no horizontal overflow. No browser credential changes or live mail delivery are claimed.
- Browser uses a separate synthetic local database, file sessions with the existing shared session-lock table, log mail, and no gateway/SMS credentials. No live tenant was a fixture, and no real mail, payment, refund or payout was made.

### Self-Review Report

I stepped back and asked: “Is there anything I have overlooked? Is the solution optimal?” The browser-discovered global account failure and old-schema audit failure show why source/component coverage alone was insufficient; both are now regression-tested. Existing native identity, pivot membership, role, onboarding and queue infrastructure is reused.

**Touchpoints updated:** membership/security invariants in AGENTS.md; existing role/permission definitions reused without new grants; workspace/team/account navigation; configuration and `.env.example` with rollout default false; middleware, background consumers and private refresh channels; proportional HTTP/component/concurrency coverage; this existing implementation record. No personal-memory addition is required.

**UI gap analysis:** discoverability, zero-membership/recovery states, permission gates, submission/loading/validation feedback and explicit account switching are present. Desktop/390px and keyboard checks were performed locally. A real external mailbox, dedicated-database staging tenant and production worker topology remain release gates rather than claimed local results.

**Solved:** the corrections above. All identified implementation issues have corrected regressions; final-head full CI and the operational gates remain required. No final-head whole-suite pass is claimed by the exploratory run.

**Recommended before merge/release:** keep this as a review candidate until an approved read-only report from the actual production schema is reconciled. The feature flag does not make schema changes or changed authorization readers inert. Establish a restorable backup and rehearse migrations on a staging copy, measure index/normalisation lock duration, and agree any bounded maintenance window before deployment. Do not promise zero interruption without that rehearsal. Resolve primary-only identities, absent owners, orphan grants and canonical-email collisions explicitly; never auto-merge accounts. Upgrade every app/queue reader before enabling lifecycle writers, clear/drain incompatible legacy jobs, then complete the task-8 shared/dedicated tenant and disposable A/B smoke gates. No production action is included in the current approval.

**Not gaps:** no new permission or dependency is necessary; existing free/shared onboarding is intentional; global identity credentials remain separate from per-organisation authority; attendee receipts, payment ownership and independent portal proofs are preserved; historical downloads cannot be remotely erased. Normalisation changes legacy email casing/outer whitespace and adds uniqueness, but must not delete identities or rewrite financial history. After lifecycle/epoch use, roll forward rather than restoring readers that ignore revoked state.


### Production read-only preflight — 21 November 2026

The owner authorised a read-only production audit and remaining release gates, explicitly withholding merge/deployment until safety is established. No production rows, flags, grants, migrations or deployments were changed. Queries ran in explicit PostgreSQL read-only transactions, rolled back at the end; initial scans used a 20-second statement timeout. No credential values, contact details or job payloads were emitted.

- Production: 11 organisations, 17 global staff identities, 17 memberships; all organisations use shared isolation. Lifecycle columns are not deployed. All 17 global identities are active.
- No canonical-email collisions or noncanonical addresses; no primary-only affiliations, orphan membership rows, or tenant-scoped user role/direct-permission grants without corresponding membership.
- Owner gate FAIL: Kempinski Hotel Gold Coast City, Labadi Beach Hotel, Lancaster Kumasi and Ridge Royal Hotel each have one Org Admin membership and no active Org Superadmin. Kunneo has no membership and no owner. Owner classification/reconciliation requires explicit owner decisions; do not promote or attach automatically.
- Purpledot has five memberships and three active Org Superadmins. Six organisations in total have at least one active Org Superadmin.
- Database queue snapshot: zero queued jobs, zero failed jobs and zero queued legacy credential-email jobs. This is a point-in-time check, not proof that Cloud-managed queue deliveries are drained.
- Topology: app instance with scheduler; separate Cloud-managed queue instance, available; no custom background processes listed; Octane disabled. Coordinated app/managed-queue rollout remains required.
- Backup gate NOT PASSED: Cloud reports Neon cluster `wild-mud-40672608`, `retention_days: 0`, and an empty snapshot list. This does not establish whether an external backup exists. A non-destructive snapshot-create attempt was rejected (`name is required`) although this CLI exposes no name argument; no snapshot or restore was created. Resolve backup creation through a supported provider interface and prove restoration into an isolated environment before a production-copy rehearsal. Do not change retention/costs or restore over production without explicit agreement.
- Staging/restore rehearsal, measured migration locks, dedicated-database tenant acceptance, real-mail invitation acceptance, and production disposable A/B checks remain outstanding. No staging deployment was attempted under the instruction not to deploy.

Evidence: Cloud read-only executions `cexe-a347b4f2-4782-432a-8a8f-e884d113d1d7`, `cexe-a347b520-69b8-40fa-9adc-103ee34ec30b`, and `cexe-a347b55e-3910-466c-8c11-527edac7337b`. These contain identifier/count/schema output only. The independent queries mirror the undeployed audit's legacy-schema checks; the new Artisan command was not installed or run on production.

Decision: keep PR #112 unmerged and undeployed. Clean integrity scans do not override the unresolved owner and restore gates.


### Owner classification and backup follow-up — 21 November 2026

The owner confirmed the four hotels are catalogue-only listings. Their missing Org Superadmin is an explicitly accepted catalogue-only exception, not permission to grant access or delete their existing Org Admin memberships. Kunneo is to be owned by the owner's existing account. Read-only lookup identified the current signed-in active staff identity as user ID 5; no live ownership change has yet been applied. Rehearse the exact tenant-scoped assignment after a recoverable backup exists, preserving primary affiliation, global identity, credentials and all Purpledot grants.

Production is PostgreSQL 17.12 and approximately 75 MB. The locally available pg_dump is 15.19, which cannot back up a PostgreSQL 17 server. An attempted Homebrew libpq installation would require a broad source build on this Mac; it was stopped before installing those dependencies. Do not treat a PostgreSQL 15 dump as a valid backup.

Inspected installed Cloud CLI snapshot implementation: the provider request requires `name`, but the CLI command exposes no non-interactive name argument. A named API request was denied by Cloudflare (HTTP 403); it was not retried or disguised, and no snapshot was created. The supported dashboard path is now open at Laravel Cloud sign-in awaiting the owner's session. A subsequent CLI remote-tool-discovery request timed out; no remote dump was made. Backup and isolated restore remain unverified. No retention settings, paid resources, production data, ownership or deployments were changed.

Staging work is limited to local isolated fixtures until restore access is available. Real-mail delivery and a production-copy migration rehearsal remain open; the existing synthetic browser checks must not be relabelled as those gates.


### Isolated recovery created — 21 November 2026

After the owner signed in and explicitly authorised backup, isolated restore and migration rehearsal, the Cloud dashboard confirmed backups disabled. Enabled seven-day point-in-time recovery on `miconvener`; the displayed incremental rate is $0.50 per GB-month of backup storage. No compute settings or application connections were changed. This supersedes the earlier disabled-backup observation; successful current-point restoration is the evidence, not a claim that a full historical seven-day window was independently tested.

The dashboard's Restore backup workflow explicitly creates a new cluster. Created `miconvener_pr112_restore_20261121` at the default current restore point on 21 November. It contains restored `production` (schema ID `3781360`) and `demo` (`3781361`), both displayed as not used in any environment. The restored endpoint differs from the original production hostname, verified before any migration. Direct read-only connection confirms 17 staff identities in restored `production`.

Important provider identifier caveat: the CLI reports both the source and restored clusters with Neon project identifier `wild-mud-40672608`; this is not a unique isolation identifier. Never target cleanup or writes by that identifier alone. Match restored resource name, restored hostname and schema IDs. Production's attached schema remains `2941158`. The local application was not reconfigured; rehearsal credentials are held in a mode-0600 file in a mode-0700 backup directory outside the repository.

No app/worker is attached to either restored database. Rehearsal runs only from local PHP against the verified restored endpoint, with mail logged and queue/broadcast disabled. No Cloud application deployment, merge, production migration, ownership grant or real notification is authorised by this operation.


### Restored-production migration rehearsal PASS — 21 November 2026

Executed only against the distinct restored endpoint, with its `production` schema ID `3781360`, from PR head `0acff345`. Baseline: 11 organisations, 17 users, 17 membership rows. Captured row counts and deterministic row fingerprints for all 224 existing non-migration tables, projecting their original columns. Applied exactly the five PR #112 migrations. Every original table's count and fingerprint remained identical afterwards. The 17 existing membership rows received active lifecycle status. This verifies preservation of original credentials, roles, event and financial records at the restored point; it does not prove anything about file/object-storage recovery.

Migration end-to-end timings (include network and migration bookkeeping, not measured lock-held durations): lifecycle 4055 ms; invitations 6552 ms; security version 1638 ms; canonical email uniqueness 2357 ms; account email changes 3045 ms. Total 17647 ms. Session lock timeout was 5 seconds and statement timeout 60 seconds. No competing application load was applied; do not promise the same production latency or zero downtime from this isolated run. A bounded production deployment window and coordination of application/managed-queue versions remain required.

Kunneo ownership was rehearsed in a transaction on the restored copy: existing active user ID 5, active Kunneo membership, and native tenant-scoped Org Superadmin role assignment. Confirmed native ownership and membership resolution, with original primary affiliation, password hash and security version unchanged. Rolled the rehearsal back and rechecked users, tenants, memberships and role/direct-permission fingerprints: all preserved. This is not a live ownership grant.

Cloud environment read-back confirms production remains running on deployment `depl-a345eaa9-bc6f-486a-badf-91609a983c81` and original schema `2941158`. No merge, application deployment, production migration, payment, notification or production grant occurred. The authorised backup retention setting is the only production configuration change.

Evidence is protected outside the repo at `/Users/selase/.codex/backups/multi-organisation-review/pr112-restore-20261121/`: `baseline-hashes.json` and `migration-result.json`; credential material is in a separate mode-0600 file and must never be committed or printed. The isolated restore is retained, unattached to apps/workers, for further acceptance. Do not delete it using the shared Neon project ID. Dashboard proof: `pr112-isolated-restore.png` in the existing visualization directory.

Remaining gates: shared/dedicated application acceptance and real-mail invitation delivery; managed-queue coordination/rollback-floor review; explicit catalogue-only audit exceptions and the eventual approved live Kunneo assignment; final owner release decision and post-deployment disposable A/B smoke checks. The backup and data-preservation rehearsal have passed, but these remaining gates are not silently marked complete.


### Application acceptance and managed-queue review — 21 November 2026

Fresh focused application suites passed: 110 tests, 744 assertions (organisation invitations, workspace creation/access, persistent worker boundaries, broadcasts, API membership and domain/finance permission boundaries). Fresh real-PostgreSQL membership/invitation worker races passed: 12 tests, 210 assertions. Both used the owned local `miconvener_multi_org_fixes_testing` database, never the restored or live production database.

Browser acceptance used synthetic PR112 Shared Acceptance and PR112 Dedicated Acceptance organisations in `miconvener_multi_org_browser`, and an actual separate local PostgreSQL database `miconvener_multi_org_acceptance_dedicated` migrated through the native tenant migration path. The same verified synthetic identity signed in, opened the shared dashboard, used the workspace chooser and opened the dedicated dashboard. A second synthetic dedicated owner then revoked that identity through OrganisationMemberships. Reloading the already-open dedicated dashboard denied access immediately, while the shared dashboard still loaded with the same authenticated identity. No external messages were dispatched. Earlier desktop/390px, MFA, zero-membership and role/event-scope tests remain the separately recorded evidence; this narrower new pass does not claim to repeat every prior visual check.

Cloud managed queue inspection corrects the earlier incomplete database-only queue snapshot: `default` is a Standard managed queue, Flex 256 MiB, 0–3 workers, worker timeout 60 seconds, default tries 1. At inspection it reported 1 processing, 0 waiting, 0 delayed, and 17 historical failed jobs. Several failures from 14–18 November show SES SMTP 554 `Sending paused for this account`; older failures show SMTP timeouts. Two historical failures show EventRegistrationNeedsApproval reading ticket_type_name. Current source uses the ticketType relationship; do not blindly retry these historical emails or assume all remain broken. No payloads were opened and no jobs were retried, purged, deleted or paused. Production's current default mailer remains SMTP at Amazon SES us-east-1; its queue driver is `cloud` and the organisation rollout flag is off. SES's current sending state and actual invitation inbox arrival remain unverified.

Real-email gate OPEN: requested permission to send one labelled isolated-organisation invitation to the owner's signed-in email; no recipient response or delivery is yet recorded. Local SMTP configuration exists, but neither historical errors nor SMTP configuration prove present delivery. Do not substitute log-mail or simulated tests for an inbox receipt. Resolve/check the SES account or explicitly select an already-approved working transactional transport, then send one native invitation and verify acceptance, mailbox receipt and durable delivery state. Do not replay a sent/uncertain invitation automatically.

Rollout review: production is one App cluster with scheduler plus a separately scaled managed queue; no Octane/custom background workers were found. Cloud's visible managed-queue menu has Pause (and separate destructive Purge/Delete actions). No controls were changed. Laravel Cloud's deployment documentation states existing processes finish during graceful termination, so deployment completion alone is not proof old readers have disappeared. At a separately authorised cutover: keep invitations/lifecycle writers disabled; use the queue Pause control to stop new claims, wait for in-flight work to finish without purge, confirm no incompatible credential-mail work is waiting, deploy the reviewed version to app and managed queue together, verify both revisions and drain/replace old readers, resume processing, verify health, and only then enable lifecycle writers. Recheck queue counts at cutover; this snapshot cannot certify a future drain. Because schema and authorization readers are not inert behind the flag, use the agreed bounded maintenance window as needed. After revocation/credential epochs are used, roll forward; never restore readers that ignore them. No merge/deploy is authorised until the owner has reviewed the remaining gate evidence.

Provider references: https://laravel.com/cloud/docs/deployments and https://laravel.com/cloud/docs/queues . Actual pause/drain/deploy coordination remains a release-time execution gate; a zero-deployment review cannot claim it was executed. The restored production endpoint and live tenant records were not touched by these application checks.


### Single real invitation transport check — 21 November 2026

The owner explicitly approved one test invitation to the signed-in Gmail address. Created a native pending invitation in the isolated local PR112 TEST ONLY organisation and sent it once through the existing Amazon SES SMTP transport. The subject was labelled `[TEST PR112] Organisation invitation — isolated QA only`; its review link points to the isolated local application, never a live tenant. The send used the native OrganisationInvitationMail claim/delivery-state path after commit. No production row, invitation, membership, role or Cloud queue was created or changed. Credentials remained in memory; no token, password or SMTP response body was logged.

Result: SES transport accepted the message; native invitation delivery status `sent`, delivered_at `2026-11-21T20:29:59+00:00`. No transport exception or sending-paused refusal occurred. This resolves the current transport suspicion for this single attempt, but not inbox delivery or the disposition of historical failed jobs. An exclusive local one-attempt marker prevents blindly repeating the test. No historical job was retried.

Mailbox arrival confirmation was requested from the owner and remains pending. SMTP acceptance and the application's sent state must not be labelled as independently confirmed inbox delivery. PR #112 remains unmerged/undeployed.


### Pre-release acceptance conclusion — 21 November 2026

The owner confirmed the single labelled test invitation arrived in the approved Gmail inbox. Rechecked the native isolated invitation: delivery is sent with delivered_at, status remains pending explicit acceptance, and encrypted delivery payload is cleared. No token or password was disclosed and no membership was auto-granted. The real-email delivery gate is PASS, superseding earlier pending-delivery notes. Historical SES failures remain retained for separately authorised reconciliation; no blind retries were made.

The final targeted security/provisioning run passed 105 tests and 390 assertions: StaffAccountSecurityTest, AccountEmailChangeTest, TwoFactorTest, PortalSessionRevocationTest, TenantProvisioningFailureTest, ColdProvisioningTest and Admin/TenantProvisioningTest. Together with the fresh application and race runs above, pre-release acceptance totals 227 tests / 1344 assertions. Cold provisioning includes a real dedicated database and tenant schema; failure tests cover unreachable tenant databases and non-zero migration results. No production or restored-production database was used by these tests.

GitHub read-back: PR #112 remains draft, open, unmerged and mergeable at reviewed head `0acff345151329d373519825fe1b953e92ee412f`, based on `4448c3c8c5198e72bbcf67535ab65fa70c907be6`. CI run `44879102858` remains completed/success on that exact head. Application source has not changed during the operational acceptance work. The only uncommitted repository change is this release-evidence document.

I stepped back to check for overlooked risks. Completed pre-release evidence now covers production discrepancies and owner decisions, actual point-in-time recovery/isolated restore, preservation of original rows through migrations, native shared/dedicated application access, immediate revocation, identity/MFA/portal boundaries, concurrent owner/seat/invitation transitions, provisioning success/failure and actual mailbox arrival. The distinction still matters: a working new local version and a verified restore do not prove deployed worker replacement. No cutover has been performed and no claim of zero interruption is made.

Recommendation: technically ready for owner review of a controlled release; no newly identified code blocker. Continue the explicit merge/deployment hold. At an authorised release window, recheck backup recovery and live discrepancy/queue counts, pause new queue claims and drain without purge, ship the reviewed app/queue versions with lifecycle writers off, verify old readers are gone, then apply the separately approved Kunneo assignment and enable the feature only after disposable A/B production checks pass. Keep the four ownerless hotels as documented catalogue-only exceptions; never auto-promote or silently alter their memberships. After lifecycle state is used, roll forward. Historical failed mail is a separate reconciliation task, not permission to bulk retry.
