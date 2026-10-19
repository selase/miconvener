# Community Milestone 7: Library, Service Notes and Polls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task using the owner's established native approach. Steps use checkbox (`- [ ]`) syntax. One fresh whole-branch review at the end; no per-task reviewers or redundant second blanket review.

**Status:** Implemented and reviewed locally on `codex/community-7` (owner-approved native approach). Owner accepted and approved push/merge/deploy2026-10-18; full remote CI is the remaining pre-merge gate. No production change or rollout yet.

**Goal:** Let associations share standing documents, churches publish notes for individual recurring services, and organisations ask their audience questions through the existing `/my` app, without requiring dues or event registration for organisation-owned content.

**Architecture:** Organisation content has its own records and reader policy; existing event materials and live polls retain their records and verification rules. A service note is an organisation-library item explicitly linked to one existing event occurrence (`EventSession`); the link gives context, not attendee privileges. Reuse native identity, storage metering, form controls and pure question-validation logic, extracting shared logic only when an event regression test proves its behaviour unchanged.

**Tech Stack:** Laravel13/PHP8.5, PostgreSQL landlord connection, Inertia/React, existing Console components and Tailwind4, Pest/Vitest. No new dependency or frontend framework.

**Spec:** `docs/superpowers/specs/2026-10-15-community-design.md` §§2,4,6.1–6.3,13; roadmap milestone7; owner request2026-10-18 for organisation library and individual church-service notes. The recommendations below were approved by the owner2026-10-18.

## Proposed member experience

- Teachers' association: `/my` → Communities → Association → **Library** → Constitution. Show title, edition/date, short description, file type/size and Open document. Minutes and committee documents use the same library with their own audiences.
- Church: `/my` → Communities → Church → **Services** → Sunday18October2026, morning service → sermon notes, readings and attachments. Each morning/evening occurrence has its own identity even on the same date. Previous services remain discoverable by date and event-series filter.
- Staff: Community → Library → Add document or Add service notes; choose audience, publication/release time and, for notes, the specific recurring event and occurrence. A shortcut from Events → Programme opens this same editor with the occurrence preselected. No second event editor or duplicated recurring calendar.
- Community → **Polls & surveys** shows open questions and completed responses. These remain distinct from a conference's live polls and Q&A.
- Keep Home/Tickets/Communities/Money/Me as the five app destinations. Library/Services/Polls are organisation sections inside Communities, with real page state/deep links, browser back/forward and no inbox-style oversized modal.

## Owner-approved recommendations

1. **Restricted audience first:** active members and selected active groups. Approved representatives may read on behalf of an eligible member, visibly labelled; dues balance is irrelevant. Pending/lapsed/exited records and followers alone do not unlock member documents. No anonymous public files in this release. A later public/follower publication option would need an explicit audience decision, not a file URL bypass.
2. **Service notes independent of attendance:** an eligible church member can read the organisation's published notes without booking every Sunday. This never grants access to attendee-only EventMaterial files, tickets, speaker decks or live-event responses.
3. **Simple authored content:** title, plain text with paragraph breaks, optional attachments and edition/date. No new rich-text editor, arbitrary HTML, video hosting, livestreaming, comments, sermon transcription or audio hosting. Supported attachments: PDF, DOCX, JPEG, PNG and WebP;20MiB per file, existing tenant storage allowance applies.
4. **Current eligibility on every read:** revoked membership/grants/group access or withdrawn publication stops new reads immediately. A file deliberately downloaded by a reader cannot be remotely erased; the app/service worker will not cache private content.
5. **Library editions:** preserve immutable file versions with a current published edition. Staff can inspect older editions; members see the current edition only initially. No approval chain, coauthoring or redline workflow.
6. **Poll scope and privacy:** initially the same member/group audiences, no elections or legally binding votes. One response per eligible member per questionnaire; representative reading does not grant answering authority. Shared-email members remain separate explicitly selected member contexts under existing mailbox trust, not an invented global-person identity. No anonymous-response promise; staff can export attributable responses. Member results remain hidden until staff publishes aggregate results. Explicitly review this rule before building.
7. **Release in three reviewable slices:**7a Library,7b dated service notes,7c organisation polls/surveys. Each slice must be useful and tested on its own; none auto-deploys. Existing rollout approval does not authorise this implementation or enablement.

## Global Constraints

