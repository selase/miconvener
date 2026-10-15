# Attendee Portal — Plan A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lay the foundation of the attendee portal — frontend tests, a restructured portal page, no contact details on the open page, case-insensitive attendee identity, an organiser escape hatch, and a working proof-of-address flow at `/my` — without yet showing any history.

**Architecture:** The existing capability-URL portal is split into a shell plus one file per panel, pinned by tests written first. Identity is a verified email within one tenant: a hashed, expiring, single-use code proves an address, and a session marker keyed by tenant id remembers it. A middleware reads only the current tenant's marker and hands controllers the proven address; nothing verified is ever put in a page payload.

**Tech Stack:** Laravel 13 (Laravel 10 file layout), PHP 8.5, PostgreSQL, Pest 5, Inertia 3 + React 19, Vite 8, Tailwind 4, Vitest + Testing Library (added in Task 2).

**Spec:** `docs/superpowers/specs/2026-09-22-attendee-portal-design.md` — delivery steps 1–5 (section 11). Plan B (steps 6–10: My events, certificates, abstracts, attendance, speaker-slide policy) is written after this ships.

## Global Constraints

- **Toolchain.** Bare `php`, `node` and `composer` are broken in a non-interactive shell. Always:
  ```bash
  PHP="$HOME/Library/Application Support/Herd/bin/php"
  export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"
  ```
  Run PHP tests as `"$PHP" artisan test --compact <file>`; JS tests as `npm test`.
- **PostgreSQL everywhere, including tests.** Never pattern-drop local databases.
- **Laravel 10 layout.** Middleware aliases in `app/Http/Kernel.php`; rate limiters in `app/Providers/RouteServiceProvider.php`. No `bootstrap/app.php` configuration.
- **PHP style.** `declare(strict_types=1);`, `final` classes, explicit return types, typed class constants, constructor promotion, curly braces always. Models set `protected $connection = 'landlord';` explicitly. PHPDoc blocks over inline comments, except where logic needs it.
- **Gates before every commit:** `"$PHP" vendor/bin/pint --dirty`, then `"$PHP" vendor/bin/phpstan analyse --memory-limit=2G` with **0 errors**. Never add baseline entries or `@phpstan-ignore` comments; fix the cause.
- **The URL `/e/{event}/registrations/{registration}` and route name `public.events.confirmation` never change** — every confirmation and ticket email ever sent links there.
- **The tenant comes only from `TenantContext`** (set from the Host header by `ResolveTenant`), never from input. **The verified address comes only from the session marker**, never from input.
- **Verified data is never put in an Inertia page payload.**
- **Mail uses `markdown:`.** Anything transient a queued mail needs (a code) is a **scalar constructor argument**, never a property read off a model at render time — see Task 1.
- **Tests assert what the person receives.** Never `assertRedirect()` or `Mail::fake()` alone as proof something was delivered.
- **No new design tokens, no restyle.** Reuse existing classes and `@/Components/Console/*`.
- **JS tests live under `resources/js/test/`, never under `resources/js/Pages/`.** `resources/js/app.jsx` resolves pages with `import.meta.glob('./Pages/**/*.jsx', { eager: true })`, so a test beside a page would be bundled into production along with Vitest.
- **Commits:** one per task, message `type(scope): lowercase sentence`, a body explaining why, ending with:
  ```
  Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
  ```
  Do not push or deploy unless the owner says so.

## Deviations from the spec (for owner approval)

Found while reading the code for this plan. Each is either stricter than the spec or keeps a file focused.

1. **Task 1 is not in the spec.** Ticket-transfer emails show an **empty code box** in production: `EventTicketTransferCode` reads `$transfer->plainCode` at render time, but the mail is queued and `SerializesModels` rebuilds the model from the database on the worker, where `plainCode` (not a column) does not exist. Proven locally: the code is not present in the serialised mailable. Production has 0 transfers, so it is latent. The access-code mail in Task 7 would have copied this pattern, so it is fixed first and its test becomes the template.
2. **The phone number is removed from the open page, not masked.** The portal sends `registration.phone` but never renders it anywhere; not sending it at all is stricter than masking and needs no phone masker.
3. **`GET /my/session`** is added: it tells the page who is signed in, and gives the verification middleware a real consumer to test in Plan A.
4. **No public link to `/my` yet.** The `find-ticket` entry and the portal's "verify it's you" button move to Plan B, alongside My events. Linking now would send attendees to a page with nothing behind it; `/my` ships reachable by URL only.
5. **Two page components share parts** — `Portal.jsx` (registration entry) and `MyPortal.jsx` (`/my`) both use `VerifyPrompt.jsx`; Plan B adds a shared panel strip. One component with two modes would tangle registration-only header logic with the `/my` page.
6. **The access-code email is sent under the platform's identity**, with the organiser named in the subject and body. `TenantMailIdentity::envelope()` needs an event to brand as the organiser, and a code from `/my` belongs to no single event.
7. **Addresses in the new `attendee_access_codes` table are stored lowercased.** It is a new table, so this rewrites nothing; existing registration rows stay as typed (spec §6.6).
8. **After two wrong codes, the prompt explains the five-try limit** rather than showing a live count (spec §9), since the server does not report remaining attempts.
9. **The public verify endpoints use Form Requests** (project rule), where sibling public controllers validate inline.

## File map

| File | Task | Responsibility |
|---|---|---|
| `app/Mail/Events/EventTicketTransferCode.php` | 1 | take the code as a constructor argument |
| `app/Models/EventRegistrationTransfer.php` | 1 | drop the transient `$plainCode` |
| `app/Http/Controllers/Public/AttendeePortalController.php` | 1, 4 | pass the code; use `ContactMask` |
| `package.json`, `vitest.config.js`, `.github/workflows/tests.yml` | 2 | JS test tooling and CI step |
| `resources/js/test/setup.js`, `resources/js/test/fixtures/portal.js` | 2 | test globals and portal fixtures |
| `resources/js/Pages/Public/Events/AttendeePortal/Portal.jsx` | 3 | shell (replaces `Confirmation.jsx`) |
| `resources/js/Pages/Public/Events/AttendeePortal/panels/*.jsx` | 3 | one file per existing tab |
| `app/Support/ContactMask.php` | 4 | the one email masker |
| `app/Http/Controllers/Public/PublicEventController.php` | 3, 4 | render the new component; mask, drop phone |
| `database/migrations/landlord/2026_09_22_100000_add_lower_email_index_to_event_registrations_table.php` | 5 | functional index |
| `app/Models/EventRegistration.php` | 5 | `scopeForEmail` |
| `app/Http/Controllers/Public/TicketRecoveryController.php` | 5 | case-insensitive recovery |
| `app/Http/Requests/Tenant/UpdateEventRegistrationRequest.php` | 6 | validate a correction |
| `app/Http/Controllers/Tenant/EventRegistrationController.php` | 6 | `update` |
| `resources/js/Pages/Tenant/Events/EditRegistrationModal.jsx` | 6 | the edit dialog |
| `resources/js/Pages/Tenant/Events/Show.jsx` | 6 | Edit action on each row |
| `database/migrations/landlord/2026_09_22_100100_create_attendee_access_codes_table.php` | 7 | codes table |
| `app/Models/AttendeeAccessCode.php`, `database/factories/AttendeeAccessCodeFactory.php` | 7 | the code |
| `app/Mail/Events/AttendeeAccessCodeMail.php`, `resources/views/emails/events/attendee-access-code.blade.php` | 7 | the code email |
| `app/Services/Events/AttendeeVerification.php` | 8 | send, confirm, remember, forget |
| `app/Http/Requests/Attendee/{Send,Confirm}AccessCodeRequest.php` | 8 | validate verify requests |
| `app/Http/Controllers/Public/AttendeeAccessController.php` | 8, 9 | verify endpoints and `/my` |
| `app/Http/Middleware/EnsureAttendeeVerified.php`, `app/Http/Kernel.php` | 8 | the boundary |
| `routes/subdomain.php` | 6, 8, 9 | routes |
| `resources/js/Pages/Public/Events/AttendeePortal/{VerifyPrompt,MyPortal}.jsx` | 9 | prove an address; the `/my` page |

---

### Task 1: The transfer code survives the queue

**Files:**
- Modify: `app/Mail/Events/EventTicketTransferCode.php`
- Modify: `app/Models/EventRegistrationTransfer.php:27-32` (remove `$plainCode`)
- Modify: `app/Http/Controllers/Public/AttendeePortalController.php:96-99`
- Modify: `tests/Feature/Events/TicketTransferVerificationTest.php`
- Modify: `tests/Feature/Mail/MailableRenderTest.php` (the `EventTicketTransferCode` case)

**Interfaces:**
- Produces: `new EventTicketTransferCode(EventRegistrationTransfer $transfer, string $code)`, with `public string $code`.

- [ ] **Step 1: Write the failing test** — append to `tests/Feature/Events/TicketTransferVerificationTest.php` (it already imports `Hash`, `EventRegistrationTransfer` and `EventTicketTransferCode`):

```php
test('the transfer code survives the queue', function () {
    [$tenant, , $registration] = transferrableTicket('xfer-queue');

    $transfer = EventRegistrationTransfer::create([
        'tenant_id' => $tenant->id,
        'registration_id' => $registration->id,
        'to_full_name' => 'Kwame Asare',
        'to_email' => 'kwame@example.com',
        'code_hash' => Hash::make('482913'),
        'expires_at' => now()->addMinutes(EventRegistrationTransfer::TTL_MINUTES),
    ]);

    // A queued mail is serialised and rebuilt on a worker, and SerializesModels
    // brings models back from the database -- so anything held only in memory
    // is gone by the time the email is written. Mail::fake() never serialises,
    // which is how an empty code box passed every other test in this file.
    $rebuilt = unserialize(serialize(new EventTicketTransferCode($transfer, '482913')));

    expect($rebuilt->render())->toContain('482913');
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `"$PHP" artisan test --compact --filter="survives the queue" tests/Feature/Events/TicketTransferVerificationTest.php`
Expected: FAIL — the rendered mail does not contain `482913` (the second constructor argument is ignored and `plainCode` is null after the round trip).

- [ ] **Step 3: Implement**

In `app/Mail/Events/EventTicketTransferCode.php`, add a class docblock above `final class`, change the constructor, and read the code from it:

```php
/**
 * The code arrives as a constructor argument, never read off the transfer.
 * This mail is queued, and a queued mailable's models are rebuilt from the
 * database on the worker: a value held only in memory does not make the trip.
 */
final class EventTicketTransferCode extends Mailable
```

```php
    public function __construct(
        public EventRegistrationTransfer $transfer,
        public string $code,
    ) {}
```

```php
                'code' => $this->code,
```

In `app/Http/Controllers/Public/AttendeePortalController.php`, replace:

```php
        $transfer->setRelation('registration', $registrationModel);
        $transfer->plainCode = $code;

        Mail::to($registrationModel->email)->queue(new EventTicketTransferCode($transfer));
```

with:

```php
        $transfer->setRelation('registration', $registrationModel);

        Mail::to($registrationModel->email)->queue(new EventTicketTransferCode($transfer, $code));
```

In `app/Models/EventRegistrationTransfer.php`, delete the `$plainCode` property and its docblock (the block beginning "The code in the clear, held only for the life of the request…" through `public ?string $plainCode = null;`). It is the trap this task removes.

Then find every other reference:

Run: `grep -rn "plainCode\|new EventTicketTransferCode(" app/ tests/`
Expected: only the test lines updated below remain.

In `tests/Feature/Events/TicketTransferVerificationTest.php`, every place that reads `$mail->transfer->plainCode` becomes a read of the rendered email's code. For the "a correct code completes the transfer" test, replace:

```php
    // Read the code the way the holder would -- out of the email.
    $code = null;
    Mail::assertQueued(EventTicketTransferCode::class, function ($mail) use (&$code): bool {
        $code = $mail->transfer->plainCode;

        return true;
    });
