# Attendee Portal — Plan B Implementation Plan

> **Superseded — do not execute.** On 2026-09-23 the owner approved a central platform attendee
> portal at `miconvener.com/my`. This tenant-scoped plan must be replaced after review of
> `docs/superpowers/specs/2026-09-23-platform-attendee-portal-design.md`.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add tenant-scoped attendee history for events, certificates, abstracts, and attendance, then make speaker-slide release predictable across the portal and organiser console.

**Architecture:** A thin `AttendeeHistoryController` reads the tenant from `TenantContext` and the proven email from `EnsureAttendeeVerified`; a focused `AttendeeHistory` service owns all tenant/email queries and returns stable JSON payloads. React history panels load only from the guarded JSON routes after verification. Material visibility remains exposed through `EventMaterial::isReleased()`, but a dedicated `MaterialReleasePolicy` resolves explicit dates and speaker policies consistently for every existing caller.

**Tech Stack:** Laravel 13 (Laravel 10 file layout), PHP 8.5, PostgreSQL, Pest 5, Inertia 3, React 19, Tailwind CSS 4, Vitest 4 + Testing Library.

**Spec:** `docs/superpowers/specs/2026-09-22-attendee-portal-design.md` — delivery steps 6–10 and the Plan B deferrals in section 14.

## Global Constraints

- The exact-host middleware completed before this plan remains the outer boundary of every `public.my*` route. Add every new endpoint inside the existing `Route::prefix('my')->middleware('tenant_from_host')` group.
- The tenant comes only from `TenantContext`; the attendee email comes only from the `attendee_email` request attribute populated by `attendee_verified`. No history action accepts tenant or email input.
- Verified history is JSON-only and must never be added to an Inertia page payload.
- Identity matching is case-insensitive. Registrations use `EventRegistration::forEmail()`; certificate and author queries use `lower(...)` with the already-normalised session email.
- Cancelled and rejected registrations never appear in history.
- Certificate `cpd_hours` and session attendance are separate facts. Never derive CPD hours from dwell time and never return a summed total.
- A past-event material link always uses that attendee's registration id so the existing confirmation, release, missing-file, and per-registration download-limit checks still apply.
- `release_at` always overrides the event speaker policy. An undated organiser material remains immediately released. An undated speaker material with no sessions stays withheld for `during` and `after`.
- No new design tokens and no organiser-console redesign. Reuse the portal's existing typography/spacing and `@/Components/Console/*` primitives.
- Use Form Requests for new write validation. Use Artisan `make:* --no-interaction` commands for framework files and migrations.
- Every task follows RED → GREEN, runs `php84 vendor/bin/pint --dirty`, and runs its focused PHP and JS tests before committing. Do not push or deploy without separate authorization.

## Review Focus

1. **A caller sends another email or tenant id to a history endpoint:** tests in Task 2 must prove those inputs are ignored and only the session's proven identity is returned.
2. **One address has duplicate registrations or appears more than once as an abstract author:** Tasks 2 and 5 must return each registration/abstract once, in deterministic order.
3. **A certificate is related by registration and by recipient email:** Task 4 must return it once, while still including speaker/volunteer certificates with no registration.
4. **A speaker deck has no linked session or several rescheduled sessions:** Task 7 must withhold sessionless `during`/`after` decks and use the first start/last end at request time rather than copied timestamps.
5. **A verified marker expires while panels are open:** Task 3 must replace panel content with the verification prompt after any endpoint returns 401, without exposing cached history under another organiser.

## File Map

| File | Task | Responsibility |
|---|---|---|
| `app/Models/AttendeeAccessCode.php`, `app/Console/Kernel.php` | 1 | prune expired/consumed proof codes |
| `resources/js/Pages/Public/Events/AttendeePortal/VerifyPrompt.jsx` | 1 | status-specific send failures |
| `resources/js/test/portal/VerifyPrompt.test.jsx` | 1 | resend, error, and five-try behavior |
| `app/Services/Events/AttendeeHistory.php` | 2, 4–6 | tenant/email-scoped history queries and payloads |
| `app/Http/Controllers/Public/AttendeeHistoryController.php` | 2, 4–6 | guarded JSON actions |
| `routes/subdomain.php` | 2, 4–6 | four endpoints inside the guarded `/my` group |
| `tests/Feature/Events/AttendeeHistoryTest.php` | 2, 4–6 | boundary and payload contract |
| `resources/js/Pages/Public/Events/AttendeePortal/VerifiedHistory.jsx` | 3–6 | fetch state, tabs, expiry handling |
| `resources/js/Pages/Public/Events/AttendeePortal/panels/My*Panel.jsx` | 3–6 | pure history-panel presentation |
| `resources/js/Pages/Public/Events/AttendeePortal/HistoryEntryLink.jsx` | 3 | tested `/my` entry from the public event page |
| `resources/js/test/portal/VerifiedHistory.test.jsx` | 3–6 | conditional tabs and 401 behavior |
| `database/migrations/landlord/2026_09_23_120000_add_speaker_release_policy_to_events_and_materials.php` | 7 | release policy and material provenance |
| `app/Services/Events/MaterialReleasePolicy.php` | 7 | one resolver for material release |
| `app/Models/Event.php`, `app/Models/EventMaterial.php` | 7 | constants, persistence, preserved `isReleased()` API |
| `app/Http/Requests/Tenant/UpdateSpeakerSlidesReleaseRequest.php` | 8 | validate organiser setting |
| `app/Http/Controllers/Tenant/EventMaterialController.php` | 8 | save policy and expose material source |
| `app/Http/Controllers/Public/SpeakerPortalController.php` | 8 | label uploads as speaker material |
| `app/Http/Controllers/Public/PublicEventController.php` | 8 | released session-material payload |
| `resources/js/Pages/Tenant/Events/panels/MaterialsPanel.jsx` | 8 | speaker release control |
| `resources/js/Pages/Public/Events/AttendeePortal/panels/MyDayPanel.jsx` | 8 | material links beside sessions |