- Shared services, not shared tables. Never make `event_id` nullable to turn EventMaterial/EventPoll into organisation records.
- Email first; phone requires exact current member-scoped credentials, and representatives remain explicitly bounded. `MemberAccess::role(Session, Member): ?string` is the reader foundation. A phone match, follower address, staff-supplied reader ID or name is not ownership proof.
- Active personal membership is independent of dues, charges, Giving and event registration. Do not route access through the dues payment page.
- Feature gates: new `community_library` and `community_polls` switches require Community + `community_app` for `/my`. Library Starter+, polls/surveys Growth+, exactly as the parent spec; read current package-entitlement keys and add explicit new keys where absent. Existing records/staff management must not disappear on downgrade; stop new publishing/answering when unavailable and retain authorised reading of already-published material/history. Staff can always withdraw retained content. Test this distinction.
- New permissions: `manage community library`, `manage community polls`, `read community poll results`. Add each in PermissionsSeeder and RolePermissions together, including safe custom-role registration where appropriate; first-created-only built-in grants preserve administrator removals. Existing event-update permission does not grant library publishing.
- Private storage by name, persisted on each version: local private disk rooted under `storage/app/private/community-library`; production uses the existing private S3 configuration with a `community-library/{tenant UUID}/...` prefix. Do not reuse `Event::uploadDisk()` locally: it selects the public disk. Stream through an authorised controller, never return a raw object path/public URL or a permanently reusable signed URL. Check storage/account quota through existing TenantStorageManager/StorageMeter.
- Files and responses use private/no-store, nosniff and safe filenames; DOCX/images download as attachment, PDF may open inline after authorised streaming. Strict server MIME/size checks; no HTML/SVG/executable uploads or client-controlled disk/key.
- No private library payload, service notes, survey responses or documents in PWA caches. Clear organisation panels on proof expiry, contact change, grant revocation and sign-out.
- Use existing native components, dark/light tokens, pagination and bounded queries. No new dependencies, new base folders, destructive migrations, automatic messages, production demo content or setting changes.
- Rebuild and commit public/build with JS/CSS changes. Pint --dirty, PHPStan0/no baseline growth, affected PHP/JS suites and one final full remote CI after approved upload. Never overlap refreshing DB suites. One whole-branch review before integration; no merge/deploy without approval.

## Review Focus

1. A copied file/deep link, changed tenant or reused family phone cannot bypass current audience/proof; list/detail/download all enforce the same rule. Tasks1–3.
2. A reader who loses access between listing and downloading, including mid-session group removal, gets no content; caching and withdrawn/replaced files must not leak it. Tasks1–3.
3. Two services on one date, timezone/DST boundaries, cancelled/rescheduled/archived occurrences and unpublished notes cannot attach content to the wrong service. Task3.
4. A lost upload/database response, replacing an edition or withdrawing content cannot publish an orphan file, erase history, double-meter storage or keep a stale raw URL valid. Task2.
5. Duplicate survey submission, revoked eligibility, mixed member/representative contexts or client-edited question types cannot create extra answers, reveal identities to members or change existing live poll behaviour. Tasks4–5.

## File and interface map

Existing integration points: `app/Services/Community/MemberAccess.php`, `MemberAudienceSource.php`, `app/Models/EventSession.php`, `app/Services/Tenancy/TenantStorageManager.php`, `StorageMeter.php`, `app/Services/Events/MaterialReleasePolicy.php`, `PollAnswer.php`, `PollResults.php`; `routes/subdomain.php`, central `/my` routes in `routes/web.php`; `resources/js/Pages/Public/Community/CommunitiesPanel.jsx`, `resources/js/Pages/Public/Events/AttendeePortal/MyPortal.jsx`, `resources/js/Layouts/ConsoleLayout.jsx`; `database/seeders/PermissionsSeeder.php`, `app/Libraries/RolePermissions.php` and current entitlement definitions located at implementation start.

New classes stay in existing Models, Services/Community, Http/Controllers/{Public,Tenant}, Http/Requests/{Attendee,Tenant/Community}, and Pages/{Public,Tenant}/Community directories. Generate PHP skeletons with Artisan. Name migrations under `database/migrations/landlord`. Do not create any of them until this plan is approved.

### Task 1: Content foundations, audience policy and rollout (7a)