```

with:

```php
    // Read the code the way the holder would -- out of the rendered email.
    $code = null;
    Mail::assertQueued(EventTicketTransferCode::class, function ($mail) use (&$code): bool {
        expect($mail->render())->toContain($mail->code);
        $code = $mail->code;

        return true;
    });
```

Apply the same replacement to any other test in that file the grep found.

In `tests/Feature/Mail/MailableRenderTest.php`, change the case to:

```php
        'EventTicketTransferCode' => fn () => new EventTicketTransferCode($transfer, '123456'),
```

- [ ] **Step 4: Run the tests**

Run: `"$PHP" artisan test --compact tests/Feature/Events/TicketTransferVerificationTest.php tests/Feature/Mail/MailableRenderTest.php tests/Feature/Events/AttendeePortalTest.php`
Expected: all PASS.

- [ ] **Step 5: Gates and commit**

```bash
"$PHP" vendor/bin/pint --dirty && "$PHP" vendor/bin/phpstan analyse --memory-limit=2G
git add app/Mail/Events/EventTicketTransferCode.php app/Models/EventRegistrationTransfer.php app/Http/Controllers/Public/AttendeePortalController.php tests/Feature/Events/TicketTransferVerificationTest.php tests/Feature/Mail/MailableRenderTest.php
git commit -m "fix(transfers): carry the transfer code through the queue, so the email shows it" -m "The code was set on the transfer model in memory and read back at render time, but the mail is queued and SerializesModels rebuilds models from the database on the worker. The code is not a column, so every transfer email showed an empty box. Production has had no transfers, so nobody has hit it. Mail::fake() never serialises, which is how the tests missed it; the new test does the round trip.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Frontend tests, and the portal pinned before it moves

**Files:**
- Modify: `package.json`
- Create: `vitest.config.js`
- Create: `resources/js/test/setup.js`
- Create: `resources/js/test/fixtures/portal.js`
- Create: `resources/js/test/portal/Portal.test.jsx`
- Create: `tests/Feature/Events/AttendeePortalPageTest.php`
- Modify: `.github/workflows/tests.yml`

**Interfaces:**
- Produces: `npm test`; `portalProps(overrides)` fixture; a global `route` stub in tests; `portalFor(string $slug): array{0: Event, 1: EventRegistration, 2: string}` test helper.

- [ ] **Step 1: Install the tools**

```bash
export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"
npm install --save-dev vitest jsdom @testing-library/react @testing-library/dom
```

CI runs Node 20. Confirm the installed Vitest allows it:

Run: `node -e "console.log(require('./node_modules/vitest/package.json').engines)"`
Expected: a range that includes Node 20. If it requires more than 20, pin the newest major that does, e.g. `npm install --save-dev vitest@3`, and re-check.

Add to `package.json` `"scripts"`:

```json
        "test": "vitest run"
```

- [ ] **Step 2: Configure Vitest**

Create `vitest.config.js`:

```js
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'node:path';

/**
 * Separate from vite.config.js on purpose: the Laravel plugin there expects a
 * backend and a manifest, neither of which a component test has.
 *
 * Tests live under resources/js/test, never beside a page. app.jsx eagerly
 * globs every .jsx under Pages/, so a test there would be bundled into
 * production along with Vitest itself.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: { '@': path.resolve(import.meta.dirname, 'resources/js') },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/test/**/*.test.{js,jsx}'],
        setupFiles: ['resources/js/test/setup.js'],
    },
});
```

Create `resources/js/test/setup.js`:

```js
import { afterEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';

/**
 * Ziggy's route() is a browser global. Components only need a stable string,
 * which also lets a test assert which route was asked for.
 */
globalThis.route = vi.fn((name, params = {}) => `/${name}?${new URLSearchParams(params)}`);

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});
```

Create `resources/js/test/fixtures/portal.js`:

```js
/**
 * Props shaped like PublicEventController::confirmation sends them. Override
 * only what a test is about.
 */
export function portalProps(overrides = {}) {
    return {
        event: {
            slug: 'science-congress',
            name: 'National Science Congress',
            sessions: [
                {
                    id: 'sess-1',
                    title: 'Opening Plenary',
                    starts_at: '2026-10-01T09:00:00+00:00',
                    ends_at: '2026-10-01T10:00:00+00:00',
                    location: 'Main Hall',
                    capacity: null,
                    signup_count: 0,
                },
            ],
            ...overrides.event,
        },
        registration: {
            id: 'reg-1',
            full_name: 'Ama Serwaa',
            email: 'am•@stem.org',
            status: 'confirmed',
            ticket_code: 'EVT-ABCD-123',
            ticket_type_name: 'Delegate',
            qr_image: 'data:image/svg+xml;base64,PHN2Zy8+',
            agenda_session_ids: [],
            waitlist_position: null,
            approval_note: null,
            seat_label: null,
            room_name: null,
            checked_in: false,
            email_verified: true,
            awaiting_checkout: false,
            checkout_url: null,
            ...overrides.registration,
        },
        materials: overrides.materials ?? [],
        canRequestHelp: overrides.canRequestHelp ?? false,
    };
}

export const keynote = { id: 'm1', title: 'Keynote', remaining_attempts: 3, download_url: '/dl/m1' };
```

- [ ] **Step 3: Write the characterisation tests against the page as it is today**

Create `resources/js/test/portal/Portal.test.jsx`:

```jsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import Portal from '@/Pages/Public/Events/Confirmation';
import { portalProps, keynote } from '../fixtures/portal';

vi.mock('@/Layouts/PublicLayout', () => ({ default: ({ children }) => children }));

const tabLabels = () =>
    within(screen.getByRole('navigation'))
        .queryAllByRole('button')
        .map((button) => button.textContent);

describe('attendee portal', () => {
    it('offers only the ticket when nothing else can be acted on', () => {
        render(<Portal {...portalProps({ event: { sessions: [] } })} />);
        expect(tabLabels()).toEqual(['My ticket']);
    });

    it('offers every tab once each has something in it, in order', () => {
        render(<Portal {...portalProps({ canRequestHelp: true, materials: [keynote] })} />);
        expect(tabLabels()).toEqual(['My ticket', 'My day', 'Get help', 'Downloads']);
    });

    it('shows no tabs until the address is confirmed', () => {
        render(<Portal {...portalProps({ registration: { email_verified: false } })} />);
        expect(screen.getByText(/Almost there, Ama Serwaa/)).toBeTruthy();
        expect(tabLabels()).toEqual([]);
    });

    it('shows the status notice instead of the portal for a cancelled registration', () => {
        render(<Portal {...portalProps({ registration: { status: 'cancelled' } })} />);
        expect(screen.getByText('Registration cancelled')).toBeTruthy();
        expect(screen.queryByRole('navigation')).toBeNull();
    });

    it('lists released materials on the Downloads tab', () => {
        render(<Portal {...portalProps({ materials: [keynote] })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Downloads' }));
        expect(screen.getByText('Keynote')).toBeTruthy();
    });
});
```

Create `tests/Feature/Events/AttendeePortalPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Support\Facades\Artisan;

/**
 * Pins what the attendee portal receives from the server, so the page can be
 * restructured without anyone having to trust that nothing moved.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function portalFor(string $slug): array
{
    [$tenant] = eventHost($slug);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'ama@stem.org',
        'phone' => '+233201234234',
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    return [$event, $registration, eventSubdomainHost($slug)];
}

test('the portal page receives the registration, the event and what can be acted on', function () {
    [$event, $registration, $host] = portalFor('portal-props');

    $this->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/Confirmation')
            ->where('registration.id', $registration->id)
            ->where('registration.status', EventRegistration::STATUS_CONFIRMED)
            ->has('registration.ticket_code')
            ->has('event.slug')
            ->has('materials')
            ->has('canRequestHelp'));
});
```

- [ ] **Step 4: Run both — they must PASS against today's code**

Run: `npm test`
Expected: 5 PASS.

Run: `"$PHP" artisan test --compact tests/Feature/Events/AttendeePortalPageTest.php`
Expected: PASS.

These tests describe the page as it is. If one fails, the **fixture or the test is wrong, not the page** — fix the test. Do not change `Confirmation.jsx` in this task.

- [ ] **Step 5: Add the CI step**

In `.github/workflows/tests.yml`, after the `Run JS Linting` step, add:

```yaml
    - name: Run JS Tests
      run: npm test
```

Run: `npm run build && npm run lint`
Expected: both succeed; the build output does not grow (no test files are bundled).

- [ ] **Step 6: Gates and commit**

```bash
"$PHP" vendor/bin/pint --dirty && "$PHP" vendor/bin/phpstan analyse --memory-limit=2G
git add package.json package-lock.json vitest.config.js resources/js/test .github/workflows/tests.yml tests/Feature/Events/AttendeePortalPageTest.php
git commit -m "test(portal): add component tests and pin the portal before restructuring it" -m "The project had no JavaScript tests; CI only built and linted. Vitest and Testing Library are dev-only and run in CI. These first tests describe the attendee portal exactly as it is, so the next change can move it without anyone having to trust that nothing broke.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Split the portal into a shell and one file per panel

**Files:**
- Create: `resources/js/Pages/Public/Events/AttendeePortal/Portal.jsx`
- Create: `resources/js/Pages/Public/Events/AttendeePortal/panels/MyTicketPanel.jsx`
- Create: `resources/js/Pages/Public/Events/AttendeePortal/panels/MyDayPanel.jsx`
- Create: `resources/js/Pages/Public/Events/AttendeePortal/panels/GetHelpPanel.jsx`
- Create: `resources/js/Pages/Public/Events/AttendeePortal/panels/DownloadsPanel.jsx`
- Delete: `resources/js/Pages/Public/Events/Confirmation.jsx`
- Modify: `app/Http/Controllers/Public/PublicEventController.php:358`
- Modify: `resources/js/test/portal/Portal.test.jsx`, `tests/Feature/Events/AttendeePortalPageTest.php`

**Interfaces:**
- Produces: default exports `Portal`, `MyTicketPanel({ event, registration })`, `MyDayPanel({ event, registration, agendaIds, setAgendaIds })`, `GetHelpPanel({ event, registration })`, `DownloadsPanel({ materials })`; Inertia component `Public/Events/AttendeePortal/Portal`.

Line numbers below are from `Confirmation.jsx` as of 2026-09-22 (599 lines). Confirm them before moving anything:

Run: `grep -nE "^(export default )?(function|const) " resources/js/Pages/Public/Events/Confirmation.jsx`
Expected: `15 formatSessionTime`, `23 TicketTab`, `222 AgendaTab`, `293 HELP_TYPES`, `302 HelpTab`, `408 DownloadsTab`, `452 STATUS_NOTICE`, `496 Confirmation`.

- [ ] **Step 1: Point the tests at the new locations**

In `resources/js/test/portal/Portal.test.jsx`, change the import to:

```jsx
import Portal from '@/Pages/Public/Events/AttendeePortal/Portal';
```

In `tests/Feature/Events/AttendeePortalPageTest.php`, change the component assertion to:

```php
            ->component('Public/Events/AttendeePortal/Portal')
```

- [ ] **Step 2: Run them and watch them fail**

Run: `npm test`
Expected: FAIL — cannot resolve `@/Pages/Public/Events/AttendeePortal/Portal`.

Run: `"$PHP" artisan test --compact tests/Feature/Events/AttendeePortalPageTest.php`
Expected: FAIL — component `Public/Events/Confirmation` does not match.

- [ ] **Step 3: Move the code, unchanged**

Every function body moves **verbatim**. The only edits are the import lines shown and the renamed declarations.

`panels/MyTicketPanel.jsx` — `TicketTab`, lines 23–220:

```jsx
import { useState } from 'react';
import csrfFetch from '@/lib/csrfFetch';