---

### Task 1: Close Plan A's verification maintenance and UI gaps

**Files:**
- Modify: `app/Models/AttendeeAccessCode.php`
- Modify: `app/Console/Kernel.php`
- Create: `tests/Feature/Events/AttendeeAccessCodePruningTest.php`
- Modify: `tests/Feature/Events/AttendeeVerificationTest.php`
- Modify: `resources/js/Pages/Public/Events/AttendeePortal/VerifyPrompt.jsx`
- Modify: `resources/js/test/portal/VerifyPrompt.test.jsx`

**Interfaces:**
- Produces: `AttendeeAccessCode::prunable(): Builder`; one daily `model:prune` schedule; send errors that distinguish 422, 429, other server failures, and network failures.

- [ ] **Step 1: Write the failing pruning tests**

Create the Pest feature test with three rows: an expired unconsumed code is prunable, a consumed code older than one day is prunable, and an unexpired unconsumed code is retained. Assert the query itself, not only command output:

```php
$prunableIds = (new AttendeeAccessCode())->prunable()->pluck('id');

expect($prunableIds)
    ->toContain($expired->id, $oldConsumed->id)
    ->not->toContain($live->id);
```

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Feature/Events/AttendeeAccessCodePruningTest.php
```

Expected: FAIL because the model has no `prunable()` method.

- [ ] **Step 2: Implement pruning and schedule it**

Add `MassPrunable` to `AttendeeAccessCode` and this exact retention boundary:

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;

use MassPrunable;

public function prunable(): Builder
{
    return self::query()->where(function (Builder $query): void {
        $query->where('expires_at', '<=', now())
            ->orWhere('consumed_at', '<=', now()->subDay());
    });
}
```

In `app/Console/Kernel.php`, share the existing 04:00 wake window without overlapping:

```php
$schedule->command('model:prune --model="App\\Models\\AttendeeAccessCode"')
    ->dailyAt('04:00')
    ->withoutOverlapping();
```

Run the pruning test and `tests/Unit/Console/ScheduledUsageResetTest.php`; expected PASS.

- [ ] **Step 3: Pin verification UI errors and timers before changing them**

Extend `VerifyPrompt.test.jsx` with fake timers and mocked `csrfFetch` cases that assert:

```js
it.each([
    [429, {}, 'Too many attempts just now. Try again in a minute.'],
    [422, { message: 'The email field must be a valid email address.' }, 'The email field must be a valid email address.'],
    [500, {}, "Couldn't send a code. Try again."],
])('maps send status %s without calling it a rate limit', async (status, body, message) => {
    csrfFetch.mockResolvedValueOnce(response(status, body));
    render(<VerifyPrompt onVerified={vi.fn()} />);
    fireEvent.change(screen.getByLabelText('The address you registered with'), { target: { value: 'ama@stem.org' } });
    fireEvent.click(screen.getByRole('button', { name: 'Email me a code' }));
    expect(await screen.findByText(message)).toBeTruthy();
});
```

Add one test that advances 60 seconds and sees `Send another code`, and one that submits two wrong codes and sees the five-try explanation. Run `npm test -- resources/js/test/portal/VerifyPrompt.test.jsx`; expected at least the 422/500 cases to FAIL against the current catch-all copy.

- [ ] **Step 4: Implement status-specific copy and finish server edge coverage**

Replace the send failure branch with:

```js
if (!response.ok) {
    if (response.status === 429) {
        setMessage('Too many attempts just now. Try again in a minute.');
    } else if (response.status === 422 && json.message) {
        setMessage(json.message);
    } else {
        setMessage("Couldn't send a code. Try again.");
    }
    return;
}
```

In `AttendeeVerificationTest.php`, add explicit cases proving an unknown registration id and a registration belonging to another tenant both queue the neutral job response but send no mail when the job runs, and the sixth send for one tenant/address is silently capped while the public response remains identical.

Run:

```bash
npm test -- resources/js/test/portal/VerifyPrompt.test.jsx
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Feature/Events/AttendeeAccessCodePruningTest.php tests/Feature/Events/AttendeeVerificationTest.php tests/Unit/Console/ScheduledUsageResetTest.php
```

Expected: PASS.

- [ ] **Step 5: Format, analyse, and commit**

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/pint --dirty
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/phpstan analyse --no-progress --memory-limit=2G
git add app/Models/AttendeeAccessCode.php app/Console/Kernel.php tests/Feature/Events/AttendeeAccessCodePruningTest.php tests/Feature/Events/AttendeeVerificationTest.php resources/js/Pages/Public/Events/AttendeePortal/VerifyPrompt.jsx resources/js/test/portal/VerifyPrompt.test.jsx
git commit -m "fix(portal): finish verification lifecycle safeguards"
```

---

### Task 2: Add the guarded My events endpoint

**Files:**
- Create: `app/Services/Events/AttendeeHistory.php`
- Create: `app/Http/Controllers/Public/AttendeeHistoryController.php`
- Modify: `routes/subdomain.php`
- Create: `tests/Feature/Events/AttendeeHistoryTest.php`
- Modify: `tests/Feature/Events/AttendeePortalRouteSecurityTest.php`

**Interfaces:**
- Consumes: `TenantContext::getTenant(): ?Tenant`; request attribute `attendee_email`; `EventRegistration::forEmail()`; `EventMaterial::isReleased()`.
- Produces: `AttendeeHistory::events(Tenant $tenant, string $email): array{upcoming: list<array<string,mixed>>, past: list<array<string,mixed>>}` and `GET /my/events` named `public.my.events`.

- [ ] **Step 1: Scaffold files and write failing endpoint tests**

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan make:class Services/Events/AttendeeHistory --no-interaction
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan make:controller Public/AttendeeHistoryController --no-interaction
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan make:test --pest Events/AttendeeHistoryTest --no-interaction
```