**Files:** Create `CommunityLibraryItem`, `CommunityLibraryVersion` models/factories; landlord migration `create_community_library_tables`; `Services/Community/CommunityContentAccess.php`, `CommunityLibraryRollout.php`; `tests/Feature/Community/LibraryAccessTest.php`, `LibraryRolloutTest.php`. Modify config/filesystems.php, seeders/RolePermissions, actual entitlement definitions and tenant/community navigation.

**Interfaces:**
- `CommunityContentAccess::readers(Session $session, Tenant $tenant, array $selector): Collection<int,Member>` returns only current authorised member contexts satisfying active/group audience. Default-deny unsupported selectors; reuse member audience query criteria, never message delivery consent or address-based deduplication.
- `CommunityContentAccess::canRead(Session $session, CommunityLibraryItem $item): bool` rechecks current audience + published/release state. Staff management uses staff permissions separately.
- `CommunityLibraryRollout::canPublish(Tenant $tenant): bool` combines rollout+plan; retained-reading path explicitly separate.
- Items: tenant,id,kind(document/service_note),title,description,plain_text,audience selector,draft/published/withdrawn,release_at,current_version_id,optional event_id+session_id,created_by. Tenant-bounded foreign keys; both context IDs null for documents, both present for notes. Versions: tenant,item,id,edition,disk,path,mime,size,checksum,created_by,created_at. Files immutable; no nullable event IDs on event tables.

- [x] Write failing access tests: no-dues active member allowed; foreign/unverified/pending/lapsed/exited/follower denied; approved representative read allowed then immediate revoke; shared phone denied without grant; groups enforce tenant and current membership; draft/future/withdrawn hidden; downgrade retains safe history but refuses publishing.
- [x] Run `php artisan test --compact tests/Feature/Community/LibraryAccessTest.php tests/Feature/Community/LibraryRolloutTest.php`; watch missing behaviour fail.
- [x] Implement schema, policies, rollouts, private disk and seeded permissions; add downgrade/retained-content affordance. Assert no unauthorised field/file key in JSON and no public-disk write.
- [x] Run tests green, permission/seeder invariants and Pint; tick steps and commit together.

### Task 2: Staff library, versions and private member reading (7a)

**Files:** Create `Services/Community/CommunityContentStorage.php`, tenant/public `CommunityLibraryController.php`, `Tenant/Community/StoreLibraryItemRequest.php`, `UpdateLibraryItemRequest.php`; `Pages/Tenant/Community/Library.jsx`, `Pages/Public/Community/LibraryPanel.jsx`; `tests/Feature/Community/LibraryPublishingTest.php`, `LibraryDownloadTest.php`, `resources/js/test/CommunityLibrary.test.jsx`. Modify Task1 models, native navigation/CommunitiesPanel and routes.

**Interfaces:** Storage `put(Tenant $tenant, UploadedFile $file): array{disk:string,path:string,mime:string,size:int,checksum:string}` uses server-owned prefix/quota; `stream(CommunityLibraryVersion $version): StreamedResponse` only called after policy. GET `/my/communities/{tenant}/library`, `/library/{item}`, `/library/{item}/file`; list paginated25, current published version only. Staff create/edit/publish/withdraw and upload a new immutable edition; transaction changes current-version pointer atomically, records operator, cleans only own failed uploads, never deletes the prior edition on failure. No automatic announcement send.

- [x] Write failing tests for MIME/20MiB/quota, cross-tenant selector/file ID, absent file, streamed private headers, version replacement/revocation between list+download, signed-out/proof expiry, safe PDF filename and attachment disposition. Test malicious HTML as text; missing permission; expired proof clears UI.
- [x] Run the three new suites; confirm failure for the intended missing surface.
- [x] Implement validated APIs, actual Library navigation, staff editor and page-level member list/detail. Show edition/date/type/size, search title, empty CTA for staff and neutral reader empty state, loading/retry403/404 handling. Link from Communities without adding a sixth app tab.
- [x] Run affected PHP/Vitest and existing MemberAccess/app/inbox suites; verify390px/desktop, light/dark and keyboard. Build assets; tick and commit.

### Task 3: Notes for a particular recurring church service (7b)

**Files:** Create tenant `CommunityServiceNoteController.php`, attendee `CommunityServicesController.php`, `StoreServiceNoteRequest.php`, `Pages/Public/Community/ServicesPanel.jsx`, `Pages/Tenant/Community/ServiceNoteEditor.jsx`; tests `CommunityServiceNotesTest.php`, `resources/js/test/CommunityServices.test.jsx`. Modify Task1 item model/context constraints; Events/Programme editor entry point; Communities library navigation and central routes.