export default function MyTicketPanel({ event, registration }) {
    // ...body of TicketTab, lines 24-219, unchanged...
}
```

`panels/MyDayPanel.jsx` — `formatSessionTime` (lines 15–21, kept private here, its only user) and `AgendaTab` (lines 222–291):

```jsx
import { Check, Download, Plus } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

function formatSessionTime(startsAt, endsAt) {
    // ...lines 16-20, unchanged...
}

export default function MyDayPanel({ event, registration, agendaIds, setAgendaIds }) {
    // ...body of AgendaTab, lines 223-290, unchanged...
}
```

`panels/GetHelpPanel.jsx` — `HELP_TYPES` (lines 293–300) and `HelpTab` (lines 302–406):

```jsx
import { useState } from 'react';
import { Check } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

const HELP_TYPES = [
    // ...lines 294-299, unchanged...
];

export default function GetHelpPanel({ event, registration }) {
    // ...body of HelpTab, lines 303-405, unchanged...
}
```

`panels/DownloadsPanel.jsx` — `DownloadsTab`, lines 408–450:

```jsx
import { Download, FileText } from 'lucide-react';

export default function DownloadsPanel({ materials }) {
    // ...body of DownloadsTab, lines 409-449, unchanged...
}
```

`AttendeePortal/Portal.jsx` — `STATUS_NOTICE` (lines 452–494) and the page (lines 496–599):

```jsx
import { useState } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import { CheckCircle2, Clock, ListOrdered, XCircle } from 'lucide-react';
import MyTicketPanel from './panels/MyTicketPanel';
import MyDayPanel from './panels/MyDayPanel';
import GetHelpPanel from './panels/GetHelpPanel';
import DownloadsPanel from './panels/DownloadsPanel';

const STATUS_NOTICE = {
    // ...lines 453-493, unchanged...
};

/**
 * The attendee's page for one registration, reached by the link in their
 * confirmation email. Each tab is its own file under panels/.
 */
export default function Portal({ event, registration, materials = [], canRequestHelp = false }) {
    // ...body of Confirmation, lines 502-598, unchanged except these four JSX tags...
}
```

In the moved page body, rename exactly these four elements and nothing else:

| Before | After |
|---|---|
| `<TicketTab event={event} registration={registration} />` | `<MyTicketPanel event={event} registration={registration} />` |
| `<AgendaTab` | `<MyDayPanel` |
| `<HelpTab event={event} registration={registration} />` | `<GetHelpPanel event={event} registration={registration} />` |
| `<DownloadsTab materials={materials} />` | `<DownloadsPanel materials={materials} />` |

Every lucide icon the old file imported is now imported exactly once where it is used: `CheckCircle2`, `Clock`, `ListOrdered`, `XCircle` in `Portal.jsx`; `Check`, `Download`, `Plus` in `MyDayPanel.jsx`; `Check` in `GetHelpPanel.jsx`; `Download`, `FileText` in `DownloadsPanel.jsx`.

In `app/Http/Controllers/Public/PublicEventController.php`, change only the component name:

```php
        return Inertia::render('Public/Events/AttendeePortal/Portal', [
```

Delete `resources/js/Pages/Public/Events/Confirmation.jsx`.

Run: `grep -rn "Events/Confirmation" resources/ app/ tests/`
Expected: no output.

- [ ] **Step 4: Run everything**

Run: `npm test && npm run lint && npm run build`
Expected: 5 PASS; lint clean; build succeeds.

Run: `"$PHP" artisan test --compact tests/Feature/Events/AttendeePortalPageTest.php tests/Feature/Events/AttendeePortalTest.php tests/Feature/Events/TicketTransferVerificationTest.php`
Expected: all PASS.

Open `https://miconvener.test/e/<a real slug>/registrations/<a real id>` in a browser and click each tab. It must look and behave exactly as before.

- [ ] **Step 5: Gates and commit**

```bash
"$PHP" vendor/bin/pint --dirty && "$PHP" vendor/bin/phpstan analyse --memory-limit=2G
git add -A resources/js/Pages/Public/Events app/Http/Controllers/Public/PublicEventController.php resources/js/test tests/Feature/Events/AttendeePortalPageTest.php
git commit -m "refactor(portal): split the attendee page into a shell and one file per panel" -m "Confirmation.jsx had grown to 599 lines with four tabs inline, and the portal is about to gain more. Each tab now has its own file under AttendeePortal/panels, moved verbatim. The URL and route name are unchanged, so every link already emailed still works. The tests written beforehand pass unchanged.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: The open page stops handing out contact details

**Files:**
- Create: `app/Support/ContactMask.php`
- Create: `tests/Unit/Support/ContactMaskTest.php`
- Modify: `app/Http/Controllers/Public/AttendeePortalController.php` (the `message` in `transfer()`, and the private `maskEmail()`; Task 1 shifted their line numbers, so find them by content)
- Modify: `app/Http/Controllers/Public/PublicEventController.php` (the `email` and `phone` props in `confirmation()`)
- Modify: `tests/Feature/Events/AttendeePortalPageTest.php`

**Interfaces:**
- Produces: `App\Support\ContactMask::email(string $email): string` — keeps the first two characters of the local part and the domain: `ama@stem.org` → `am•@stem.org`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Support/ContactMaskTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\ContactMask;

test('an address keeps two letters and its domain', function () {
    expect(ContactMask::email('ama.serwaa@stem.org'))->toBe('am••••••••@stem.org');
});

test('a very short address still hides something', function () {
    expect(ContactMask::email('a@stem.org'))->toBe('a•@stem.org');
});
```

Append to `tests/Feature/Events/AttendeePortalPageTest.php`:

```php
test('the portal masks the address and does not send the phone number', function () {
    [$event, $registration, $host] = portalFor('portal-masked');

    // Anyone holding the link sees this page. A forwarded confirmation email
    // should hand over a ticket, not the holder's contact details.
    $this->get("http://{$host}/e/{$event->slug}/registrations/{$registration->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('registration.email', 'am•@stem.org')
            ->missing('registration.phone'));
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `"$PHP" artisan test --compact tests/Unit/Support/ContactMaskTest.php tests/Feature/Events/AttendeePortalPageTest.php`
Expected: FAIL — class `App\Support\ContactMask` not found; the portal sends `ama@stem.org` and a `phone`.

- [ ] **Step 3: Implement**

Create `app/Support/ContactMask.php` — the logic is moved from `AttendeePortalController::maskEmail`, not rewritten:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Enough of an address for its owner to recognise it, not enough for anyone
 * else to use. For anything shown on a page reachable by holding a link.
 */
final class ContactMask
{
    public static function email(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = mb_substr($user, 0, 2);

        return $visible.str_repeat('•', max(1, mb_strlen($user) - 2)).'@'.$domain;
    }
}
```

In `app/Http/Controllers/Public/AttendeePortalController.php`, add `use App\Support\ContactMask;` and replace the `'message' => "We emailed a confirmation code to {$this->maskEmail(...)}..."` line in `transfer()`. A static call is not interpolated inside a double-quoted string, so it is concatenated:

```php
            'message' => 'We emailed a confirmation code to '.ContactMask::email($registrationModel->email).'. Enter it to complete the transfer.',
```

Then delete the private `maskEmail()` method.

Run: `grep -n "maskEmail" app/Http/Controllers/Public/AttendeePortalController.php`
Expected: no output.

In `app/Http/Controllers/Public/PublicEventController.php`, add `use App\Support\ContactMask;` and, in `confirmation()`, replace:

```php
                'email' => $registrationModel->email,
                'phone' => $registrationModel->phone,
```

with:

```php
                // Anyone holding the link sees this. The page never shows the
                // phone number, so it is not sent at all.
                'email' => ContactMask::email($registrationModel->email),
```

`Portal.jsx` needs no change: its unverified notice already reads `We've emailed ${registration.email}`, which now shows the masked form.

- [ ] **Step 4: Run the tests**

Run: `"$PHP" artisan test --compact tests/Unit/Support/ContactMaskTest.php tests/Feature/Events/AttendeePortalPageTest.php tests/Feature/Events/TicketTransferVerificationTest.php tests/Feature/Events/AttendeePortalTest.php`
Expected: all PASS.

Run: `npm test`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

```bash
"$PHP" vendor/bin/pint --dirty && "$PHP" vendor/bin/phpstan analyse --memory-limit=2G
git add app/Support/ContactMask.php tests/Unit/Support/ContactMaskTest.php app/Http/Controllers/Public/AttendeePortalController.php app/Http/Controllers/Public/PublicEventController.php tests/Feature/Events/AttendeePortalPageTest.php
git commit -m "fix(portal): stop handing an attendee's contact details to anyone with the link" -m "The portal is reached by holding a link, so a forwarded confirmation email passed on the holder's full email and phone number. The email is now masked, and the phone number is no longer sent: the page never displayed it. The masker moves to App\Support so there is one, not a third copy.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: An attendee is the same person whatever capitals they type

**Files:**
- Create: `database/migrations/landlord/2026_09_22_100000_add_lower_email_index_to_event_registrations_table.php`
- Modify: `app/Models/EventRegistration.php` (add `scopeForEmail`)
- Modify: `app/Http/Controllers/Public/TicketRecoveryController.php` (the `where('email', …)`)
- Modify: `tests/Feature/Events/TicketRecoveryTest.php`

**Interfaces:**
- Produces: `EventRegistration::query()->forEmail(string $email)`; index `event_registrations_tenant_id_lower_email_index` on `(tenant_id, lower(email))`.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Events/TicketRecoveryTest.php`, add `use Illuminate\Support\Facades\DB;` and append:

```php
test('recovery does not care how the address is capitalised', function () {
    Mail::fake();
    [$tenant, $event, $host] = recoverableEvent('rec-case');

    // Addresses are stored exactly as typed. Someone who registered as
    // Ama@Example.com and asks as ama@example.com is the same person.
    EventRegistration::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'email' => 'Ama@Example.com', 'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    $this->postJson("http://{$host}/e/{$event->slug}/find-ticket", [
        'email' => 'ama@example.com',
    ], ['HTTP_HOST' => $host])->assertOk();

    Mail::assertQueued(EventTicketLink::class, fn ($mail): bool => $mail->hasTo('Ama@Example.com'));
});

test('addresses are looked up through an index on their lowercase form', function () {
    $index = DB::connection('landlord')->selectOne(
        "select indexdef from pg_indexes where indexname = 'event_registrations_tenant_id_lower_email_index'"
    );

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('lower((email)');
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `"$PHP" artisan test --compact tests/Feature/Events/TicketRecoveryTest.php`
Expected: FAIL — no mail is queued for the mixed-case address; the index does not exist.

- [ ] **Step 3: Implement**

Create the migration:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses are stored exactly as typed, so Ama@Example.com and
 * ama@example.com are the same attendee but not the same string. Lookups
 * compare lower(email); this index keeps that from scanning the table.
 * Stored addresses are deliberately left as typed rather than rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->rawIndex('tenant_id, lower(email)', 'event_registrations_tenant_id_lower_email_index');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropIndex('event_registrations_tenant_id_lower_email_index');
        });
    }
};
```

In `app/Models/EventRegistration.php`, add beside the other query methods:

```php
    /**
     * Addresses are stored as typed; identity is not case-sensitive. Compared
     * as lower(email) so the (tenant_id, lower(email)) index serves it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->whereRaw('lower('.$query->qualifyColumn('email').') = ?', [mb_strtolower(mb_trim($email))]);
    }
```

In `app/Http/Controllers/Public/TicketRecoveryController.php`, replace:

```php
            ->where('email', $validated['email'])
```

with:

```php
            ->forEmail($validated['email'])
```

- [ ] **Step 4: Run the tests**

Run: `"$PHP" artisan test --compact tests/Feature/Events/TicketRecoveryTest.php`
Expected: all PASS, including the existing "same answer" and rate-limit tests.

- [ ] **Step 5: Gates and commit**

```bash
"$PHP" vendor/bin/pint --dirty && "$PHP" vendor/bin/phpstan analyse --memory-limit=2G
git add database/migrations/landlord/2026_09_22_100000_add_lower_email_index_to_event_registrations_table.php app/Models/EventRegistration.php app/Http/Controllers/Public/TicketRecoveryController.php tests/Feature/Events/TicketRecoveryTest.php
git commit -m "fix(tickets): recognise an attendee whatever capitals they type their address in" -m "Addresses are stored as typed and find-ticket compared them with a case-sensitive equals, so someone who registered as Ama@Example.com could not recover their ticket as ama@example.com. Matching is now on lower(email), served by a new functional index. No stored address is rewritten.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: Organisers can correct a registration

**Files:**
- Create: `app/Http/Requests/Tenant/UpdateEventRegistrationRequest.php`
- Modify: `app/Http/Controllers/Tenant/EventRegistrationController.php` (add `update`)
- Modify: `routes/subdomain.php` (after the `cancel` route, line 153)
- Create: `resources/js/Pages/Tenant/Events/EditRegistrationModal.jsx`
- Modify: `resources/js/Pages/Tenant/Events/Show.jsx` (`GuestsTab`)
- Create: `tests/Feature/Events/EventRegistrationEditTest.php`
- Create: `resources/js/test/console/EditRegistrationModal.test.jsx`

**Interfaces:**
- Consumes: `EventRegistrationController`'s existing private `getTenant()` and `findEvent(string $tenantId, string $event)`.
- Produces: `PATCH events/{event}/registrations/{registration}`, route `tenant.events.registrations.update`, body `{full_name, email, phone|null}`, response `{message}`; `EditRegistrationModal({ event, registration, onClose, onSaved })`.

- [ ] **Step 1: Write the failing feature tests**

Create `tests/Feature/Events/EventRegistrationEditTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

/**
 * An attendee's history is keyed to their email. A mistyped or shared address
 * locks them out of it, and an organiser correcting the address is the only
 * way back -- so the correction has to exist, be restricted, and be recorded.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function editableRegistration(string $slug): array
{
    [$tenant, $user] = eventHost($slug);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Ama Serwa',
        'email' => 'ama@stme.org',
        'phone' => null,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    return [$tenant, $user, $event, $registration, eventSubdomainHost($slug)];
}

test('an organiser can correct an attendee\'s name, email and phone', function () {
    [, $user, $event, $registration, $host] = editableRegistration('edit-ok');

    $this->actingAs($user)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$registration->id}",
        ['full_name' => 'Ama Serwaa', 'email' => 'ama@stem.org', 'phone' => '+233201234234'],
        ['HTTP_HOST' => $host]
    )->assertOk();

    $registration->refresh();
    expect($registration->full_name)->toBe('Ama Serwaa')
        ->and($registration->email)->toBe('ama@stem.org')
        ->and($registration->phone)->toBe('+233201234234');
});

test('the correction is recorded with the address it replaced', function () {
    config(['activitylog.enabled' => true]);
    [, $user, $event, $registration, $host] = editableRegistration('edit-log');

    $this->actingAs($user)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$registration->id}",
        ['full_name' => 'Ama Serwa', 'email' => 'ama@stem.org', 'phone' => null],
        ['HTTP_HOST' => $host]
    )->assertOk();

    $entry = Activity::query()
        ->where('subject_id', $registration->id)
        ->where('event', 'updated')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['old']['email'])->toBe('ama@stme.org')
        ->and($entry->properties['attributes']['email'])->toBe('ama@stem.org');
});

test('an address that is not an address is refused, and nothing changes', function () {
    [, $user, $event, $registration, $host] = editableRegistration('edit-invalid');

    $this->actingAs($user)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$registration->id}",
        ['full_name' => 'Ama Serwaa', 'email' => 'not-an-address'],
        ['HTTP_HOST' => $host]
    )->assertUnprocessable()->assertJsonValidationErrors('email');

    expect($registration->fresh()->email)->toBe('ama@stme.org');
});

test('someone without permission to update events cannot correct a registration', function () {
    [$tenant, , $event, $registration, $host] = editableRegistration('edit-denied');
    $outsider = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($outsider->id);

    $this->actingAs($outsider)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$registration->id}",
        ['full_name' => 'Someone Else', 'email' => 'someone@else.org'],
        ['HTTP_HOST' => $host]
    )->assertForbidden();

    expect($registration->fresh()->email)->toBe('ama@stme.org');
});

test('an organiser cannot reach another organiser\'s registration', function () {
    [, $user, $event, , $host] = editableRegistration('edit-mine');
    [, , , $theirs] = editableRegistration('edit-theirs');

    $this->actingAs($user)->patchJson(
        "http://{$host}/events/{$event->id}/registrations/{$theirs->id}",
        ['full_name' => 'Taken Over', 'email' => 'taken@over.org'],
        ['HTTP_HOST' => $host]
    )->assertNotFound();

    expect($theirs->fresh()->email)->toBe('ama@stme.org');
});
```

If the permission test returns a redirect rather than 403, the console's `onboarding` middleware is intercepting a user with no role — investigate that; do not weaken the assertion.

- [ ] **Step 2: Run them and watch them fail**

Run: `"$PHP" artisan test --compact tests/Feature/Events/EventRegistrationEditTest.php`
Expected: FAIL — `405 Method Not Allowed` (no PATCH route).

- [ ] **Step 3: Implement the backend**

Create `app/Http/Requests/Tenant/UpdateEventRegistrationRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An organiser correcting who a registration belongs to. Permission is checked
 * in the controller, alongside approve, reject and cancel.
 */
final class UpdateEventRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Enter the attendee\'s name.',
            'email.required' => 'Enter the attendee\'s email address.',
            'email.email' => 'That doesn\'t look like an email address.',
        ];
    }
}
```

In `app/Http/Controllers/Tenant/EventRegistrationController.php`, add `use App\Http\Requests\Tenant\UpdateEventRegistrationRequest;` and, after `cancel()`, add:

```php
    /**
     * Corrects who a registration belongs to: a mistyped address, a misspelt
     * name. An attendee's history is keyed to their email, so this is also the
     * way back for someone registered under the wrong one. The model's
     * activity log records what changed.
     */
    public function update(UpdateEventRegistrationRequest $request, string $subdomain, string $event, string $registration): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $registrationModel = $eventModel->registrations()->where('id', $registration)->firstOrFail();
        $registrationModel->update($request->validated());

        return response()->json(['message' => 'Registration updated.']);
    }