In the new test, create `historyFixture(string $slug, string $email = 'ama@stem.org')` returning tenant, host, and a `provenFor()` session marker. Add tests for: 401 without proof; 404 on `www` even with a proof/header; upcoming and past split by `ends_at`; cancelled/rejected exclusion; mixed-case match; duplicate registrations retained as separate registrations in stable `starts_at,id` order; a supplied `?email=intruder@...&tenant_id=...` ignored; another tenant's same-email data absent; only released materials included; and each material URL contains its owning registration id.

The successful JSON contract is:

```php
[
    'upcoming' => [[
        'registration_id' => $registration->id,
        'registration_status' => $registration->status,
        'event' => [
            'name' => $event->name,
            'slug' => $event->slug,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
        ],
        'portal_url' => route('public.events.confirmation', [
            'subdomain' => $tenant->slug,
            'event' => $event->slug,
            'registration' => $registration->id,
        ]),
        'materials' => [[
            'id' => $material->id,
            'title' => $material->title,
            'remaining_attempts' => 3,
            'download_url' => route('public.events.materials.download', [
                'subdomain' => $tenant->slug,
                'event' => $event->slug,
                'registration' => $registration->id,
                'material' => $material->id,
            ]),
        ]],
    ]],
    'past' => [],
]
```

Run the new test; expected FAIL with route not found/404.

- [ ] **Step 2: Add the route and thin controller**

Inside the existing guarded `/my` group, after `/session`, add:

```php
Route::get('/events', [App\Http\Controllers\Public\AttendeeHistoryController::class, 'events'])
    ->middleware('attendee_verified')
    ->name('public.my.events');
```

Implement the controller without accepting identity arguments:

```php
final readonly class AttendeeHistoryController extends Controller
{
    public function __construct(private AttendeeHistory $history) {}

    public function events(Request $request): JsonResponse
    {
        return response()->json($this->history->events(
            $this->tenant(),
            (string) $request->attributes->get('attendee_email'),
        ));
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(404);
    }
}
```

- [ ] **Step 3: Implement the events query and payload**

`AttendeeHistory::events()` must start with:

```php
$registrations = EventRegistration::query()
    ->where('tenant_id', $tenant->id)
    ->forEmail($email)
    ->whereNotIn('status', [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED])
    ->with(['event.materials.downloads'])
    ->get()
    ->sortBy(fn (EventRegistration $registration): string =>
        $registration->event->starts_at->format('Y-m-d H:i:s.u').$registration->id
    );
```

Map each registration to the contract from Step 1. Include materials only when `$registration->isConfirmed()` and `$material->isReleased()`. Partition with `$registration->event->ends_at->isPast()`, sort upcoming ascending and past descending, and return `values()->all()` lists.

- [ ] **Step 4: Verify the structural boundary and focused behavior**

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan route:list --path=my --json
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Feature/Events/AttendeeHistoryTest.php tests/Feature/Events/AttendeePortalHostTest.php tests/Feature/Events/AttendeePortalRouteSecurityTest.php tests/Feature/Events/EventMaterialTest.php
```

Expected: PASS; `public.my.events` lists `EnsureTenantFromHost` before `EnsureAttendeeVerified`.

- [ ] **Step 5: Format, analyse, and commit**

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/pint --dirty
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/phpstan analyse --no-progress --memory-limit=2G
git add app/Services/Events/AttendeeHistory.php app/Http/Controllers/Public/AttendeeHistoryController.php routes/subdomain.php tests/Feature/Events/AttendeeHistoryTest.php tests/Feature/Events/AttendeePortalRouteSecurityTest.php
git commit -m "feat(portal): show attendee event history"
```

---

### Task 3: Put verified history into both portal entry points

**Files:**
- Create: `resources/js/Pages/Public/Events/AttendeePortal/VerifiedHistory.jsx`
- Create: `resources/js/Pages/Public/Events/AttendeePortal/HistoryEntryLink.jsx`
- Create: `resources/js/Pages/Public/Events/AttendeePortal/panels/MyEventsPanel.jsx`
- Modify: `resources/js/Pages/Public/Events/AttendeePortal/MyPortal.jsx`
- Modify: `resources/js/Pages/Public/Events/AttendeePortal/Portal.jsx`
- Modify: `resources/js/Pages/Public/Events/Show.jsx`
- Modify: `app/Http/Controllers/Public/PublicEventController.php`
- Create: `resources/js/test/portal/VerifiedHistory.test.jsx`
- Modify: `resources/js/test/portal/Portal.test.jsx`
- Modify: `tests/Feature/Events/AttendeePortalPageTest.php`

**Interfaces:**
- Consumes: `GET public.my.events`; masked proof string from page props; optional `currentRegistrationId`.
- Produces: `<VerifiedHistory verifiedEmail onVerificationExpired currentRegistrationId={null} />`; `MyEventsPanel({ upcoming, past })`.

- [ ] **Step 1: Write failing component and page-prop tests**

Mock `csrfFetch` in `VerifiedHistory.test.jsx`. Pin these behaviors: `/my` shows My events even when its lists are empty; a registration portal hides My events when the only result has `registration_id === currentRegistrationId`; another event makes the tab appear; upcoming precedes past; a 401 calls `onVerificationExpired` and removes rendered history; and another non-OK response shows a retry message without signing out.

