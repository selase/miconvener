# Implementation Plan: Platform Attendee Portal (Stages 1–3)

## Goal
Implement the foundational layers of the **Platform Attendee Portal** as specified in `docs/superpowers/specs/2026-09-23-platform-attendee-portal-design.md`:
1. Establish the canonical platform host boundary at `https://miconvener.com/my` with automatic subdomain 302 redirects (`{tenant}.miconvener.com/my` -> `miconvener.com/my?organiser={tenant}`) and pre-routing tenant context bypass.
2. Build the landlord-scoped platform verification engine (`platform_attendee_access_codes`), independent rate limiters (email cooldown, hourly, daily, IP burst/daily, Cloudflare Turnstile step-up), and 12-hour session-based identity.
3. Deploy the central `/my` verification shell with status-specific UX, 60s resend timer, address correction, and neutral 202 queuing.

---

## Tasks

### Stage 1: Platform Host Boundary, Pre-routing Isolation, & Subdomain Redirects
- [ ] **1.1 Unit tests for Host Matcher**: Add tests in `tests/Unit/Tenancy/TenantHostMatcherTest.php` for `isPlatformHost(string $host)`, `isWwwHost(string $host)`, and `tenantSlug(string $host)`.
- [ ] **1.2 Update TenantHostMatcher**: Implement `isPlatformHost` and `isWwwHost` with host normalization and terminal dot handling.
- [ ] **1.3 Update GuardAttendeePortalHost Middleware**:
  - Intercept `/my` and `/my/*`.
  - For `isPlatformHost`: tag `$request->attributes->set('is_platform_attendee_portal', true)`.
  - For `isWwwHost`: return 302 redirect to `https://{baseDomain}/my`.
  - For valid tenant slug: return 302 redirect to `https://{baseDomain}/my?organiser={slug}`.
  - For any invalid, nested, or unrecognized host: abort 404.
- [ ] **1.4 Update ResolveTenant Middleware**:
  - If `$request->attributes->get('is_platform_attendee_portal')` is true, call `$this->dbManager->configureShared()` and proceed without tenant resolution (ignoring session `active_tenant_id` and `X-Tenant` header).
- [ ] **1.5 Route Wiring**:
  - Define root-matching `/my` group in `routes/web.php` (or `routes/attendee.php` registered in `RouteServiceProvider`).
  - Remove obsolete `/my` prefix group from `routes/subdomain.php`.
- [ ] **1.6 Feature Tests**:
  - Create `tests/Feature/Events/PlatformAttendeePortalHostTest.php` verifying base domain access, `www` redirect, tenant subdomain redirect with `?organiser=`, 404 on invalid hosts, and total isolation from session `active_tenant_id` / `X-Tenant`.

### Stage 2: Platform Access Codes & Multi-Tier Verification Infrastructure
- [ ] **2.1 Landlord Migration & Model**:
- [x] **2.1 Landlord Migration & Model**:
  - Create migration for `platform_attendee_access_codes` table (`id` uuid pk, `email_normalized`, `code_hash`, `attempts`, `expires_at`, `consumed_at`, timestamps, composite index `(email_normalized, consumed_at, created_at)`).
  - Create model `App\Models\PlatformAttendeeAccessCode` with `MassPrunable`.
- [ ] **2.2 Rate Limiting & Turnstile Service**:
- [x] **2.2 Rate Limiting & Turnstile Service**:
  - Implement `App\Services\Events\AttendeePortalRateLimiter` managing:
    - Email cooldown: 1 per 60s
    - Email hourly: 5 per hr
    - Email daily: 10 per 24h
    - IP burst: 20 per 10min (6th attempt requires Turnstile)
    - IP daily: 100 per 24h
    - Confirm attempts: 10 per email / 10min, 60 per IP / 10min
  - Add Turnstile verification via Laravel HTTP client.