```

In `routes/subdomain.php`, directly after the `tenant.events.registrations.cancel` route, add:

```php
    Route::patch('events/{event}/registrations/{registration}', [EventRegistrationController::class, 'update'])->name('tenant.events.registrations.update');
```

- [ ] **Step 4: Run the feature tests**

Run: `"$PHP" artisan test --compact tests/Feature/Events/EventRegistrationEditTest.php`
Expected: all PASS.

- [ ] **Step 5: Write the failing component test**

Create `resources/js/test/console/EditRegistrationModal.test.jsx`. The console `Input` renders its label without `htmlFor`, so fields are found by their value:

```jsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import EditRegistrationModal from '@/Pages/Tenant/Events/EditRegistrationModal';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));

const registration = { id: 'reg-1', full_name: 'Ama Serwa', email: 'ama@stme.org', phone: null };
const event = { id: 'evt-1' };

describe('editing a registration', () => {
    beforeEach(() => csrfFetch.mockReset());

    it('sends the corrected details to the update route', async () => {
        csrfFetch.mockResolvedValue({ ok: true, json: async () => ({}) });
        const onSaved = vi.fn();
        render(
            <EditRegistrationModal event={event} registration={registration} onClose={() => {}} onSaved={onSaved} />
        );

        fireEvent.change(screen.getByDisplayValue('ama@stme.org'), { target: { value: 'ama@stem.org' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(onSaved).toHaveBeenCalled());
        const [url, options] = csrfFetch.mock.calls[0];
        expect(url).toContain('tenant.events.registrations.update');
        expect(options.method).toBe('PATCH');
        expect(JSON.parse(options.body)).toEqual({
            full_name: 'Ama Serwa',
            email: 'ama@stem.org',
            phone: null,
        });
    });

    it('shows the server\'s reason when the address is refused, and stays open', async () => {
        csrfFetch.mockResolvedValue({
            ok: false,
            json: async () => ({ errors: { email: ["That doesn't look like an email address."] } }),
        });
        const onSaved = vi.fn();
        render(
            <EditRegistrationModal event={event} registration={registration} onClose={() => {}} onSaved={onSaved} />
        );

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByText("That doesn't look like an email address.")).toBeTruthy();
        expect(onSaved).not.toHaveBeenCalled();
    });
});
```

Run: `npm test`
Expected: FAIL — cannot resolve `EditRegistrationModal`.

- [ ] **Step 6: Implement the dialog and the row action**

Create `resources/js/Pages/Tenant/Events/EditRegistrationModal.jsx`:

```jsx
import { useState } from 'react';
import Modal from '@/Components/Console/Modal';
import Input from '@/Components/Console/Input';
import Button from '@/Components/Console/Button';
import csrfFetch from '@/lib/csrfFetch';

/**
 * Corrects a registration's name, email or phone. An attendee's history is
 * keyed to their email, so fixing a mistyped address is also how someone gets
 * back to it.
 */
export default function EditRegistrationModal({ event, registration, onClose, onSaved }) {
    const [form, setForm] = useState({
        full_name: registration.full_name ?? '',
        email: registration.email ?? '',
        phone: registration.phone ?? '',
    });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const set = (field) => (e) => setForm({ ...form, [field]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        const response = await csrfFetch(
            route('tenant.events.registrations.update', {
                event: event.id,
                registration: registration.id,
            }),
            { method: 'PATCH', body: JSON.stringify({ ...form, phone: form.phone || null }) }
        );
        setSaving(false);

        if (response.ok) {
            onSaved();
            return;
        }

        const json = await response.json().catch(() => ({}));
        setErrors(
            Object.fromEntries(
                Object.entries(json.errors ?? {}).map(([field, messages]) => [field, messages[0]])
            )
        );
    };

    return (
        <Modal open onClose={onClose} title="Edit registration">
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Name"
                    value={form.full_name}
                    onChange={set('full_name')}
                    error={errors.full_name}
                    required
                />
                <Input
                    label="Email"
                    type="email"
                    value={form.email}
                    onChange={set('email')}
                    error={errors.email}
                    hint="Their tickets, certificates and history follow this address."
                    required
                />
                <Input label="Phone" value={form.phone} onChange={set('phone')} error={errors.phone} />
                <div className="flex justify-end gap-2">
                    <Button type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" disabled={saving}>
                        {saving ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
```

In `resources/js/Pages/Tenant/Events/Show.jsx`, `GuestsTab`:

1. Add the import at the top with the other local imports:

```jsx
import EditRegistrationModal from './EditRegistrationModal';
```

2. Inside `GuestsTab`, after `const reload = …`, add:

```jsx
    const [editing, setEditing] = useState(null);
```

3. Replace the whole actions cell — the `<Td align="right">` containing the Approve/Reject and Cancel blocks — so every row gets an Edit action and the existing blocks sit beside it unchanged:

```jsx
                                <Td align="right">
                                    <div className="flex items-center justify-end gap-3">
                                        <button
                                            onClick={() => setEditing(registration)}
                                            title="Edit"
                                            className="text-ink-secondary hover:text-ink"
                                        >
                                            <Pencil className="h-4 w-4" strokeWidth={1.75} />
                                        </button>
                                        {/* the existing pending_approval block, unchanged */}
                                        {/* the existing confirmed/checked_in/waitlisted Cancel block, unchanged */}
                                    </div>
                                </Td>
```

Paste the two existing blocks verbatim where the comments are, then delete the comments. `Pencil` is already imported in `Show.jsx`.

4. Directly after the table (inside `GuestsTab`'s returned `<div>`), add:

```jsx
            {editing && (
                <EditRegistrationModal
                    event={event}
                    registration={editing}
                    onClose={() => setEditing(null)}
                    onSaved={() => {
                        setEditing(null);
                        reload();
                    }}
                />
            )}
```

- [ ] **Step 7: Run everything for this task**

Run: `npm test && npm run lint && npm run build`
Expected: all PASS.

Run: `"$PHP" artisan test --compact tests/Feature/Events/EventRegistrationEditTest.php tests/Feature/Events/EventRegistrationTest.php`
Expected: all PASS.

In a browser at `https://miconvener.test`, open an event's Guests section, click the pencil on a row, change the email and save. The row shows the new address.

- [ ] **Step 8: Gates and commit**

```bash
"$PHP" vendor/bin/pint --dirty && "$PHP" vendor/bin/phpstan analyse --memory-limit=2G
git add app/Http/Requests/Tenant/UpdateEventRegistrationRequest.php app/Http/Controllers/Tenant/EventRegistrationController.php routes/subdomain.php resources/js/Pages/Tenant/Events/EditRegistrationModal.jsx resources/js/Pages/Tenant/Events/Show.jsx tests/Feature/Events/EventRegistrationEditTest.php resources/js/test/console/EditRegistrationModal.test.jsx
git commit -m "feat(console): let organisers correct a registration's name, email and phone" -m "Registrations could be approved, rejected or cancelled but never corrected. The attendee portal keys an attendee's history to their email, so a mistyped or shared address would lock someone out with no way back. Organisers with permission to update events can now fix it from the guest list; the change is recorded in the activity log with the old value.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: Access codes, and the email that carries them

**Files:**
- Create: `database/migrations/landlord/2026_09_22_100100_create_attendee_access_codes_table.php`
- Create: `app/Models/AttendeeAccessCode.php`
- Create: `database/factories/AttendeeAccessCodeFactory.php`
- Create: `app/Mail/Events/AttendeeAccessCodeMail.php`
- Create: `resources/views/emails/events/attendee-access-code.blade.php`
- Modify: `tests/Feature/Mail/MailableRenderTest.php`
- Create: `tests/Feature/Events/AttendeeAccessCodeTest.php`

**Interfaces:**
- Produces: `AttendeeAccessCode` with `TTL_MINUTES = 15`, `MAX_ATTEMPTS = 5`, `static generateCode(): string`, `isUsable(): bool`, `matches(string $code): bool`; columns `tenant_id, email, code_hash, attempts, expires_at, consumed_at`. `new AttendeeAccessCodeMail(Tenant $tenant, string $code)` with `public string $code`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Events/AttendeeAccessCodeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\AttendeeAccessCodeMail;
use App\Models\AttendeeAccessCode;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    refreshTenantDatabases();
});

test('the code survives the queue', function () {
    $tenant = Tenant::factory()->create(['slug' => 'code-queue', 'name' => 'Acme Events']);

    // The same round trip a worker makes. See the transfer-code test for why
    // the code is a constructor argument and not a property of a model.
    $rebuilt = unserialize(serialize(new AttendeeAccessCodeMail($tenant, '482913')));

    expect($rebuilt->render())->toContain('482913')->toContain('Acme Events')
        ->and($rebuilt->envelope()->subject)->toContain('Acme Events');
});

test('a code is stored only as its hash', function () {
    $code = AttendeeAccessCode::factory()->create(['code_hash' => Hash::make('482913')]);

    expect($code->code_hash)->not->toBe('482913')
        ->and($code->matches('482913'))->toBeTrue()
        ->and($code->matches(' 482913 '))->toBeTrue()
        ->and($code->matches('000000'))->toBeFalse();
});

test('a code stops working once expired, used, or guessed at too often', function () {
    $fresh = AttendeeAccessCode::factory()->create();
    $expired = AttendeeAccessCode::factory()->create(['expires_at' => now()->subMinute()]);
    $used = AttendeeAccessCode::factory()->create(['consumed_at' => now()]);
    $guessed = AttendeeAccessCode::factory()->create(['attempts' => AttendeeAccessCode::MAX_ATTEMPTS]);

    expect($fresh->isUsable())->toBeTrue()
        ->and($expired->isUsable())->toBeFalse()
        ->and($used->isUsable())->toBeFalse()
        ->and($guessed->isUsable())->toBeFalse();
});

test('codes are six digits', function () {
    foreach (range(1, 50) as $ignored) {
        expect(AttendeeAccessCode::generateCode())->toMatch('/^\d{6}$/');
    }
});
```

Add the mailable to `tests/Feature/Mail/MailableRenderTest.php` — the reflection guard there fails until it has a case. Add `use App\Mail\Events\AttendeeAccessCodeMail;` and, in `$mailables`:

```php
        'AttendeeAccessCodeMail' => fn () => new AttendeeAccessCodeMail($tenant, '123456'),
```

- [ ] **Step 2: Run them and watch them fail**

Run: `"$PHP" artisan test --compact tests/Feature/Events/AttendeeAccessCodeTest.php tests/Feature/Mail/MailableRenderTest.php`
Expected: FAIL — classes `AttendeeAccessCodeMail` and `AttendeeAccessCode` not found.

- [ ] **Step 3: Implement**

Create the migration:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes an attendee receives to prove they hold an address. Kept
 * apart from transfer codes: a transfer code moves a ticket, this only proves
 * identity, and sharing a table would make one bug a ticket-theft bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('attendee_access_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            // Stored lowercased. The table is new, so normalising rewrites nothing.
            $table->string('email');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('attendee_access_codes');
    }
};
```

Create `app/Models/AttendeeAccessCode.php` — constant for constant with `EventRegistrationTransfer`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A one-time code proving someone holds an address with one organiser. Only
 * its hash is stored, so a leaked row proves nothing.
 */
final class AttendeeAccessCode extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /** A code is useless after this long, so a forwarded email goes stale. */
    public const int TTL_MINUTES = 15;

    /** Guessing a six-digit code is only hard if guessing is limited. */
    public const int MAX_ATTEMPTS = 5;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'email',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    /**
     * Six digits: short enough to read out of an email on a phone, and safe
     * because attempts are capped and it expires.
     */
    public static function generateCode(): string
    {
        return mb_str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < self::MAX_ATTEMPTS;
    }

    public function matches(string $code): bool
    {
        return Hash::check(Str::of($code)->trim()->toString(), $this->code_hash);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
```

Create `database/factories/AttendeeAccessCodeFactory.php` (match the namespace and style of `database/factories/EventBlastFactory.php`):

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AttendeeAccessCode;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<AttendeeAccessCode>
 */
final class AttendeeAccessCodeFactory extends Factory
{
    protected $model = AttendeeAccessCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'email' => mb_strtolower($this->faker->safeEmail()),
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(AttendeeAccessCode::TTL_MINUTES),
        ];
    }
}
```

Create `app/Mail/Events/AttendeeAccessCodeMail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail\Events;

use App\Models\AttendeeAccessCode;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A one-time code proving someone holds an address, so they can see what is
 * theirs with this organiser.
 *
 * Sent under the platform's name with the organiser named in it: branding as
 * the organiser needs an event, and a code asked for at /my belongs to none.
 *
 * The code is a constructor argument, never read off a model. This mail is
 * queued, and a queued mailable's models are rebuilt from the database on the
 * worker, so anything held only in memory would arrive empty.
 */
final class AttendeeAccessCodeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your code for {$this->tenant->name}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.events.attendee-access-code',
            with: [
                'organiser' => $this->tenant->name,
                'code' => $this->code,
                'minutes' => AttendeeAccessCode::TTL_MINUTES,
            ],
        );
    }
}
```

Create `resources/views/emails/events/attendee-access-code.blade.php`:

```blade
<x-mail::message>
# Your code