Also render `HistoryEntryLink` and assert its anchor uses `route('public.my')` with the current subdomain. This is the small tested component inserted beside the existing “Already registered? Find my ticket” block in `Public/Events/Show.jsx`; its copy is “See all my events”.

In `AttendeePortalPageTest.php`, prove `verifiedEmail` is masked or null and prove no key named `events`, `certificates`, `abstracts`, or `attendance` appears in Inertia props.

Run the PHP and JS files; expected FAIL because the component/prop does not exist.

- [ ] **Step 2: Add proof state to the registration page only**

Inject `AttendeeVerification` into `PublicEventController` through constructor promotion following its existing constructor pattern. In `confirmation()`, add only:

```php
'verifiedEmail' => ($verifiedEmail = $this->attendeeVerification->verifiedEmail($request->session(), $tenant)) !== null
    ? ContactMask::email($verifiedEmail)
    : null,
```

Do not add history arrays to the page.

- [ ] **Step 3: Implement `VerifiedHistory` and the events panel**

On mount when `verifiedEmail` is non-null, fetch `route('public.my.events')`. Store `{ loading, error, events }`. For a 401, clear all stored data and call `onVerificationExpired()`. Compute registration-entry rows with:

```js
const visibleUpcoming = events.upcoming.filter(
    (item) => item.registration_id !== currentRegistrationId
);
const visiblePast = events.past.filter((item) => item.registration_id !== currentRegistrationId);
const showEvents = currentRegistrationId === null || visibleUpcoming.length + visiblePast.length > 0;
```

`MyEventsPanel` renders event name, dates, `portal_url`, and released material links. Empty `/my` state says “No events found for this address yet.”

- [ ] **Step 4: Wire both entry points**

In `MyPortal`, replace the signed-in paragraph-only state with the paragraph plus `VerifiedHistory`. Its expiry callback clears `verifiedEmail` so `VerifyPrompt` returns.

In `Portal`, add local `historyEmail` state initialized from `verifiedEmail`. Below the existing open panels, render either `VerifyPrompt` with `registrationId={registration.id}` and `sentTo={registration.email}`, or `VerifiedHistory` with `currentRegistrationId={registration.id}`. This proof prompt must not replace or delay the ticket/agenda/help/download panels.

In `Public/Events/Show.jsx`, render `HistoryEntryLink` beside the existing find-ticket action. The link goes to the tenant subdomain's `public.my` route; it does not carry an email, registration id, or other identity input.

- [ ] **Step 5: Run the portal tests and build**

```bash
npm test -- resources/js/test/portal/VerifiedHistory.test.jsx resources/js/test/portal/Portal.test.jsx resources/js/test/portal/VerifyPrompt.test.jsx
npm run lint
npm run build
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Feature/Events/AttendeePortalPageTest.php tests/Feature/Events/AttendeeHistoryTest.php tests/Feature/Events/AttendeeVerificationTest.php
```

Expected: PASS.

- [ ] **Step 6: Format, analyse, and commit**

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/pint --dirty
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/phpstan analyse --no-progress --memory-limit=2G
git add resources/js/Pages/Public/Events/AttendeePortal/VerifiedHistory.jsx resources/js/Pages/Public/Events/AttendeePortal/HistoryEntryLink.jsx resources/js/Pages/Public/Events/AttendeePortal/panels/MyEventsPanel.jsx resources/js/Pages/Public/Events/AttendeePortal/MyPortal.jsx resources/js/Pages/Public/Events/AttendeePortal/Portal.jsx resources/js/Pages/Public/Events/Show.jsx app/Http/Controllers/Public/PublicEventController.php resources/js/test/portal/VerifiedHistory.test.jsx resources/js/test/portal/Portal.test.jsx tests/Feature/Events/AttendeePortalPageTest.php
git commit -m "feat(portal): surface verified event history"
```

---

### Task 4: Add My certificates without duplicates

**Files:**
- Modify: `app/Services/Events/AttendeeHistory.php`
- Modify: `app/Http/Controllers/Public/AttendeeHistoryController.php`
- Modify: `routes/subdomain.php`
- Modify: `tests/Feature/Events/AttendeeHistoryTest.php`
- Create: `resources/js/Pages/Public/Events/AttendeePortal/panels/MyCertificatesPanel.jsx`
- Modify: `resources/js/Pages/Public/Events/AttendeePortal/VerifiedHistory.jsx`
- Modify: `resources/js/test/portal/VerifiedHistory.test.jsx`

**Interfaces:**
- Produces: `AttendeeHistory::certificates(Tenant $tenant, string $email): list<array<string,mixed>>`; `GET /my/certificates` named `public.my.certificates`.

- [ ] **Step 1: Write failing API tests**

Add cases for: a registration-linked certificate; a speaker certificate with null `registration_id`; mixed-case `recipient_email`; exclusion of another tenant; and one certificate that matches both registration id and recipient email appearing once. Assert each row has `event_name`, `recipient_name`, `role`, numeric `cpd_hours`, `issued_at`, `verify_url`, and `download_url`.

Run the history test; expected FAIL with 404.

- [ ] **Step 2: Add the route, controller action, and query**

Add `/certificates` under the same guarded group with `attendee_verified`. Add a controller action identical in shape to `events()`.

Implement the query as one grouped predicate so tenant scoping cannot be bypassed:

```php
$registrationIds = $this->registrationIds($tenant, $email);