**Interfaces:** `CommunityServiceNoteController::store(StoreServiceNoteRequest $request, string $subdomain): JsonResponse` writes a Task1 library item, never an EventMaterial. Validate tenant event and exact session together. `CommunityServicesController::index(Request $request,string $tenant): JsonResponse` paginates20 accessible dated occurrences with released note counts; `show(Request $request,string $tenant,string $session): JsonResponse` returns accessible released notes only. A slug is not identity evidence. Deep link `/my?organiser={slug}&section=communities&community_view=services&service={session UUID}` survives refresh/back and requires verification.

- [x] Write failing tests: two services on the same date remain distinct; notes cannot reference a session from another event/tenant; original scheduled instant shown in tenant timezone including DST; current no-dues member reads without registration; follower denied; future release hidden; reschedule keeps session-ID linkage; cancelled occurrence hides notes; archive preserves already-published historical notes until explicit withdrawal; no event ticket/registration is created.
- [x] Run new tests RED and existing `EventRecurrenceTest`, `EventMaterialTest`, `MaterialReleasePolicyTest` baseline serially.
- [x] Add service date/series filters and a date-first list (only eligible content, no member roster), published notes and attachments. Staff may edit text/edition/release/audience; cancel/reschedule retains linkage/history. Copying or generating a recurring occurrence does not copy released private notes into it automatically.
- [x] Keep EventMaterial downloads, release policy, speaker decks, limits, attendee verification and live poll gates unchanged. A link to attendee-only materials starts the existing attendee journey; it cannot proxy around it through Community membership.
- [x] Run all above green plus app navigation tests, local browser morning/evening service, build, tick and commit.

### Task 4: Organisation polls/surveys and pure engine reuse (7c)

**Files:** Create `CommunityQuestionnaire`, `CommunityQuestion`, `CommunityQuestionOption`, `CommunitySubmission`, `CommunityAnswer` models/factories and migration; `Services/Community/CommunityQuestionnaires.php`; extract pure `Services/Events/QuestionAnswerValidator.php` from `PollAnswer.php` only if needed while retaining `PollAnswer::read(Request,EventPoll): array` unchanged as adapter. Create `tests/Feature/Community/CommunityQuestionnairesTest.php`; modify event question tests only to add adapter parity assertions.

**Interfaces:** Questionnaire kind poll(single question)/survey(ordered questions), tenant,audience,draft/open/closed,opens_at/closes_at,results_published_at. Existing10 event question types/limits apply, including MAX_WORDS3 and type-specific settings; no event-specific deck/broadcast or quiz scoring added to Community. Pure validator `read(Request $request,string $type,array $optionIds,array $settings): array` returns the existing normalised answer shape. `CommunityQuestionnaires::submit(Tenant $tenant,Session $session,string $questionnaireId,string $memberId,array $answers): CommunitySubmission` rechecks current self-role/audience/time under a transaction; unique questionnaire+member submission, locked question set, atomic answers. No representation-based answering or named public voter list. Open questions/options freeze after first accepted response.

- [x] Write failing tests for every supported question type/invalid payload, foreign option IDs, mandatory answers, atomic multi-question rejection, duplicate browser/web retry, concurrent duplicate submit, close boundary, changed audience before submit, representative rejected and member without dues accepted. One verified mailbox controlling multiple members requires explicit eligible member selection, never silent multiplication of responses.
- [x] Watch failures, implement own records plus minimal pure extraction, then run event `PollQuestionTypesTest`, `PollScaleTest`, `PollVotingGateTest`, `PollPresentationTest` and new tests green. No existing EventPoll table/foreign-key/identity change.
- [x] Tick and commit schema/engine changes together. No live sending or production flags.

### Task 5: Poll/survey staff and member UI, results and exports (7c)

**Files:** Create `Services/Community/CommunityQuestionnaireResults.php`, tenant/public `CommunityQuestionnaireController.php`, Form Requests, `Pages/Tenant/Community/Polls.jsx`, `Pages/Public/Community/QuestionnairesPanel.jsx`, tests `CommunityQuestionnaireEndpointsTest.php`, `CommunityQuestionnaireExportTest.php`, `resources/js/test/CommunityQuestionnaires.test.jsx`. Modify native Community navigation, Task4 service and routes.