Someone asked to see their events with **{{ $organiser }}** using this address.

<x-mail::panel>
{{ $code }}
</x-mail::panel>

Enter it on the page you came from. It works once, and expires in {{ $minutes }} minutes.

**If you did not ask for this, ignore this email.** Nobody can use this address without the code.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
```

- [ ] **Step 4: Run the tests**

Run: `"$PHP" artisan test --compact tests/Feature/Events/AttendeeAccessCodeTest.php tests/Feature/Mail/MailableRenderTest.php`
Expected: all PASS, including the render guard.

- [ ] **Step 5: Gates and commit**

```bash
"$PHP" vendor/bin/pint --dirty && "$PHP" vendor/bin/phpstan analyse --memory-limit=2G
git add database/migrations/landlord/2026_09_22_100100_create_attendee_access_codes_table.php app/Models/AttendeeAccessCode.php database/factories/AttendeeAccessCodeFactory.php app/Mail/Events/AttendeeAccessCodeMail.php resources/views/emails/events/attendee-access-code.blade.php tests/Feature/Events/AttendeeAccessCodeTest.php tests/Feature/Mail/MailableRenderTest.php
git commit -m "feat(portal): one-time access codes for attendees, and the email that carries them" -m "Modelled constant for constant on transfer codes: six digits, stored only as a hash, fifteen minutes, five attempts, single use. A separate table, because a transfer code moves a ticket and this one only proves who someone is. The mail takes the code as an argument, so it survives the queue.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 8: Prove an address, remember it for one organiser, and forget it

**Files:**
- Create: `app/Services/Events/AttendeeVerification.php`
- Create: `app/Http/Requests/Attendee/SendAccessCodeRequest.php`
- Create: `app/Http/Requests/Attendee/ConfirmAccessCodeRequest.php`
- Create: `app/Http/Controllers/Public/AttendeeAccessController.php`
- Create: `app/Http/Middleware/EnsureAttendeeVerified.php`
- Modify: `app/Http/Kernel.php` (`$middlewareAliases`)
- Modify: `routes/subdomain.php` (public block, after the `public.events.service-requests.store` route)
- Create: `tests/Feature/Events/AttendeeVerificationTest.php`

**Interfaces:**
- Consumes: `AttendeeAccessCode` (Task 7), `AttendeeAccessCodeMail` (Task 7), `EventRegistration::forEmail()` (Task 5), `ContactMask::email()` (Task 4).
- Produces:
  - `AttendeeVerification`: `VERIFIED_MINUTES = 120`, `CODES_PER_HOUR = 5`; `sendCode(Tenant, string $email): void`; `confirm(Session, Tenant, string $email, string $code): bool`; `verifiedEmail(Session, Tenant): ?string`; `forget(Session, Tenant): void`; `hasAnythingFor(Tenant, string $email): bool`; `static normalise(string): string`.
  - Session key `attendee_verified.{tenant_id}` → `['email' => string, 'expires_at' => int unix timestamp]`.
  - Middleware alias `attendee_verified`; request attribute `attendee_email`.
  - Routes: `POST /my/verify/send` (`public.my.verify.send`), `POST /my/verify/confirm` (`public.my.verify.confirm`), `POST /my/verify/forget` (`public.my.verify.forget`), `GET /my/session` (`public.my.session`). Send and confirm take `registration` (uuid) **or** `email`; confirm also takes `code`. Confirm answers `{email: masked}` or 422 `{message}`. `/my/session` answers `{email: masked}` or 401.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Events/AttendeeVerificationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\AttendeeAccessCodeMail;