return EventCertificate::query()
    ->where('tenant_id', $tenant->id)
    ->where(function (Builder $query) use ($registrationIds, $email): void {
        $query->whereIn('registration_id', $registrationIds)
            ->orWhereRaw('lower(recipient_email) = ?', [AttendeeVerification::normalise($email)]);
    })
    ->with('event:id,name,slug')
    ->orderByDesc('issued_at')
    ->orderBy('id')
    ->get()
    ->unique('id')
    ->map(fn (EventCertificate $certificate): array => [
        'id' => $certificate->id,
        'event_name' => $certificate->event->name,
        'recipient_name' => $certificate->recipient_name,
        'role' => $certificate->role,
        'cpd_hours' => $certificate->cpd_hours,
        'issued_at' => $certificate->issued_at?->toIso8601String(),
        'verify_url' => route('public.certificates.verify', [
            'subdomain' => $tenant->slug,
            'uuid' => $certificate->uuid,
        ]),
        'download_url' => route('public.certificates.download', [
            'subdomain' => $tenant->slug,
            'uuid' => $certificate->uuid,
        ]),
    ])
    ->values()
    ->all();
```

`registrationIds()` uses the same tenant/email/status boundary as events.

- [ ] **Step 3: Add the conditional panel**

`VerifiedHistory` fetches `public.my.certificates` beside events. Add a Certificates tab only when the list is non-empty. `MyCertificatesPanel` renders event, role, issued date, CPD hours when non-null, and separate Verify/Download links. A 401 from either request expires the whole verified state.

- [ ] **Step 4: Verify and commit**

```bash
npm test -- resources/js/test/portal/VerifiedHistory.test.jsx
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Feature/Events/AttendeeHistoryTest.php tests/Feature/Events/AttendeePortalRouteSecurityTest.php tests/Feature/Events/EventMultiRoleCertificatesTest.php
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/pint --dirty
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/phpstan analyse --no-progress --memory-limit=2G
git add app/Services/Events/AttendeeHistory.php app/Http/Controllers/Public/AttendeeHistoryController.php routes/subdomain.php tests/Feature/Events/AttendeeHistoryTest.php resources/js/Pages/Public/Events/AttendeePortal/panels/MyCertificatesPanel.jsx resources/js/Pages/Public/Events/AttendeePortal/VerifiedHistory.jsx resources/js/test/portal/VerifiedHistory.test.jsx
git commit -m "feat(portal): list attendee certificates"
```

---

### Task 5: Add My abstracts by authorship

**Files:**
- Modify: `app/Services/Events/AttendeeHistory.php`
- Modify: `app/Http/Controllers/Public/AttendeeHistoryController.php`
- Modify: `routes/subdomain.php`
- Modify: `tests/Feature/Events/AttendeeHistoryTest.php`
- Create: `resources/js/Pages/Public/Events/AttendeePortal/panels/MyAbstractsPanel.jsx`
- Modify: `resources/js/Pages/Public/Events/AttendeePortal/VerifiedHistory.jsx`
- Modify: `resources/js/test/portal/VerifiedHistory.test.jsx`

**Interfaces:**
- Produces: `AttendeeHistory::abstracts(Tenant $tenant, string $email): list<array<string,mixed>>`; `GET /my/abstracts` named `public.my.abstracts`.

- [ ] **Step 1: Write failing authorship tests**

Add cases proving: a co-author with no registration sees the abstract; case differs; another tenant's abstract is absent; a duplicate author row with the same email does not duplicate the abstract; and the session email wins over a supplied request email. Assert `id`, `code`, `title`, `status`, `presentation_preference`, `event_name`, `submitted_at`, and the existing public `tracking_url`.

Run the history test; expected FAIL with 404.

- [ ] **Step 2: Implement the endpoint**

Add `/abstracts` with both host and verification middleware. Query from `EventAbstract` so one abstract is intrinsically one row:

```php
return EventAbstract::query()
    ->where('tenant_id', $tenant->id)
    ->whereHas('authors', fn (Builder $query): Builder =>
        $query->whereRaw('lower(email) = ?', [AttendeeVerification::normalise($email)]))
    ->with('event:id,name,slug')
    ->orderByDesc('created_at')
    ->orderBy('id')
    ->get()
    ->map(fn (EventAbstract $abstract): array => [
        'id' => $abstract->id,
        'code' => $abstract->code,
        'title' => $abstract->title,
        'status' => $abstract->status,
        'presentation_preference' => $abstract->presentation_preference,
        'event_name' => $abstract->event->name,
        'submitted_at' => $abstract->created_at->toIso8601String(),
        'tracking_url' => route('public.events.abstracts.show', [
            'subdomain' => $tenant->slug,
            'event' => $abstract->event->slug,
            'code' => $abstract->code,
        ]),
    ])->all();
```

- [ ] **Step 3: Add the panel and verify**

Fetch abstracts alongside the existing resources. Show the tab only when non-empty. Render title, event, human-readable status (underscores replaced with spaces), preference, submission date, and a tracking link.

```bash
npm test -- resources/js/test/portal/VerifiedHistory.test.jsx
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Feature/Events/AttendeeHistoryTest.php tests/Feature/Events/AttendeePortalRouteSecurityTest.php tests/Feature/Events/EventAbstractSubmissionTest.php
```

Expected: PASS.

- [ ] **Step 4: Format, analyse, and commit**

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/pint --dirty
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/phpstan analyse --no-progress --memory-limit=2G
git add app/Services/Events/AttendeeHistory.php app/Http/Controllers/Public/AttendeeHistoryController.php routes/subdomain.php tests/Feature/Events/AttendeeHistoryTest.php resources/js/Pages/Public/Events/AttendeePortal/panels/MyAbstractsPanel.jsx resources/js/Pages/Public/Events/AttendeePortal/VerifiedHistory.jsx resources/js/test/portal/VerifiedHistory.test.jsx
git commit -m "feat(portal): list attendee abstracts"
```