**Interfaces:** GET member list/detail; POST bounded answer payload uses Task4 submit; staff create/edit/open/close/results publication requires manage permission, attributable results/CSV requires separate results permission. Paginate response detail25; aggregate server-side; stream bounded exports and neutralise CSV formulas. `CommunityQuestionnaireResults::summary(CommunityQuestionnaire $questionnaire): array` exposes counts/distributions only to permitted audience after explicit results publication; no emails/phone/member IDs in reader results. Preserve hidden results while accepting answers.

- [x] Write failing tests for staff permission separation, unpublished result denial, raw export privacy, CSV formula neutralisation, duplicate submit notice, partially completed form on validation errors, proof expiry, withdrawn questionnaire and cross-tenant URLs.
- [x] Implement native editor/member form, explicit selected context, confirmation, open/closed/responded state, empty/error/loading views, result publication and export. No automatic notifications, inbox copies, SMS or event broadcasts.
- [x] Run affected PHP/Vitest, event poll regression suites and app privacy/cache tests; keyboard/narrow/light/dark acceptance, rebuild, tick and commit.

### Task 6: Final integration, one review and controlled rollout proposal

**Files:** Modify parent spec, roadmap and this plan; all slice-owned tests and assets as needed.

- [x] Self-review all Review Focus cases and touchpoints (permissions/seeding/entitlements/storage/routes/app/navigation/empty/errors/cache/docs). Record a Self-Review Report in this plan.
- [x] One fresh whole-branch review of implemented slices against their approved base. Fix Important/Critical findings with failing regressions in one grouped pass. No second blanket review; record manual limits and deferred minors honestly.
- [ ] External gate: one full remote CI after authorised upload. Local affected suites, Pint, static0/no baseline growth, lint/test/build, event material/recurrence/poll and attendee-portal guards completed; exact evidence below. Do not repeat a full local run just to duplicate CI.
- [ ] Owner reviews staff upload/publication, association constitution, church morning/evening notes, no-dues member/representative/member-context poll, revoke/expired proof and actual device/zoom behaviour. Use approved local QA fixtures only; real recipients and production content need separate authorisation.
- [ ] Update roadmap state with exact evidence and request merge/deploy approval. If approved later, enable the deployed slice for Purpledot under standing rollout instruction, preserve all other organisations, verify private storage and permissions before any real upload. Disabling starts must preserve history; never delete private documents or responses as rollback.

## Self-Review Report

### Touchpoints updated

- [x] AGENTS.md — organisation content, current access, private streams, replay keys and questionnaire ownership rules recorded.
- [x] Seeders — three permissions in PermissionsSeeder/RolePermissions/TENANT_SAFE; distinct package entitlements and missing-only migration propagation. Existing prices, administrator removals and rollout switches preserved.
- [x] Navigation — authorised staff Library/Polls sidebar entries, occurrence-level Programme shortcut and organisation Library/Services/Polls page-level links inside the same five app destinations.
- [x] Permissions — separate staff publishing/results/export permissions; member reads always use current active membership/group and native verified contexts. Representative read-only labels and self-only responses.
- [x] Config — private named local disk; immutable stored production private disk/path and tenant prefix. No public object links.
- [x] Tests — access/download/version/upload/retry/permission/rollout, dated notes, ten answer types, privacy and unchanged event/app contracts. Exact final evidence below.
- Memory files: N/A; the approved roadmap/spec/plan and execution ledger are the repository handover. No separate undocumented memory dependency.
- .env.example: N/A; no new environment variable or dependency. Existing production private storage must be verified before real-document rollout.

### UI Gap Analysis

- Discoverability: present from native console and Communities; Programme shortcut reuses the same service-note editor.
- Empty state: present; staff creation controls, neutral member empty state and retained-history guidance.
- Permission gating: present in both UI and direct endpoints. Poll results/export require their own permission.
- Loading/errors: present with request cancellation, retry, 403/404 handling and proof-expiry clearing. Validation retains typed questionnaire answers; clearing optional choices omits an empty response.
- Responsive: native local browser exercised390px and1280px without horizontal overflow. Actual physical devices and actual200% browser zoom remain owner acceptance, not claimed passed.
- Accessibility: associated native labels, fieldsets, keyboard focus and semantic status/alerts present. All accent actions use native contrast ink after the review fix. This is focused acceptance, not a complete assistive-technology certification.