use App\Models\AttendeeAccessCode;
use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventAbstractAuthor;
use App\Models\EventCertificate;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\AttendeeVerification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * An attendee proves an address with an emailed code, and the proof holds for
 * one organiser only. The session cookie spans every organiser's subdomain,
 * so these tests hold that boundary in place.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
});

function attendeeAt(string $slug, string $email = 'ama@stem.org'): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => $email,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    return [$tenant, $registration, $slug.'.'.mb_ltrim((string) config('session.domain'), '.')];
}

/** @return array<string, array{email: string, expires_at: int}> */
function provenFor(Tenant $tenant, string $email = 'ama@stem.org'): array
{
    return ["attendee_verified.{$tenant->id}" => [
        'email' => $email,
        'expires_at' => now()->addMinutes(AttendeeVerification::VERIFIED_MINUTES)->getTimestamp(),
    ]];
}

/** The latest code, read from the mail the attendee would receive. */
function sentCode(): string
{
    $code = null;
    Mail::assertQueued(AttendeeAccessCodeMail::class, function (AttendeeAccessCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    return (string) $code;
}

test('a proof on one organiser is refused on another, in the same session', function () {
    [$acme, , $acmeHost] = attendeeAt('acme-verify');
    [, , $otherHost] = attendeeAt('other-verify');

    // One browser session spans every subdomain. Only the tenant key keeps
    // Acme's proof from opening another organiser's history. The first
    // assertion proves the session really is present for the second.
    $this->withSession(provenFor($acme));

    $this->getJson("http://{$acmeHost}/my/session", ['HTTP_HOST' => $acmeHost])->assertOk();
    $this->getJson("http://{$otherHost}/my/session", ['HTTP_HOST' => $otherHost])->assertUnauthorized();
});

test('being verified does not change what the portal page sends', function () {
    [$tenant, $registration, $host] = attendeeAt('payload-verify');
    $url = "http://{$host}/e/{$registration->event->slug}/registrations/{$registration->id}";

    $anonymous = $this->get($url, ['HTTP_HOST' => $host])->viewData('page')['props'];

    $this->withSession(provenFor($tenant));
    $verified = $this->get($url, ['HTTP_HOST' => $host])->viewData('page')['props'];

    // Verified data is fetched by panels from guarded endpoints, never shipped
    // in a page, so a forwarded link can never carry it.
    expect(array_keys($verified))->toEqual(array_keys($anonymous))
        ->and($verified['registration'])->toEqual($anonymous['registration']);
});

test('asking from a portal page sends the code to the registration, not to a typed address', function () {
    [, $registration, $host] = attendeeAt('code-owner');

    $this->postJson("http://{$host}/my/verify/send", [
        'registration' => $registration->id,
        'email' => 'intruder@elsewhere.org',
    ], ['HTTP_HOST' => $host])->assertOk();

    Mail::assertQueued(AttendeeAccessCodeMail::class, fn ($mail): bool => $mail->hasTo('ama@stem.org'));
    Mail::assertNotQueued(AttendeeAccessCodeMail::class, fn ($mail): bool => $mail->hasTo('intruder@elsewhere.org'));
});

test('asking for a code answers the same whether or not the address is known', function () {
    [, , $host] = attendeeAt('code-same');

    $known = $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $unknown = $this->postJson("http://{$host}/my/verify/send", ['email' => 'nobody@stem.org'], ['HTTP_HOST' => $host]);

    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json())->toEqual($known->json());
    Mail::assertQueued(AttendeeAccessCodeMail::class, 1);
});

test('a correct code proves the address for this organiser, whatever its capitals', function () {
    [$tenant, , $host] = attendeeAt('code-ok');

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'Ama@Stem.org'], ['HTTP_HOST' => $host])->assertOk();

    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@STEM.org', 'code' => sentCode()], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['email' => 'am•@stem.org'])
        ->assertSessionHas("attendee_verified.{$tenant->id}.email", 'ama@stem.org');
});

test('the code is stored hashed', function () {
    [, , $host] = attendeeAt('code-hashed');

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $code = sentCode();

    $stored = AttendeeAccessCode::query()->sole();
    expect($stored->code_hash)->not->toBe($code)
        ->and(Hash::check($code, $stored->code_hash))->toBeTrue();
});

test('five wrong guesses kill the code, even for the right one after', function () {
    [, , $host] = attendeeAt('code-guess');

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $code = sentCode();
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, AttendeeAccessCode::MAX_ATTEMPTS) as $ignored) {
        $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $wrong], ['HTTP_HOST' => $host])
            ->assertUnprocessable();
    }

    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $code], ['HTTP_HOST' => $host])
        ->assertUnprocessable();
});

test('a code expires after fifteen minutes', function () {
    [, , $host] = attendeeAt('code-expire');

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $code = sentCode();

    $this->travel(AttendeeAccessCode::TTL_MINUTES + 1)->minutes();

    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $code], ['HTTP_HOST' => $host])
        ->assertUnprocessable();
});

test('a code works once', function () {
    [, , $host] = attendeeAt('code-once');

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $code = sentCode();

    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $code], ['HTTP_HOST' => $host])->assertOk();
    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $code], ['HTTP_HOST' => $host])->assertUnprocessable();
});

test('only the latest code works', function () {
    [, , $host] = attendeeAt('code-latest');

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $first = sentCode();
    $this->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host]);
    $second = sentCode();

    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $first], ['HTTP_HOST' => $host])->assertUnprocessable();
    $this->postJson("http://{$host}/my/verify/confirm", ['email' => 'ama@stem.org', 'code' => $second], ['HTTP_HOST' => $host])->assertOk();
});

test('one address is sent at most five codes an hour, however many IPs ask', function () {
    [, , $host] = attendeeAt('code-cap');

    foreach (range(1, AttendeeVerification::CODES_PER_HOUR + 1) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->postJson("http://{$host}/my/verify/send", ['email' => 'ama@stem.org'], ['HTTP_HOST' => $host])
            ->assertOk();
    }

    Mail::assertQueued(AttendeeAccessCodeMail::class, AttendeeVerification::CODES_PER_HOUR);
});

test('someone who holds only a certificate can still be sent a code', function () {
    $tenant = Tenant::factory()->create(['slug' => 'cert-only', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $host = 'cert-only.'.mb_ltrim((string) config('session.domain'), '.');

    // A speaker's certificate can exist with no registration at all.
    EventCertificate::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => null,
        'recipient_name' => 'Dr Kofi Mensah',
        'recipient_email' => 'Kofi@Stem.org',
        'role' => 'speaker',
        'issued_at' => now(),
    ]);

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'kofi@stem.org'], ['HTTP_HOST' => $host])->assertOk();

    Mail::assertQueued(AttendeeAccessCodeMail::class, fn ($mail): bool => $mail->hasTo('kofi@stem.org'));
});

test('an abstract co-author who never registered can still be sent a code', function () {
    $tenant = Tenant::factory()->create(['slug' => 'author-only', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $host = 'author-only.'.mb_ltrim((string) config('session.domain'), '.');

    // Public submissions carry no user; authors are known only by address.
    $abstract = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'user_id' => null,
        'code' => 'ABS-0042',
        'title' => 'Outcomes in Rural Cardiology',
        'track' => 'Cardiology',
        'presentation_preference' => 'either',
        'body' => 'Background, methods, results and conclusion.',
        'status' => EventAbstract::STATUS_ACCEPTED_ORAL,
    ]);
    EventAbstractAuthor::create([
        'abstract_id' => $abstract->id,
        'first_name' => 'Efua',
        'last_name' => 'Owusu',
        'email' => 'Efua@Stem.org',
        'affiliation' => 'Korle Bu Teaching Hospital',
        'sort_order' => 1,
    ]);

    $this->postJson("http://{$host}/my/verify/send", ['email' => 'efua@stem.org'], ['HTTP_HOST' => $host])->assertOk();

    Mail::assertQueued(AttendeeAccessCodeMail::class, fn ($mail): bool => $mail->hasTo('efua@stem.org'));
});