- [ ] **2.3 Verification Service & Mailable**:
- [x] **2.3 Verification Service & Mailable**:
  - Create `App\Mail\Events\PlatformAttendeeAccessCodeMail` (transactional, platform-branded "Your MiConvener sign-in code", zero tenant credits metered).
  - Create `App\Jobs\Events\SendPlatformAttendeeAccessCode`.
  - Create `App\Services\Events\PlatformAttendeeVerification`:
    - Normalization: `mb_strtolower(mb_trim($email))`.
    - Cached BCrypt dummy hash matching hashing rounds to guarantee constant-time checks.
    - Atomic attempt increment and atomic consumption.
    - Confirmation stores `attendee_verified_platform` session marker (12-hour duration) and rotates session ID.
- [ ] **2.4 Middleware & Pruning Schedule**:
- [x] **2.4 Middleware & Pruning Schedule**:
  - Create `App\Http\Middleware\EnsurePlatformAttendeeVerified` reading `attendee_verified_platform`.
  - Register daily prune for `PlatformAttendeeAccessCode` at 04:00 in `app/Console/Kernel.php`.
- [x] **2.5 Feature & Unit Tests**:
  - Test pruning in `tests/Feature/Events/PlatformAttendeeAccessCodePruningTest.php`.
  - Test verification lifecycle, timing invariance, rate limiting, and Turnstile challenge in `tests/Feature/Events/PlatformAttendeeVerificationTest.php`.

### Stage 3: Central `/my` Verification Shell
- [x] **3.1 Controller & Form Requests**:
  - Create `App\Http\Requests\Attendee\PlatformSendAccessCodeRequest` and `PlatformConfirmAccessCodeRequest`.
  - Create `App\Http\Controllers\Public\PlatformAttendeeAccessController`:
    - `page(Request $request)`: renders `Public/Events/AttendeePortal/MyPortal` with masked verified email, optional preselected organiser hint, or verification prompt.
    - `send(PlatformSendAccessCodeRequest $request)`: neutral 202 response, Turnstile handling (428 when challenge required).
    - `confirm(PlatformConfirmAccessCodeRequest $request)`: atomic verification, session rotation, returns masked email.
    - `forget(Request $request)`: signs out and clears platform proof.
    - `session(Request $request)`: returns current verified session email.
- [x] **3.2 Frontend UI Enhancements**:
  - Update `VerifyPrompt.jsx` to support:
    - 60s cooldown timer.
    - Turnstile widget appearance when challenged.
    - Status-specific copy (422 validation, 428 challenge, 429 rate limit, 500 error, network error).
    - "Use a different email address" button to reset form.
    - Explanation after 2 failed attempts that 5 wrong guesses invalidate the code.
  - Update `MyPortal.jsx` for platform layout and verified empty state ("No MiConvener events found for this address yet").
- [x] **3.3 Component Verification & Feature Tests**:
  - Feature tests in `tests/Feature/Events/PlatformAttendeeAccessControllerTest.php`.
  - Production asset compilation via `npm run build`.
  - Update Vitest tests in `resources/js/test/portal/VerifyPrompt.test.jsx`.

### Stage 4: Cross-Tenant History Service
- [x] **4.1 History Service Implementation**:
  - `App\Services\Events\PlatformAttendeeHistory`:
    - `getHistory(string $email, ?string $organiserSlug = null)`: groups into `needs_attention` (unpaid registrations, pending transfers), `live_now` (ongoing/checked in), and `organisers` with `upcoming` (starts_at ASC) and `past` (starts_at DESC).
    - `getCertificates(string $email, ?string $organiserSlug = null)`: certificates earned across all organisers.
    - `getAbstracts(string $email, ?string $organiserSlug = null)`: abstracts submitted or co-authored across all organisers.
    - `getAttendance(string $email, ?string $organiserSlug = null)`: session attendance and dwell time records.
    - Landlord bypass (`withoutGlobalScopes()`) and banned tenant exclusions (`TenantStatusEnum::BANNED`).
- [x] **4.2 Endpoints in PlatformAttendeeAccessController**:
  - `GET /my/events`
  - `GET /my/certificates`
  - `GET /my/abstracts`
  - `GET /my/attendance`