---

### Task 6: Add My attendance without manufacturing hours

**Files:**
- Modify: `app/Services/Events/AttendeeHistory.php`
- Modify: `app/Http/Controllers/Public/AttendeeHistoryController.php`
- Modify: `routes/subdomain.php`
- Modify: `tests/Feature/Events/AttendeeHistoryTest.php`
- Create: `resources/js/Pages/Public/Events/AttendeePortal/panels/MyAttendancePanel.jsx`
- Modify: `resources/js/Pages/Public/Events/AttendeePortal/VerifiedHistory.jsx`
- Modify: `resources/js/test/portal/VerifiedHistory.test.jsx`

**Interfaces:**
- Produces: `AttendeeHistory::attendance(Tenant $tenant, string $email): array{sessions: list<array<string,mixed>>, certificates: list<array<string,mixed>>}`; `GET /my/attendance` named `public.my.attendance`.

- [ ] **Step 1: Write failing separation tests**

Create two attended sessions and one certificate with `cpd_hours = 3.5`. Assert the response has `sessions` with check-in/check-out presence and `certificates` with `cpd_hours`, but has no `total`, `total_hours`, `duration_hours`, or computed CPD value. Add other-tenant, rejected-registration, and mixed-case cases.

Run the history test; expected FAIL with 404.

- [ ] **Step 2: Implement the endpoint**

Use `registrationIds()` for both parts. Attendance rows are tenant scoped and eager-load event/session:

```php
$sessions = EventSessionAttendance::query()
    ->where('tenant_id', $tenant->id)
    ->whereIn('registration_id', $registrationIds)
    ->with(['event:id,name', 'session:id,title,starts_at,ends_at,location'])
    ->orderByDesc('checked_in_at')
    ->get()
    ->map(fn (EventSessionAttendance $attendance): array => [
        'event_name' => $attendance->event->name,
        'session_title' => $attendance->session->title,
        'session_starts_at' => $attendance->session->starts_at->toIso8601String(),
        'session_ends_at' => $attendance->session->ends_at->toIso8601String(),
        'location' => $attendance->session->location,
        'checked_in_at' => $attendance->checked_in_at?->toIso8601String(),
        'checked_out_at' => $attendance->checked_out_at?->toIso8601String(),
    ])->all();
```

Build the `certificates` member from the same certificate identity rule as Task 4, returning only `event_name`, `role`, `cpd_hours`, and `verify_url`. Do not call `contactHoursEarned()` and do not sum either list.

- [ ] **Step 3: Add the panel and verify**

The Attendance tab appears when either list is non-empty. Render “Sessions attended” and “Certified hours” as separate sections. The certificate section labels each value; it has no grand total.

```bash
npm test -- resources/js/test/portal/VerifiedHistory.test.jsx
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Feature/Events/AttendeeHistoryTest.php tests/Feature/Events/AttendeePortalRouteSecurityTest.php tests/Feature/Events/EventSessionAttendanceTest.php
```

Expected: PASS.

- [ ] **Step 4: Format, analyse, and commit**

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/pint --dirty
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/phpstan analyse --no-progress --memory-limit=2G
git add app/Services/Events/AttendeeHistory.php app/Http/Controllers/Public/AttendeeHistoryController.php routes/subdomain.php tests/Feature/Events/AttendeeHistoryTest.php resources/js/Pages/Public/Events/AttendeePortal/panels/MyAttendancePanel.jsx resources/js/Pages/Public/Events/AttendeePortal/VerifiedHistory.jsx resources/js/test/portal/VerifiedHistory.test.jsx
git commit -m "feat(portal): separate attendance from certified hours"
```

---

### Task 7: Make speaker-slide release one explicit policy

**Files:**
- Create: `database/migrations/landlord/2026_09_23_120000_add_speaker_release_policy_to_events_and_materials.php`
- Modify: `app/Models/Event.php`
- Modify: `app/Models/EventMaterial.php`
- Modify: `app/Models/Speaker.php`
- Modify: `app/Services/Events/AttendeeHistory.php`
- Modify: `database/factories/EventFactory.php`
- Modify: `database/factories/EventMaterialFactory.php`
- Create: `app/Services/Events/MaterialReleasePolicy.php`
- Create: `tests/Feature/Events/MaterialReleasePolicyTest.php`
- Modify: `tests/Feature/Events/EventMaterialTest.php`

**Interfaces:**
- Produces: `Event::{SPEAKER_SLIDES_BEFORE,DURING,AFTER}`; `EventMaterial::{SOURCE_ORGANISER,SOURCE_SPEAKER}`; `MaterialReleasePolicy::isReleased(EventMaterial $material, ?CarbonInterface $at = null): bool`; preserved `EventMaterial::isReleased(): bool`.

- [ ] **Step 1: Generate the migration and write failing policy tests**

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan make:migration add_speaker_release_policy_to_events_and_materials --path=database/migrations/landlord --no-interaction
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan make:class Services/Events/MaterialReleasePolicy --no-interaction
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan make:test --pest Events/MaterialReleasePolicyTest --no-interaction
```

The policy test uses `CarbonImmutable` times and database-backed speaker/session relations. Cover all of these before implementation: explicit past/future `release_at` wins for either source; undated organiser is released; speaker `before` releases immediately; `during` releases at the first linked session start; `after` releases only at the last linked session end; sessionless `during`/`after` is withheld; unrelated speakers' sessions do not affect the deck; changing a session time changes the answer without copying data.

Run the test; expected FAIL for missing columns/constants/service behavior.