test('the proven address comes from the session, never from the request', function () {
    [$tenant, , $host] = attendeeAt('who-asks');

    $this->withSession(provenFor($tenant));

    $this->getJson("http://{$host}/my/session?email=kofi@stem.org", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJson(['email' => 'am•@stem.org']);
});

test('a proof lapses after two hours', function () {
    [$tenant, , $host] = attendeeAt('lapse');

    $this->withSession(provenFor($tenant));
    $this->travel(AttendeeVerification::VERIFIED_MINUTES + 1)->minutes();

    $this->getJson("http://{$host}/my/session", ['HTTP_HOST' => $host])->assertUnauthorized();
});

test('signing out forgets the proof', function () {
    [$tenant, , $host] = attendeeAt('forget');

    $this->withSession(provenFor($tenant));
    $this->postJson("http://{$host}/my/verify/forget", [], ['HTTP_HOST' => $host])->assertOk();

    $this->getJson("http://{$host}/my/session", ['HTTP_HOST' => $host])->assertUnauthorized();
});

test('proving an address rotates the session id', function () {
    [$tenant] = attendeeAt('rotate');

    AttendeeAccessCode::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'ama@stem.org',
        'code_hash' => Hash::make('482913'),
        'expires_at' => now()->addMinutes(AttendeeAccessCode::TTL_MINUTES),
    ]);

    $session = app('session.store');
    $session->start();
    $before = $session->getId();

    expect(app(AttendeeVerification::class)->confirm($session, $tenant, 'ama@stem.org', '482913'))->toBeTrue()
        ->and($session->getId())->not->toBe($before);
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `"$PHP" artisan test --compact tests/Feature/Events/AttendeeVerificationTest.php`
Expected: FAIL — routes 404 and `AttendeeVerification` not found.

- [ ] **Step 3: Implement the service**

Create `app/Services/Events/AttendeeVerification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Mail\Events\AttendeeAccessCodeMail;
use App\Models\AttendeeAccessCode;
use App\Models\EventAbstractAuthor;
use App\Models\EventCertificate;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Tenancy\FeatureMeteringService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Proves that someone holds an email address, and remembers it for a while, so
 * they can see everything tied to that address with one organiser.
 *
 * The proof is scoped to a tenant explicitly. The session cookie is shared
 * across every organiser's subdomain, so one session can hold proofs for
 * several organisers side by side; nothing about the cookie keeps them apart.
 * Only the tenant key in verifiedEmail() does.
 */
final class AttendeeVerification
{
    /** How long a proof lasts: enough to look around, short on a shared device. */
    public const int VERIFIED_MINUTES = 120;

    /** Codes one address can be sent per hour, whatever IPs ask for them. */
    public const int CODES_PER_HOUR = 5;

    /**
     * Sends a code if there is anything to see. The caller answers the same
     * either way, so this cannot be used to find out who attends.
     */
    public function sendCode(Tenant $tenant, string $email): void
    {
        $email = self::normalise($email);

        if (! $this->hasAnythingFor($tenant, $email)) {
            return;
        }

        $key = "attendee-code:{$tenant->id}:{$email}";
        if (RateLimiter::tooManyAttempts($key, self::CODES_PER_HOUR)) {
            return;
        }
        RateLimiter::hit($key, 3600);

        // Only the latest code works.
        AttendeeAccessCode::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = AttendeeAccessCode::generateCode();

        AttendeeAccessCode::query()->create([
            'tenant_id' => $tenant->id,
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(AttendeeAccessCode::TTL_MINUTES),
        ]);

        Mail::to($email)->queue(new AttendeeAccessCodeMail($tenant, $code));
        app(FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');
    }

    /**
     * On a match: consumes the code, rotates the session id against fixation,
     * and remembers the address for this tenant only.
     */
    public function confirm(Session $session, Tenant $tenant, string $email, string $code): bool
    {
        $email = self::normalise($email);

        $accessCode = AttendeeAccessCode::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->latest()
            ->first();

        if ($accessCode === null || ! $accessCode->isUsable()) {
            return false;
        }

        if (! $accessCode->matches($code)) {
            $accessCode->increment('attempts');

            return false;
        }

        $accessCode->update(['consumed_at' => now()]);

        $session->regenerate();
        $session->put(self::sessionKey($tenant), [
            'email' => $email,
            'expires_at' => now()->addMinutes(self::VERIFIED_MINUTES)->getTimestamp(),
        ]);

        return true;
    }

    /** The address proven for this tenant, or null. Never another tenant's. */
    public function verifiedEmail(Session $session, Tenant $tenant): ?string
    {
        $marker = $session->get(self::sessionKey($tenant));

        if (! is_array($marker) || ! isset($marker['email'], $marker['expires_at'])) {
            return null;
        }

        if ($marker['expires_at'] <= now()->getTimestamp()) {
            $session->forget(self::sessionKey($tenant));

            return null;
        }

        return (string) $marker['email'];
    }

    public function forget(Session $session, Tenant $tenant): void
    {
        $session->forget(self::sessionKey($tenant));
    }

    /**
     * A live registration, an abstract authorship, or a certificate.
     * Certificates count on their own: a speaker may hold one with no
     * registration at all.
     */
    public function hasAnythingFor(Tenant $tenant, string $email): bool
    {
        $email = self::normalise($email);

        return EventRegistration::query()
            ->where('tenant_id', $tenant->id)
            ->forEmail($email)
            ->whereNotIn('status', [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED])
            ->exists()
            || EventAbstractAuthor::query()
                ->whereHas('abstract', fn ($query) => $query->where('tenant_id', $tenant->id))
                ->whereRaw('lower(email) = ?', [$email])
                ->exists()
            || EventCertificate::query()
                ->where('tenant_id', $tenant->id)
                ->whereRaw('lower(recipient_email) = ?', [$email])
                ->exists();
    }

    public static function normalise(string $email): string
    {
        return mb_strtolower(mb_trim($email));
    }

    private static function sessionKey(Tenant $tenant): string
    {
        return "attendee_verified.{$tenant->id}";
    }
}
```

- [ ] **Step 4: Implement the requests, controller, middleware and routes**

Create `app/Http/Requests/Attendee/SendAccessCodeRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking for a code: from a portal page by registration, or from /my by address.
 */
final class SendAccessCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registration' => ['required_without:email', 'nullable', 'uuid'],
            'email' => ['required_without:registration', 'nullable', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['email.email' => 'Enter the address you registered with.'];
    }
}
```

Create `app/Http/Requests/Attendee/ConfirmAccessCodeRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Entering a code. Identifies the address the same way the request for it did.
 */
final class ConfirmAccessCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registration' => ['required_without:email', 'nullable', 'uuid'],
            'email' => ['required_without:registration', 'nullable', 'email', 'max:255'],
            'code' => ['required', 'string', 'max:12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.required' => 'Enter the code from the email.'];
    }
}
```

Create `app/Http/Controllers/Public/AttendeeAccessController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\ConfirmAccessCodeRequest;
use App\Http\Requests\Attendee\SendAccessCodeRequest;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\AttendeeVerification;
use App\Services\Tenancy\TenantContext;
use App\Support\ContactMask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets an attendee prove they hold an address, so the portal can show what is
 * theirs with this organiser.
 */
final class AttendeeAccessController extends Controller
{
    public function __construct(private readonly AttendeeVerification $verification) {}

    public function send(SendAccessCodeRequest $request): JsonResponse
    {
        $tenant = $this->tenant();
        $email = $this->addressFor($request, $tenant);

        if ($email !== null) {
            $this->verification->sendCode($tenant, $email);
        }

        // The same answer whatever happened. Anyone can type an address here,
        // and a different reply for "found" would reveal who attends.
        return response()->json([
            'message' => 'If that address has anything with this organiser, a code is on its way to it.',
        ]);
    }

    public function confirm(ConfirmAccessCodeRequest $request): JsonResponse
    {
        $tenant = $this->tenant();
        $email = $this->addressFor($request, $tenant);

        if ($email === null || ! $this->verification->confirm($request->session(), $tenant, $email, (string) $request->input('code'))) {
            return response()->json(['message' => "That code didn't match. Check it, or ask for a new one."], 422);
        }

        return response()->json(['email' => ContactMask::email(AttendeeVerification::normalise($email))]);
    }

    public function forget(Request $request): JsonResponse
    {
        $this->verification->forget($request->session(), $this->tenant());

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * Who the visitor has proven to be here. Behind attendee_verified, so the
     * address comes from the session, never from the request.
     */
    public function session(Request $request): JsonResponse
    {
        return response()->json([
            'email' => ContactMask::email((string) $request->attributes->get('attendee_email')),
        ]);
    }

    /**
     * From a portal page the address is the registration's own, never one the
     * visitor typed -- so a forwarded link reaches the pass, not the history.
     */
    private function addressFor(Request $request, Tenant $tenant): ?string
    {
        if ($request->filled('registration')) {
            $email = EventRegistration::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $request->input('registration'))
                ->value('email');

            return $email !== null ? (string) $email : null;
        }

        return (string) $request->input('email');
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(404);
    }
}
```

Create `app/Http/Middleware/EnsureAttendeeVerified.php`, following `EnsureFeatureEnabled`'s `final readonly class` style:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Events\AttendeeVerification;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits a request only when the visitor has proven an address for the tenant
 * this subdomain belongs to, and hands controllers that address.
 *
 * The tenant comes from TenantContext -- the Host header, via ResolveTenant --
 * never from input. Controllers read the address from the request attribute,
 * never from input, so a request cannot ask for someone else's data.
 */
final readonly class EnsureAttendeeVerified
{
    public function __construct(private AttendeeVerification $verification) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class)->getTenant();
        $email = $tenant !== null ? $this->verification->verifiedEmail($request->session(), $tenant) : null;

        if ($email === null) {
            return response()->json(['message' => 'Confirm your email address to see this.'], 401);
        }

        $request->attributes->set('attendee_email', $email);

        return $next($request);
    }
}
```

In `app/Http/Kernel.php`, add to `$middlewareAliases` (after `'feature_limit'`):

```php
        'attendee_verified' => Middleware\EnsureAttendeeVerified::class,
```

In `routes/subdomain.php`, after the `public.events.service-requests.store` route, add:

```php

// Attendee identity across this organiser's events: proving an address.
// The panels that show history behind it arrive in Plan B.
Route::post('/my/verify/send', [App\Http\Controllers\Public\AttendeeAccessController::class, 'send'])->middleware('throttle:public-registration')->name('public.my.verify.send');
Route::post('/my/verify/confirm', [App\Http\Controllers\Public\AttendeeAccessController::class, 'confirm'])->middleware('throttle:public-registration')->name('public.my.verify.confirm');
Route::post('/my/verify/forget', [App\Http\Controllers\Public\AttendeeAccessController::class, 'forget'])->name('public.my.verify.forget');
Route::get('/my/session', [App\Http\Controllers\Public\AttendeeAccessController::class, 'session'])->middleware('attendee_verified')->name('public.my.session');
```

- [ ] **Step 5: Run the tests**

Run: `"$PHP" artisan test --compact tests/Feature/Events/AttendeeVerificationTest.php`
Expected: all PASS.

If the cross-tenant test fails on its **first** assertion (the Acme request returns 401), the seeded session is not reaching the request — fix the test setup, never the boundary. The test is only meaningful when that first assertion passes.

- [ ] **Step 6: Gates and commit**

```bash
"$PHP" vendor/bin/pint --dirty && "$PHP" vendor/bin/phpstan analyse --memory-limit=2G
git add app/Services/Events/AttendeeVerification.php app/Http/Requests/Attendee app/Http/Controllers/Public/AttendeeAccessController.php app/Http/Middleware/EnsureAttendeeVerified.php app/Http/Kernel.php routes/subdomain.php tests/Feature/Events/AttendeeVerificationTest.php
git commit -m "feat(portal): let attendees prove their address to one organiser at a time" -m "A code emailed to an address proves it; the proof is remembered for two hours under the current tenant's key. The session cookie spans every organiser's subdomain, so the boundary is that key, read only from TenantContext, and a test holds a proof on one organiser against another in the same session. The same answer is given whether or not an address is known, codes go to a registration's own address when asked from its page, and one address gets at most five codes an hour.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 9: `/my`, where an attendee proves their address

**Files:**
- Create: `resources/js/Pages/Public/Events/AttendeePortal/VerifyPrompt.jsx`
- Create: `resources/js/Pages/Public/Events/AttendeePortal/MyPortal.jsx`
- Modify: `app/Http/Controllers/Public/AttendeeAccessController.php` (add `page`)
- Modify: `routes/subdomain.php` (add `GET /my`)
- Create: `resources/js/test/portal/VerifyPrompt.test.jsx`
- Modify: `tests/Feature/Events/AttendeeVerificationTest.php`

**Interfaces:**
- Consumes: the four routes and response shapes from Task 8.
- Produces: `GET /my` (`public.my`) rendering `Public/Events/AttendeePortal/MyPortal` with props `organiser: {name}` and `verifiedEmail: string|null` (masked); `VerifyPrompt({ registrationId = null, sentTo = null, onVerified })`, calling `onVerified(maskedEmail)`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Events/AttendeeVerificationTest.php`:

```php
test('the /my page asks for an address until one is proven', function () {
    [$tenant, , $host] = attendeeAt('my-page');

    $this->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/Events/AttendeePortal/MyPortal')
            ->where('organiser.name', $tenant->name)
            ->where('verifiedEmail', null));

    $this->withSession(provenFor($tenant));

    $this->get("http://{$host}/my", ['HTTP_HOST' => $host])
        ->assertInertia(fn ($page) => $page->where('verifiedEmail', 'am•@stem.org'));
});
```

Create `resources/js/test/portal/VerifyPrompt.test.jsx`:

```jsx
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import VerifyPrompt from '@/Pages/Public/Events/AttendeePortal/VerifyPrompt';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));

const answer = (ok, json) => Promise.resolve({ ok, json: async () => json });