- [x] **4.3 Feature Tests**:
  - `tests/Feature/Events/PlatformAttendeeHistoryTest.php`: multi-tenant aggregation, lifecycle categorization, transfer actions, auth gate rejection.

### Stage 5: Central Event Workspace & Checkout Grant
- [x] **5.1 Checkout Grant & Return Handling**:
  - `PlatformAttendeeWorkspaceAuthorizer`: 30-minute session-scoped `checkout_grant` allowing immediate single-registration workspace view while webhook completes fulfillment without creating platform-wide email proof.
  - Endpoint `GET /my/events/{registration}/status` for bounded status polling (2s initial, 5s interval, 24 attempts max / 2 minutes) with status and confirmation details.
- [x] **5.2 Central Event Workspace Endpoint**:
  - `GET /my/events/{registration}` in `PlatformAttendeeWorkspaceController`:
    - Authorize via verified platform attendee email matching registration email OR valid checkout grant.
    - Pass complete event context (sessions, speakers, ticket details, seat assignment, released materials, service requests).
    - Render `Public/Events/AttendeePortal/Portal` with `is_checkout_grant` flag and "Confirming payment" loader if pending.
- [x] **5.3 Ticket Download & Actions**:
  - `GET /my/events/{registration}/ticket`: PDF ticket download using `TicketPdfService`.
  - Atomic ticket credential rotation (`rotateTicketCredentials`) on transfer to prevent dual-possession.
  - Materials streaming download via `PlatformAttendeeWorkspaceController::downloadMaterial`.
  - Service request creation gated to running events or checked-in attendees.
- [x] **5.4 Feature Tests**:
  - Tests covering checkout grant authorization, expired grant redirect, bounded status polling, PDF download, credential rotation, and access restrictions in `tests/Feature/Events/PlatformAttendeeWorkspaceTest.php` (10 passing tests, 53 assertions).

### Stage 6: Dashboard UI (Action-First Lifecycle Hierarchy)
- [x] **6.1 Action-First Dashboard Components**:
  - `NeedsAttentionSection`: pending payments with "Complete payment" action, incoming transfer invitations with "Review & accept".
  - `LiveNowSection`: running events with pulsing indicator, checked-in badges, seat assignments, quick actions ("Open workspace", "My Day agenda", "Get help").
  - `OrganiserEventsSection`: grouped by organiser, upcoming events with ticket details and released materials count, collapsible past events (de-emphasized when urgent items exist).
- [x] **6.2 Filter by Organiser Support**:
  - Honour `?organiser={slug}` URL query parameter in the UI and data layer, focusing the view on the specified organiser while offering "See all events" clear button.
- [x] **6.3 Integration with `/my/events` Data**:
  - Preload `initialHistory` via Inertia from `PlatformAttendeeAccessController::page`.
  - Wire `MyPortal.jsx` to load and display data, with `HistorySkeleton`, error retry state, and honest empty state.
- [x] **6.4 Verification**:
  - Feature tests in `tests/Feature/Events/PlatformAttendeeDashboardTest.php` (3 tests, 54 assertions), clean production build via `npm run build` (2.38s), and 0 PHPStan errors.

### Stage 7: Responsive Navigation & Offline PWA
- [x] **7.1 Web App Manifest**:
  - App manifest scoped to `/my/` via `GET /my/manifest.json` and `PlatformAttendeeAccessController::manifest()` with standalone display and `/my/` start URL.
- [x] **7.2 Service Worker Shell Precaching**:
  - Service worker `public/attendee-sw.js` and `resources/js/lib/registerServiceWorker.js` precaching shell and branding assets, fetch handler strictly scoped to `/my*`, never caching authenticated API responses in HTTP cache.
- [x] **7.3 Offline Ticket Snapshot**:
  - Opt-in "Save ticket offline" snapshot stored in browser `localStorage` (`resources/js/lib/offlineTicketStore.js`), 7-day post-event expiry (or 30-day confirmation expiry), explicit omission of sensitive attendee fields (email, phone, certificates, notes), and automatic cleanup on sign-out via `clearAllOfflineTickets()`.