### Solved (auto-fixed)

- Current-version authorised snapshot prevents replaced editions being served from stale models. Malformed audience selectors fail closed in list/detail/file paths.
- Empty native search query, create kind default, direct representative label and exact occurrence preselection corrected with regressions.
- Library lost-response replays use durable tenant/actor-scoped keys and content fingerprints. Changed reuse is409; old retries do not revert later edits or duplicate storage.
- Review Important1: archived events with any library history refuse manual/scheduled purge; event upload cleanup runs only after successful deletion commit. Rejected/direct FK-raced deletion retains material/hero bytes. Archiving/restoring remains available.
- Review Important2: transaction-error cleanup deletes only a confirmed unreferenced object. A committed edition survives a lost acknowledgement; unavailable database outcome deliberately retains the private object for reconciliation.
- Review Important3: Library/Services attachments use text-accent-ink; questionnaire submit uses native primary Button.
- Review minors: service lists consume the eager-loaded archived-safe event; independent bounded/searchable series choices survive empty date results and include the selected series.
- Native Console props, nullable date validation, question-array list validation and explicit payload/factory types corrected without baseline growth or new packages.

### Recommended for user decision

- Before real-document rollout, choose aggregate storage allowances for packages; current20MiB/file and any configured storage_bytes limit are enforced. No allowance or price was invented. Recommendation: configure explicit tier byte limits before inviting real uploads; entitlement configuration/acceptance is a small follow-up once amounts are chosen.
- Actual-device/zoom acceptance and the owner’s staff/member walkthrough remain. Recommendation: review the local Library, two service occurrences and volunteer questionnaire before authorising upload/CI and merge.
- When a database commit outcome cannot be established, retaining a private object is safer than deleting a committed edition. There is no automatic orphan pruning in this milestone. Recommendation: an explicit dry reconciliation/audit before deleting any uncertain object; defer a dedicated sweeper until operational volume warrants it.

### Not gaps

- No dues or weekly event registration is required for organisation content; existing attendee-only material/poll verification is unchanged.
- No public/follower library, anonymous ballots, elections, rich HTML, hosted audio/video or automatic message sending: deliberately outside the approved scope.
- Downloaded files cannot be remotely erased; subsequent reads recheck access and private content never enters the ticket-only offline cache.
- Remote CI, production private-storage verification and rollout remain pending approval. The branch contains the reviewed, still-unmerged M6 and dues-safeguard ancestry; integration must account for that stack.

### Verification and review evidence

- Native implementation, one fresh whole-M7 review of bd04734b..90aedd19. Reviewer found no Critical issues, three Important and two Minor; all addressed in one grouped fix pass. No second blanket review.
- Before grouped fixes: broad affected PHP204pass/2 native-component failures,835assertions; both corrected and focused Console5/5. Final payload/service/replay25/25,80assertions. Full JavaScript447/447; optional-answer regression subsequently6/6. Full static0 without baseline growth. Pint/lint/build pass; rebuilt assets tracked.
- Grouped fixes: PHP43/43,181assertions,148.13s covering manual/scheduled/direct purge bytes, normal file cleanup/deletion, uncertain commit, upload/version cleanup, series scope and N+1 budget. Console5/5,6assertions after final native Button integration. Affected JS15/15; lint/build/Pint pass. Final full static check is recorded in the roadmap. The EventSession-to-Event relationship contract also removes three obsolete baseline entries; no new suppression.
- Final local browser: selected service series remains available when a2099 date filter yields no rows;1280px no overflow. Native primary palette measured9.44:1 before disabled opacity (the pilot membership has already answered); no claim about disabled contrast/physical-device certification. One browser locator used an overly strict wrapping-label selector and timed out; actual role-based selection and filter journey passed.
- Local QA only: synthetic benefits-qa constitution, distinct morning/evening notes and volunteer survey. Staff published/opened; current no-dues member explicitly answered; staff saw identifiable response; member results hidden then aggregate publication verified. No real recipients, external sends, payments, production data, rollout or settlement changes.

## Plan self-review

The parent organisation Library/polls vision and individual service-note request are implemented through separate organisation records and shared native policies. Existing event tables, verification and money paths were preserved. Owner-approved recommendations are now implementation contracts in the parent spec. Remaining external gates are owner acceptance, authorised upload/full CI and later integration/deployment approval.