describe('verify prompt', () => {
    beforeEach(() => csrfFetch.mockReset());
    afterEach(() => vi.useRealTimers());

    it('asks for an address, sends a code, and reports the proven address', async () => {
        csrfFetch
            .mockReturnValueOnce(answer(true, { message: 'On its way.' }))
            .mockReturnValueOnce(answer(true, { email: 'am•@stem.org' }));
        const onVerified = vi.fn();
        render(<VerifyPrompt onVerified={onVerified} />);

        fireEvent.change(screen.getByLabelText('The address you registered with'), {
            target: { value: 'ama@stem.org' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Email me a code' }));
        fireEvent.change(await screen.findByLabelText('Code'), { target: { value: '482913' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(onVerified).toHaveBeenCalledWith('am•@stem.org'));
        expect(JSON.parse(csrfFetch.mock.calls[1][1].body)).toEqual({
            email: 'ama@stem.org',
            code: '482913',
        });
    });

    it('from a portal page, asks by registration and never sends a typed address', async () => {
        csrfFetch.mockReturnValueOnce(answer(true, { message: 'On its way.' }));
        render(<VerifyPrompt registrationId="reg-1" sentTo="am•@stem.org" onVerified={() => {}} />);

        expect(screen.queryByLabelText('The address you registered with')).toBeNull();
        expect(screen.getByText(/am•@stem\.org/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Email me a code' }));

        await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
        expect(JSON.parse(csrfFetch.mock.calls[0][1].body)).toEqual({ registration: 'reg-1' });
    });

    it('explains the five-try limit only after a second wrong code', async () => {
        csrfFetch
            .mockReturnValueOnce(answer(true, { message: 'On its way.' }))
            .mockReturnValue(answer(false, { message: "That code didn't match." }));
        render(<VerifyPrompt registrationId="reg-1" onVerified={() => {}} />);

        fireEvent.click(screen.getByRole('button', { name: 'Email me a code' }));
        fireEvent.change(await screen.findByLabelText('Code'), { target: { value: '000000' } });

        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        await screen.findByText("That code didn't match.");
        expect(screen.queryByText(/five wrong tries/)).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        expect(await screen.findByText(/five wrong tries/)).toBeTruthy();
    });

    it('lets a new code be asked for only after a minute', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        csrfFetch.mockReturnValue(answer(true, { message: 'On its way.' }));
        render(<VerifyPrompt registrationId="reg-1" onVerified={() => {}} />);

        fireEvent.click(screen.getByRole('button', { name: 'Email me a code' }));
        const resend = await screen.findByRole('button', { name: /Send another code/ });
        expect(resend.disabled).toBe(true);

        for (let second = 0; second < 60; second += 1) {
            await act(async () => {
                vi.advanceTimersByTime(1000);
            });
        }

        expect(screen.getByRole('button', { name: 'Send another code' }).disabled).toBe(false);
    });
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `"$PHP" artisan test --compact --filter="the /my page" tests/Feature/Events/AttendeeVerificationTest.php`
Expected: FAIL — `GET /my` is 404.

Run: `npm test`
Expected: FAIL — cannot resolve `VerifyPrompt`.

- [ ] **Step 3: Implement the page route**

In `app/Http/Controllers/Public/AttendeeAccessController.php`, add `use Inertia\Inertia;` and `use Inertia\Response;`, then:

```php
    /**
     * Everything an attendee has with this organiser, once they prove an
     * address. Reached without a registration link, by co-authors and
     * returning delegates. Nothing links here until Plan B gives it content.
     */
    public function page(Request $request): Response
    {
        $tenant = $this->tenant();
        $email = $this->verification->verifiedEmail($request->session(), $tenant);

        return Inertia::render('Public/Events/AttendeePortal/MyPortal', [
            'organiser' => ['name' => $tenant->name],
            'verifiedEmail' => $email !== null ? ContactMask::email($email) : null,
        ]);
    }
```

In `routes/subdomain.php`, directly above the `/my/verify/send` route, add:

```php
Route::get('/my', [App\Http\Controllers\Public\AttendeeAccessController::class, 'page'])->name('public.my');
```

- [ ] **Step 4: Implement the components**

Create `resources/js/Pages/Public/Events/AttendeePortal/VerifyPrompt.jsx`:

```jsx
import { useEffect, useState } from 'react';
import csrfFetch from '@/lib/csrfFetch';

const RESEND_AFTER_SECONDS = 60;

const INPUT_CLASS =
    'w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none';
const PRIMARY_CLASS =
    'inline-flex items-center rounded-lg bg-accent px-5 py-2.5 text-[13.5px] font-medium text-white hover:opacity-90 disabled:opacity-60';

/**
 * Proves an address with an emailed code. Given a registration, the code goes
 * to that registration's own address and nothing is typed; otherwise it goes
 * to the address entered here.
 */
export default function VerifyPrompt({ registrationId = null, sentTo = null, onVerified }) {
    const [stage, setStage] = useState(registrationId ? 'ready' : 'address');
    const [email, setEmail] = useState('');
    const [code, setCode] = useState('');
    const [message, setMessage] = useState(null);
    const [busy, setBusy] = useState(false);
    const [misses, setMisses] = useState(0);
    const [wait, setWait] = useState(0);

    // One interval for the whole countdown, updated functionally. Re-arming a
    // timeout on every tick would depend on React flushing between ticks.
    const counting = wait > 0;
    useEffect(() => {
        if (!counting) {
            return undefined;
        }
        const timer = setInterval(() => setWait((left) => Math.max(0, left - 1)), 1000);
        return () => clearInterval(timer);
    }, [counting]);

    const who = registrationId ? { registration: registrationId } : { email };

    const send = async (e) => {
        e?.preventDefault();
        setBusy(true);
        const response = await csrfFetch(route('public.my.verify.send'), {
            method: 'POST',
            body: JSON.stringify(who),
        });
        const json = await response.json().catch(() => ({}));
        setBusy(false);

        if (!response.ok) {
            setMessage('Too many attempts just now. Try again in a minute.');
            return;
        }
        setMessage(json.message);
        setStage('code');
        setWait(RESEND_AFTER_SECONDS);
    };

    const confirm = async (e) => {
        e.preventDefault();
        setBusy(true);
        const response = await csrfFetch(route('public.my.verify.confirm'), {
            method: 'POST',
            body: JSON.stringify({ ...who, code }),
        });
        const json = await response.json().catch(() => ({}));
        setBusy(false);

        if (response.ok) {
            onVerified(json.email);
            return;
        }
        setMisses(misses + 1);
        setMessage(json.message ?? "That code didn't match.");
    };

    if (stage === 'address') {
        return (
            <form onSubmit={send} className="space-y-3">
                <label htmlFor="verify-email" className="block text-[13px] text-ink-secondary">
                    The address you registered with
                </label>
                <input
                    id="verify-email"
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    className={INPUT_CLASS}
                />
                <button type="submit" disabled={busy} className={PRIMARY_CLASS}>
                    {busy ? 'Sending…' : 'Email me a code'}
                </button>
                {message && <p className="text-[13px] text-ink-secondary">{message}</p>}
            </form>
        );
    }

    if (stage === 'ready') {
        return (
            <div className="space-y-3">
                <p className="text-[13.5px] text-ink-secondary">
                    To see everything you have with this organiser, confirm it's you. We'll email a
                    code to {sentTo ?? 'the address on this registration'}.
                </p>
                <button type="button" onClick={send} disabled={busy} className={PRIMARY_CLASS}>
                    {busy ? 'Sending…' : 'Email me a code'}
                </button>
                {message && <p className="text-[13px] text-ink-secondary">{message}</p>}
            </div>
        );
    }

    return (
        <form onSubmit={confirm} className="space-y-3">
            {message && <p className="text-[13px] text-ink-secondary">{message}</p>}
            <label htmlFor="verify-code" className="block text-[13px] text-ink-secondary">
                Code
            </label>
            <input
                id="verify-code"
                inputMode="numeric"
                autoComplete="one-time-code"
                required
                value={code}
                onChange={(e) => setCode(e.target.value)}
                className={INPUT_CLASS}
            />
            <div className="flex items-center gap-4">
                <button type="submit" disabled={busy} className={PRIMARY_CLASS}>
                    Confirm
                </button>
                <button
                    type="button"
                    onClick={send}
                    disabled={wait > 0 || busy}
                    className="text-[12.5px] text-accent underline disabled:text-ink-secondary disabled:no-underline"
                >
                    {wait > 0 ? `Send another code in ${wait}s` : 'Send another code'}
                </button>
            </div>
            {misses >= 2 && (
                <p className="text-[12.5px] text-ink-secondary">
                    After five wrong tries a code stops working. Ask for a new one if you need to.
                </p>
            )}
            <p className="text-[12.5px] text-ink-secondary">
                Wrong address? The organiser can correct it for you.
            </p>
        </form>
    );
}
```

The "five wrong tries" copy is what the test matches with `/five wrong tries/`, and "Send another code in 60s" is matched by `/Send another code/`. Keep both phrasings if you edit the copy.

Create `resources/js/Pages/Public/Events/AttendeePortal/MyPortal.jsx`:

```jsx
import { useState } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import csrfFetch from '@/lib/csrfFetch';
import VerifyPrompt from './VerifyPrompt';

/**
 * Everything an attendee has with this organiser, once they prove an address.
 * Reached without a registration link, by co-authors and returning delegates.
 * The panels that show history arrive in Plan B.
 */
export default function MyPortal({ organiser, verifiedEmail: provenAtLoad }) {
    const [verifiedEmail, setVerifiedEmail] = useState(provenAtLoad);

    const signOut = async () => {
        await csrfFetch(route('public.my.verify.forget'), { method: 'POST' });
        setVerifiedEmail(null);
    };

    return (
        <PublicLayout>
            <div className="mx-auto max-w-2xl px-6 py-16 sm:px-10">
                <h1 className="text-2xl font-normal tracking-tight text-ink">
                    Your events with {organiser.name}
                </h1>
                {verifiedEmail ? (
                    <p className="mt-3 text-[13.5px] text-ink-secondary">
                        Signed in as {verifiedEmail}.{' '}
                        <button type="button" onClick={signOut} className="text-accent underline">
                            Sign out
                        </button>
                    </p>
                ) : (
                    <div className="mt-8">
                        <VerifyPrompt onVerified={setVerifiedEmail} />
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
```

- [ ] **Step 5: Run everything for this task**

Run: `npm test && npm run lint && npm run build`
Expected: all PASS.

Run: `"$PHP" artisan test --compact tests/Feature/Events/AttendeeVerificationTest.php`
Expected: all PASS.

The timer test uses fake timers that also advance with real time, so `findBy*` still resolves, and it advances one second per `act()` so React renders between ticks. If it proves flaky, find the cause; do not delete it or loosen its assertions.

In a browser, visit `https://miconvener.test/my` on a tenant subdomain that has a registration. Enter that registration's address, read the code from the local mail log, enter it, and confirm "Signed in as …" appears. Sign out, and the prompt returns.

- [ ] **Step 6: Gates and commit**

```bash
"$PHP" vendor/bin/pint --dirty && "$PHP" vendor/bin/phpstan analyse --memory-limit=2G
git add app/Http/Controllers/Public/AttendeeAccessController.php routes/subdomain.php resources/js/Pages/Public/Events/AttendeePortal/VerifyPrompt.jsx resources/js/Pages/Public/Events/AttendeePortal/MyPortal.jsx resources/js/test/portal/VerifyPrompt.test.jsx tests/Feature/Events/AttendeeVerificationTest.php
git commit -m "feat(portal): add /my, where an attendee proves their address" -m "Asks for an address, emails a code, and confirms it; signing out forgets the proof. From a portal page the prompt asks by registration and never takes a typed address. Nothing links to /my yet: the panels that give it something to show arrive in Plan B.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Final verification

- [ ] Full PHP suite: `"$PHP" artisan test --compact` — all PASS. (It takes 20–30 minutes; run it in the background.)
- [ ] `npm test && npm run lint && npm run build` — all PASS.
- [ ] `"$PHP" vendor/bin/pint --test` and `"$PHP" vendor/bin/phpstan analyse --memory-limit=2G` — clean.
- [ ] `git log --oneline -9` shows nine commits, one per task.
- [ ] Append a track entry to `conductor/tracks.md` (append only; `docs/` and the plan stay untracked by project convention).
- [ ] Stop. Ask the owner before pushing, since pushing to `main` deploys. After deploy, verify on production as on 2026-09-21/22: the migrations ran, the portal renders under its new component, and — with the owner's agreement, since it sends real mail — a transfer code arrives with its digits visible.

## Not in this plan (Plan B)

My events with past events' materials; My certificates; My abstracts; My attendance; the `find-ticket` entry and the portal's "verify it's you" button; the shared verified-panel strip; the speaker-slide release policy. Plan B is written after Plan A ships.