- [x] **7.4 Responsive Navigation**:
  - Safe-area-aware sticky bottom navigation on mobile (<768px) with 44px touch targets (`Overview`, `Ticket`, `My day`, `More`), desktop header navigation (>=768px), offline banner, and "Saved offline" pill with modal confirmation.
- [x] **7.5 Verification**:
  - Verified with `tests/Feature/Events/PlatformAttendeePwaTest.php` (2 passing tests, 11 assertions), 55 passing platform tests (329 assertions), 0 PHPStan errors, and clean Vite production build.

### Stage 8: Contextual Actions (Polls, Q&A, Dynamic Forms, and Durable Service Requests)
- [ ] **8.1 Durable Service Requests**:
- [x] **8.1 Durable Service Requests**:
  - `GET /my/events/{registration}/service-requests` endpoint in `PlatformAttendeeWorkspaceController` returning all service requests with status progression.
  - Enhance `POST /my/events/{registration}/service-requests`: prevent duplicate active requests of the same type (`['open', 'acknowledged', 'in_progress']`), medical priority escalation, and return full progression.
  - Update `GetHelpPanel.jsx`: load existing requests on mount, display live progression stepper (Open -> Acknowledged -> In Progress -> Resolved), urgent medical styling, duplicate prevention.
- [ ] **8.2 Contextual Live Polls & Quizzes**:
- [x] **8.2 Contextual Live Polls & Quizzes**:
  - `GET /my/events/{registration}/poll` and `POST /my/events/{registration}/poll/{poll}/respond` in `PlatformAttendeeWorkspaceController`.
  - Derive stable opaque respondent token (`hash_hmac`) from registration ID and app key, preventing duplicate responses while keeping attendee identity private from organiser poll records.
  - Create `PollPanel.jsx`: active question, options, quiz timer/countdown, real-time feedback, and score presentation.
- [ ] **8.3 Event Q&A / Forum**:
- [x] **8.3 Event Q&A / Forum**:
  - `GET /my/events/{registration}/forum`, `POST /my/events/{registration}/forum`, `POST /my/events/{registration}/forum/{thread}/vote`, and `POST /my/events/{registration}/forum/{thread}/unvote`.
  - Allow confirmed registered attendees of private events to access the forum.
  - Create `ForumPanel.jsx`: questions list with upvote counts, "Ask a question" modal with anonymous toggle, and host reply highlighting.
- [ ] **8.4 Dynamic Forms & Surveys**:
- [x] **8.4 Dynamic Forms & Surveys**:
  - `GET /my/events/{registration}/forms`, `GET /my/events/{registration}/forms/{form}`, and `POST /my/events/{registration}/forms/{form}`.
  - Automatic identity binding (`registration_id`, `email`, `name`) without requiring re-entry; enforce `requires_check_in` eligibility; sync dynamic participant stratification groups.
  - Create `FormsPanel.jsx`: schema-driven form renderer and submission management.
- [ ] **8.5 Portal Navigation Integration**:
- [x] **8.5 Portal Navigation Integration**:
  - Update `Portal.jsx` to dynamically render contextual tabs (`ticket`, `agenda`, `poll`, `forum`, `forms`, `help`, `downloads`), badge live polls, and support desktop & mobile navigation.
- [ ] **8.6 Verification**:
  - Create `tests/Feature/Events/PlatformAttendeeContextualActionsTest.php`.
  - Run Pint, PHPStan, and Vite build.
- [x] **8.6 Verification**:
  - Create `tests/Feature/Events/PlatformAttendeeContextualActionsTest.php` (10 passing tests, 54 assertions).
  - Run Pint, PHPStan, and Vite build (clean build, 0 errors).

---

## Verification Plan
1. **Automated Feature Tests**:
   - `tests/Feature/Events/PlatformAttendeeContextualActionsTest.php`
2. **Quality Gates**:
   - `vendor/bin/pint --dirty`
   - `vendor/bin/phpstan analyse --memory-limit=2G`
   - `npm run build`