- [ ] **Step 2: Add additive schema and model contracts**

Migration `up()`:

```php
Schema::connection('landlord')->table('events', function (Blueprint $table): void {
    $table->string('speaker_slides_release')->default('after')->after('registration_settings');
});

Schema::connection('landlord')->table('event_materials', function (Blueprint $table): void {
    $table->string('source')->default('organiser')->after('session_id');
    $table->index(['event_id', 'source']);
});
```

The `down()` drops the index/column from materials, then the event column. Add constants, fillable fields, and factory defaults. Existing rows become organiser/after through database defaults; do not issue a data rewrite.

- [ ] **Step 3: Implement the resolver from live relationships**

Add these relationships:

```php
// EventMaterial
public function speakerAssignments(): HasMany
{
    return $this->hasMany(EventSpeaker::class, 'slides_material_id');
}

// Speaker
public function sessions(): BelongsToMany
{
    return $this->belongsToMany(
        EventSession::class,
        'event_session_speakers',
        'speaker_id',
        'session_id',
    );
}
```

Callers that map several materials must eager-load `speakerAssignments.speaker.sessions`; in this task update `AttendeeHistory` to load that path with event materials. `MaterialReleasePolicy::isReleased()` uses the loaded relations when present and otherwise loads the same path once, then applies this order:

```php
$at ??= now();

if ($material->release_at !== null) {
    return $material->release_at->lte($at);
}

if ($material->source !== EventMaterial::SOURCE_SPEAKER) {
    return true;
}

$event = $material->event;
if ($event->speaker_slides_release === Event::SPEAKER_SLIDES_BEFORE) {
    return true;
}

$assignments = $material->relationLoaded('speakerAssignments')
    ? $material->speakerAssignments
    : $material->speakerAssignments()->with('speaker.sessions')->get();

$sessions = $assignments
    ->flatMap(fn (EventSpeaker $assignment) => $assignment->speaker->sessions)
    ->where('event_id', $material->event_id)
    ->unique('id');

if ($sessions->isEmpty()) {
    return false;
}

return match ($event->speaker_slides_release) {
    Event::SPEAKER_SLIDES_DURING => $sessions->min('starts_at')->lte($at),
    Event::SPEAKER_SLIDES_AFTER => $sessions->max('ends_at')->lte($at),
    default => false,
};
```

Preserve every caller by changing `EventMaterial::isReleased()` to delegate to the service. Add a query-count assertion covering three speaker materials loaded through `AttendeeHistory`; it must not grow once per material. Do not duplicate the rule in controllers.

- [ ] **Step 4: Run migrations and regression tests**

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan migrate --env=testing --force
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Feature/Events/MaterialReleasePolicyTest.php tests/Feature/Events/EventMaterialTest.php tests/Feature/Events/AttendeePortalPageTest.php tests/Feature/Events/AttendeeHistoryTest.php
```

Expected: PASS; existing undated organiser materials remain released.

- [ ] **Step 5: Format, analyse, and commit**

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/pint --dirty
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/phpstan analyse --no-progress --memory-limit=2G
git add database/migrations/landlord/2026_09_23_120000_add_speaker_release_policy_to_events_and_materials.php app/Models/Event.php app/Models/EventMaterial.php app/Models/Speaker.php app/Services/Events/AttendeeHistory.php database/factories/EventFactory.php database/factories/EventMaterialFactory.php app/Services/Events/MaterialReleasePolicy.php tests/Feature/Events/MaterialReleasePolicyTest.php tests/Feature/Events/EventMaterialTest.php
git commit -m "feat(materials): resolve speaker slide release by event policy"
```

---

### Task 8: Wire material source, organiser setting, and session links

**Files:**
- Create: `app/Http/Requests/Tenant/UpdateSpeakerSlidesReleaseRequest.php`
- Modify: `app/Http/Controllers/Tenant/EventMaterialController.php`
- Modify: `app/Http/Controllers/Public/SpeakerPortalController.php`
- Modify: `app/Http/Controllers/Public/PublicEventController.php`
- Modify: `routes/subdomain.php`
- Modify: `resources/js/Pages/Tenant/Events/panels/MaterialsPanel.jsx`
- Modify: `resources/js/Pages/Public/Events/AttendeePortal/panels/MyDayPanel.jsx`
- Modify: `resources/js/test/portal/Portal.test.jsx`
- Modify: `tests/Feature/Events/EventMaterialTest.php`
- Modify: `tests/Feature/Events/SpeakerPortalTest.php`

**Interfaces:**
- Consumes: Task 7 constants and `EventMaterial::isReleased()`.
- Produces: `PATCH events/{event}/materials/speaker-release` named `tenant.events.materials.speaker-release`; each released material payload has `source` and `session_ids`.

- [ ] **Step 1: Write failing source, setting, and My day tests**

Server tests must prove: speaker upload creates `source = speaker`; organiser upload creates `source = organiser`; only a user with `update event` can change the policy; invalid policy is 422; the event/material payload exposes the selected value/source; and a released speaker deck appears beside every session for its speaker but not another session.

Extend `Portal.test.jsx` so My day renders a released organiser session file and one speaker deck link, does not render an unreleased file, and does not duplicate the deck in one session.

Run focused PHP/JS tests; expected FAIL.

- [ ] **Step 2: Add the validated organiser endpoint**

Generate the request:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan make:request Tenant/UpdateSpeakerSlidesReleaseRequest --no-interaction
```

Its rules are:

```php
public function rules(): array
{
    return [
        'speaker_slides_release' => ['required', Rule::in([
            Event::SPEAKER_SLIDES_BEFORE,
            Event::SPEAKER_SLIDES_DURING,
            Event::SPEAKER_SLIDES_AFTER,
        ])],
    ];
}
```

Authorization remains in the controller with `$this->authorize('update event')`. Add the PATCH route beside the material store/delete routes. The action scopes the event through tenant id, updates the one field, and returns `['speaker_slides_release' => $event->speaker_slides_release]`.

- [ ] **Step 3: Persist provenance at both upload paths**

Add `source => EventMaterial::SOURCE_ORGANISER` in `EventMaterialController::store()` and `source => EventMaterial::SOURCE_SPEAKER` when `SpeakerPortalController` creates a material. When replacing an existing speaker deck, also set `source` to speaker so a pre-migration/mislabelled row repairs itself.

Expose `source` from `EventMaterialController::toPayload()` and the event workspace payload. Expose `speaker_slides_release` on the event payload.

- [ ] **Step 4: Derive released material session ids for the open portal**

In `PublicEventController::confirmation()`, eager-load the relationships required by the release resolver. For each released material:

- organiser source: `session_ids` is `[session_id]` when present, otherwise `[]`;
- speaker source: query the `EventSpeaker` row(s) whose `slides_material_id` matches, then derive all event sessions containing those speakers;
- `download_url` remains the existing registration-specific route.

Return unique `session_ids`. Do not write a speaker session id onto `event_materials`.

- [ ] **Step 5: Add the console control and My day links**

At the top of `MaterialsPanel`, add a compact select labelled “Speaker slides become available” with values/copy:

```js
[
    ['before', 'As soon as they are uploaded'],
    ['during', 'When the speaker’s first session starts'],
    ['after', 'After the speaker’s last session ends'],
]
```

PATCH on change, show a saving state, toast failures, and restore the previous value on failure. This control governs speaker uploads only; keep the organiser upload's explicit Release field unchanged.

In `MyDayPanel`, index `materials` by `session_ids` and render each session's links below its time/location. Keep the separate Downloads panel unchanged.

- [ ] **Step 6: Verify all material surfaces**

```bash
npm test -- resources/js/test/portal/Portal.test.jsx resources/js/test/portal/VerifiedHistory.test.jsx
npm run lint
npm run build
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Feature/Events/EventMaterialTest.php tests/Feature/Events/SpeakerPortalTest.php tests/Feature/Events/AttendeePortalPageTest.php tests/Feature/Events/AttendeeHistoryTest.php tests/Feature/Events/FreePlanLimitsTest.php
```

Expected: PASS.

- [ ] **Step 7: Format, analyse, and commit**

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/pint --dirty
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/phpstan analyse --no-progress --memory-limit=2G
git add app/Http/Requests/Tenant/UpdateSpeakerSlidesReleaseRequest.php app/Http/Controllers/Tenant/EventMaterialController.php app/Http/Controllers/Public/SpeakerPortalController.php app/Http/Controllers/Public/PublicEventController.php routes/subdomain.php resources/js/Pages/Tenant/Events/panels/MaterialsPanel.jsx resources/js/Pages/Public/Events/AttendeePortal/panels/MyDayPanel.jsx resources/js/test/portal/Portal.test.jsx tests/Feature/Events/EventMaterialTest.php tests/Feature/Events/SpeakerPortalTest.php
git commit -m "feat(materials): apply speaker release policy across portal surfaces"
```

---

### Task 9: Final integration, security audit, and full verification

**Files:**
- Modify only when a failing gate identifies a defect in Tasks 1–8.

**Interfaces:**
- Consumes: all Plan B endpoints, panels, and material policy.
- Produces: one verified, reviewable Plan B branch.

- [ ] **Step 1: Audit route and payload boundaries**

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan route:list --path=my --json
rg -n "request->(input|get)\('(email|tenant|tenant_id)'|validated\(\).*email" app/Http/Controllers/Public/AttendeeHistoryController.php app/Services/Events/AttendeeHistory.php
rg -n "events|certificates|abstracts|attendance" app/Http/Controllers/Public/PublicEventController.php app/Http/Controllers/Public/AttendeeAccessController.php
```

Expected: every `public.my*` history route has host guard before verification; the first identity-input search is empty; the second finds no history payload in Inertia controllers.

- [ ] **Step 2: Run focused security, history, and material suites**

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Feature/Events/AttendeePortalHostTest.php tests/Feature/Events/AttendeePortalRouteSecurityTest.php tests/Feature/Events/AttendeeVerificationTest.php tests/Feature/Events/AttendeeHistoryTest.php tests/Feature/Events/EventMaterialTest.php tests/Feature/Events/SpeakerPortalTest.php tests/Feature/Events/MaterialReleasePolicyTest.php
npm test -- resources/js/test/portal
```

Expected: PASS.

- [ ] **Step 3: Run all project gates on the final tree**

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/pint --dirty
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/phpstan analyse --no-progress --memory-limit=2G
npm run lint
npm run build
npm test
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact
git diff --check
```

Expected: all pass with zero static-analysis errors and no whitespace errors.

- [ ] **Step 4: Perform the mandatory self-review**

Ask: “Is there anything I have overlooked? Is the solution optimal? Let me take a step back.” Confirm every dependency is used, all four endpoints are host + proof guarded, no controller trusts email input, empty states work, duplicate matches collapse correctly, route names remain stable, release policy is centralized, sessionless decks fail closed, download limits remain registration-scoped, and every new user-facing surface has PHP and/or component coverage.

- [ ] **Step 5: Commit only review fixes, then request whole-branch review**

If Step 4 or the final reviewer finds an Important/Critical defect, write a failing regression first, make one focused correction pass, rerun Step 3, and commit with a message describing that defect. Minor findings are recorded for the owner rather than silently widening scope.
