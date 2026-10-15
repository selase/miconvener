# Homepage UI and Copy Updates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This is a review draft; do not execute product changes before the user reviews it.

**Goal:** Present MiConvener as a connected platform for organisers, attendees, event crews, venue operators and service providers, using five concise hero slides and a fuller homepage beneath them.

**Architecture:** Extend the existing Blade landing page and its accessible, manually operated showcase. Keep the blue identity, product layout, existing pricing component and public routes. Use the existing configuration for the audience section and feature catalogue; keep the current hero interaction script scoped to its own component.

**Review summary:** Keep the blue homepage, expand the hero to five benefit-led slides, replace the narrow lifecycle section with four audiences, add one venue/facility section, and give the remaining capabilities clear coverage in the feature catalogue. Pricing and backend behaviour stay unchanged.

**Tech Stack:** Laravel 13, Blade, existing public-page Tailwind CDN and scoped CSS, native JavaScript, Livewire pricing, Pest and Vitest/jsdom. No new dependencies or frontend migration.

**Spec:** The design and copy brief below is the self-contained specification for this plan, based on the user's approved five slide groups and requested broader coverage beneath the hero.

**Status:** Draft for review, 10 October 2026. Planning only. The current three-slide implementation is committed at `b7ba753` on `codex/homepage-hero-showcase`; it is not merged or pushed.

## Design and copy brief

### What stays familiar

Keep the iconic blue, grid/glow backdrop, logo, navigation style, typography, primary calls to action, pricing and footer. Retain the existing white/soft-grey sections, restrained borders and product-window visual language. Improve coverage without introducing a full-screen carousel, oversized new sections, invented testimonials or a new visual theme.

Treat the section after the hero as narrow in subject matter, rather than assume its container needs widening. Preserve the existing maximum page width. The new audience cards must comfortably use that width and stack on phones.

### Proposed page order

1. Blue hero with five product slides.
2. Four audiences: “Built for everyone behind the gathering.”
3. Existing offline check-in section, with accurate synchronisation copy.
4. Existing guest-assistance section, highlighting practical floor coordination.
5. New venue/facility section showing calendar and operational readiness.
6. Existing finance section, broadened to cover contributions and donations.
7. Expanded feature catalogue, including academic workflows and recognition.
8. Existing community examples, refined to connect to recurring gatherings and giving.
9. Existing pricing, FAQ and final call to action.

Only one substantial new section is proposed: venue/facility operations. Badges, certificates, academic features and coordination receive space in the hero and catalogue instead of each adding another long homepage section.

### Hero copy

**Headline:** “Run the whole event\nfrom one place” — retain the familiar headline.

**Proposed supporting copy:** “Bring programmes, people, payments and places together. Plan the event, equip your crew, engage your guests and keep the venue ready—from the first registration to the final settlement.”

**Calls to action:** “Start free” and “See pricing.” Keep the existing free-plan limits and no-card note, pinned to the real catalogue.

| Tab | Slide headline | Supporting copy | Visual focus |
| --- | --- | --- | --- |
| Plan & sell | Bring your programme, people and ticket sales together. | Publish your event, organise sessions and speakers, register guests and take card or mobile money payments. | Programme alongside registration/ticket status; a small task or sponsor-deliverable indicator. |
| Run & engage | Keep the door moving and the room connected. | Check guests in from your crew’s phones, respond to requests and bring the audience into the conversation. | Existing event-day overview, with check-in and assistance; concise polls/Q&A indicators. |
| Design & recognise | Make every badge and certificate unmistakably yours. | Create branded badges and verifiable certificates, with your artwork, fonts, logo and QR placement. | One badge and one certificate, plus a small verification/attendee-portal cue. |
| Collect & settle | See what came in, what went out and what you receive. | Track ticket payments, donations, contributions and refunds, then see the fees and payout on your settlement statement. | Compact finance breakdown using clearly illustrative figures, not a promised payout or universal rate. |
| Venues & services | Find the right space. Keep your facility ready. | Browse listed spaces and event services, and coordinate availability, inquiries and facility preparations. | Venue calendar and readiness tasks, with a secondary listing/storefront cue. Public discovery wording is subject to the readiness gate below. |

**Fifth-slide fallback if marketplace promotion remains restricted:** Headline “Keep every space ready for the event.” Supporting copy “Coordinate your spaces, booking calendar, inquiries, facility tasks and organiser handovers.” Show the venue operations interface only. Keep the fifth group in the review design; do not label functioning facility tools “coming soon” merely because marketplace promotion is withheld.

### Hero behaviour and layout

- Five equal-width tabs on desktop above one product window; active underline and visible keyboard focus.
- Retain “Run & engage” as the default, carrying forward the existing overview.
- Manual selection only; no autoplay, countdown, timer or slide takeover.
- Arrow Left/Right wrap between tabs; Home/End select the first/last; Enter/Space activate through native button behaviour.
- Exactly one selected tab and visible panel; inactive panels remain inaccessible to keyboard and assistive technology.
- Maintain a stable frame height when switching at a given viewport. Keep each preview concise so the tallest panel does not create excessive empty space in the others.
- On phones, use a horizontally scrollable tab strip within the component, with the selected tab brought into view. Preserve native swiping and allow labels to remain readable; the document itself must not overflow sideways.
- Keep the full hero height close to the current implementation. Preview content can simplify on phones, but each slide's headline and main benefit must remain visible.
- Without JavaScript, retain the original overview and hide the nonfunctional tab controls.
- Product visuals are illustrative. Do not make decorative mock controls focusable or imply they are an interactive demo; label the mockup “Example event workspace.”

### Audience section immediately after the hero

**Heading:** “Built for everyone behind the gathering”

**Supporting copy:** “Bring the people organising, hosting, running and attending your event into connected workspaces.”

Use four clear cards in the existing restrained style, without a carousel, stock-photo montage or extra calls to action in each card.

| Audience | Proposed card copy |
| --- | --- |
| Event organisers | Coordinate programmes, speakers, registrations, team tasks and sponsor commitments. |
| Door & floor teams | Check guests in, track session arrivals and respond to requests from their own phones. |
| Venues & service providers | Manage spaces, inquiries, quotations and facility preparations, with a shared view of the event. |
| Attendees & participants | Find tickets, available materials, receipts and certificates, and take part in the event from one personal portal. |

Use four columns at wide widths, two at medium widths and one on narrow phones. Service-provider wording must match the readiness review; if its workspace is not ready, use “Venue operators” and omit that promise from this card.

### Supporting sections and copy

**Offline check-in — retain:** “Your crew scans from their own phones.” Emphasise staff links, no app/account requirement, scan attribution and continued scanning without signal. Explain that offline scans sync when connectivity returns. Do not imply that disconnected devices share immediate totals or can immediately detect one another's offline scans.

**Guest assistance — retain:** “When a guest needs something, someone’s phone buzzes.” Confirm that the notification channel shown actually works before keeping the buzz claim. If device testing has not verified it, use “When a guest needs something, your crew sees the request.” Keep requests, ownership and status as the visible proof. Do not promise vibration or sound on every phone.

**New venue/facility section:** “Keep every space ready for the next gathering.” Supporting copy: “See your bookings, organise facility tasks and coordinate inspections and handovers with the event organiser.” Visual: calendar beside a short readiness checklist. Supporting points: availability and blocked dates; inquiries and quotations; assigned tasks and completion; organiser messages; check-in/check-out inspections. Scope this to event facility operations; do not imply a full building-maintenance or asset-inventory system.

**Finance — broaden:** “Know what came in—and what you receive.” Supporting copy: “Bring ticket payments, donations and contributions into a clear event record. Follow refunds, fees and payouts through your settlement statement.” Keep card/mobile-money practicality and bank/mobile-money payouts. Preserve actual plan pricing and commission disclosures.

**New or expanded catalogue copy:**

| Title | Proposed explanatory sentence |
| --- | --- |
| Team tasks & readiness | Assign responsibilities and track the work needed to prepare and run your event. |
| Sponsor commitments | Record sponsor deliverables and follow their completion with your team. |
| Custom forms & participant groups | Collect the information your event needs and organise participants into useful groups. |
| Badges & certificates | Design branded badges and certificates with your fonts, artwork, logo and QR placement. |
| Certificate verification | Give recipients a certificate with a public verification page. |
| Academic submissions & peer review | Receive abstracts, assign reviewers, record decisions and export an abstract book. |
| Venue calendar & facilities | Coordinate bookings, blocked dates, facility tasks and inspection handovers. |

Retain accurate existing catalogue items for ticketing, programme, speaker portal, offline scanning, assistance, polling, messaging, attendee portal, giving, settlement, webhooks and safeguards. Group them as below, without repeating the same capability in several groups.

**Feature catalogue:** Heading “The details that make the whole event work.” Group the existing capabilities into readable clusters rather than an undifferentiated list:

- Programme & coordination: sessions, speaker portal/materials, task ownership and sponsor deliverables.
- Registration & participation: tickets, custom forms, participant groups, room/session attendance and attendee portal.
- Engagement & communication: live polls, moderated Q&A, discussions, guest requests, email and SMS.
- Recognition & academic events: badge/certificate design and verification; abstract submissions, assigned reviewers, decisions and abstract-book export.
- Giving & finance: donations/contributions, refunds, settlement exports and payouts.
- Venue operations: space details/capacities/amenities, availability, inquiries, tasks and inspections; discovery/storefront links only after the marketplace gate.

Keep each item to a short title and one concrete explanatory sentence. Use the real release policy when describing available speaker materials; do not promise every upload is immediately visible to attendees. Do not imply all capabilities are included on Free.

**Communities — retain:** Churches, memorials, academic events and fundraisers. Emphasise recurring gatherings and appropriate wording for giving and message walls. Do not imply automated recurring donations merely because gatherings can recur.

**FAQ:** Preserve pricing/account questions. Add concise explanations of venue operations, badges/certificates, academic submissions and the attendee portal if those topics are absent. Align availability with actual plan/add-on rules. Add a marketplace question only after readiness is established.

## Global Constraints

- Preserve the iconic blue and existing homepage visual language.
- Five concise, manually selected hero slides; no autoplay or full-screen takeover.
- Keep pricing, currency, free-plan limits and calls to action accurate.
- Do not change backend business logic, payments, permissions, packages or dependencies as part of this homepage update.
- Do not touch Claude’s checkout, worktrees, branches or uncommitted files.
- Existing public marketing tests prohibit marketplace promotion until real venues take bookings; do not delete or evade the restriction to ship a slide.
- An implemented route or passing fixture test is not proof that a production merchant accepts bookings.
- No unsupported claims about customer counts, certifications, uptime, AI capability, booking guarantees or plan inclusions.
- No product implementation during plan review.

## Review Focus

1. Mobile: five tabs remain readable and usable without document overflow or a giant blank product frame.
2. Accessibility: keyboard selection, panel labels, hidden-panel exclusion and no-JavaScript fallback remain correct.
3. Availability: marketplace and provider claims reflect readiness, and facility management is not confused with a complete maintenance system.
4. Copy drift: rendered Blade content agrees with configuration, permissions and package entitlements.
5. Real-world operation: offline synchronisation and device notification wording do not promise behaviour that testing has not established.

## Implementation tasks

### Task 1: Validate claims and settle the marketplace wording

**Files to read:**

- `routes/web.php` — public marketplace, storefront and booking routes.
- `routes/subdomain.php` — organiser and venue workspaces.
- `app/Http/Controllers/Tenant/Venue/VenueOperationsController.php`
- `app/Http/Controllers/Tenant/Venue/VenueCalendarController.php`
- `app/Http/Controllers/Marketplace/MarketplaceListingController.php`
- `app/Http/Controllers/Marketplace/VenueBookingController.php`
- `app/Http/Controllers/Marketplace/MarketplaceQuotePublicController.php`
- `app/Models/StoreListing.php`
- `resources/js/Layouts/ConsoleLayout.jsx`
- `tests/Feature/Marketing/MarketingClaimsTest.php`
- `tests/Feature/Marketplace/UnbookableVenueTest.php`
- `tests/Feature/Tenant/VenueOperationsTest.php`
- `config/product-page.php` and package/add-on definitions.

**Produces:** Approved copy for the fifth slide, audience card and feature catalogue; a clear list of public promises allowed for this release. No new backend feature.

- [ ] Check the implemented provider/venue screens and permissions against every proposed claim; narrow provider wording if its workflow is incomplete.
- [ ] Read production catalogue availability through approved read-only access, and verify whether active, published, bookable listings belong to real participating businesses. Do not create a booking, charge money or publish listings as part of this check.
- [ ] Inspect existing booking/quotation tests and evidence from a controlled end-to-end flow. If readiness cannot be established, select the facility-operations fallback copy above and retain the marketing restriction.
- [ ] Check available device-notification evidence from the prior staff-phone test. Use “your crew sees the request” unless sound/vibration is verified for the advertised conditions.
- [ ] If marketplace promotion is justified, present the evidence and concrete wording/link change for user review before changing the restriction. Keep a test that prevents unsupported instant-booking promises.

**Verification:** Every benefit in the brief must point to an implemented screen/workflow and its applicable availability. Outstanding marketplace readiness is a release condition for discovery claims, not a reason to block accurate copy for the other four slides and facility operations.

### Task 2: Expand the accessible hero to five slides

**Modify:** `resources/views/product/landing.blade.php`

**Tests:** `resources/js/test/heroShowcase.test.js`, `tests/Feature/Marketing/LandingPageTest.php`

**Consumes:** The approved copy above and Task 1's marketplace/facility choice.

**Produces:** Explicit tab/panel pairs with IDs `hero-tab-plan`, `hero-tab-run`, `hero-tab-design`, `hero-tab-finance`, `hero-tab-venues` and corresponding `hero-panel-*` IDs. Keep the existing scoped native selection script and index 1 default.

- [ ] Extend the existing failing JS tests from three to five tabs. Exercise all tabs, both wrap directions, Home/End, single-tab-stop behaviour and matching panel visibility. Add this sequence to the keyboard test:

```js
expect(tabs).toHaveLength(5);
press(1, 'ArrowRight', 2);
press(4, 'ArrowRight', 0);
press(0, 'ArrowLeft', 4);
press(4, 'Home', 0);
press(0, 'End', 4);
```

- [ ] Extend the existing rendered-response test to expect five unique labelled panels, with only `hero-panel-run` initially unhidden; verify every `aria-controls` resolves. Its DOM assertion changes to `expect($tabs->length)->toBe(5)`. Add an explicit expected-ID check:

```php
$ids = [];
foreach ($tabs as $tab) {
    $ids[] = $tab->getAttribute('aria-controls');
}
expect($ids)->toBe([
    'hero-panel-plan', 'hero-panel-run', 'hero-panel-design',
    'hero-panel-finance', 'hero-panel-venues',
]);
```

Run the affected tests and confirm failure before changing the view.
- [ ] Add the two new compact panels and revise the existing panel copy. Keep semantic button/tab and tabpanel attributes. Use the pattern:

```html
<button type="button" class="mc-reference-tab" role="tab"
        id="hero-tab-design" aria-controls="hero-panel-design"
        aria-selected="false" tabindex="-1">Design &amp; recognise</button>
<div class="mc-product-slide" id="hero-panel-design" role="tabpanel"
     aria-labelledby="hero-tab-design" tabindex="0" hidden>
    <h2 class="mc-slide-title">Make every badge and certificate unmistakably yours.</h2>
</div>
```

The implementation must include the full badge/certificate visual and approved supporting copy, not stop at this structural example.

- [ ] Change the desktop tab grid to five columns. Below the phone breakpoint, use scoped flex/overflow styling:

```css
@media (max-width: 640px) {
    [data-hero-showcase] .mc-reference-tabs { display: flex; overflow-x: auto; }
    [data-hero-showcase] .mc-reference-tab { flex: 0 0 auto; white-space: nowrap; }
}
```

When selection changes on a phone, scroll only the tab strip horizontally to reveal the selected tab; do not scroll the document vertically or introduce motion animations. Reuse the existing overlapping-panel grid for stable frame height and keep hidden panels invisible.

- [ ] Keep the no-JavaScript overview fallback. Label the demo as illustrative and keep its decorative controls outside the tab order.
- [ ] Run JS and PHP tests, inspect the hero at 320, 375, 768 and 1440 pixels, then commit this independently reviewable change.

### Task 3: Broaden the section immediately after the hero

**Modify:** `config/product-page.php`, `resources/views/product/landing.blade.php`

**Tests:** `tests/Feature/Marketing/LandingPageTest.php`, `tests/Feature/Marketing/MarketingClaimsTest.php`

**Consumes:** Audience copy above and Task 1's provider wording.

**Produces:** One authoritative `product-page.intro` title/body/cards structure used by the rendered view. Remove the obsolete Create/Sell/Run/Settle array rather than retain unused alternative copy.

- [ ] Add this failing response test for the four audiences and the replacement of the narrow lifecycle section, using the verified venue/provider title:

```php
test('the homepage introduces the four audiences it serves', function (): void {
    $page = $this->get('/')->assertOk();
    $page->assertSee('Built for everyone behind the gathering')
        ->assertSee('Event organisers')
        ->assertSee('Door &amp; floor teams', false)
        ->assertSee('Venues &amp; service providers', false)
        ->assertSee('Attendees &amp; participants', false)
        ->assertDontSee('From first invite to final payout');
});
```

If Task 1 selects the venue-only card, assert `Venue operators` instead. Verify the test fails before editing configuration or markup.
- [ ] Update `intro.title`, `intro.body` and its four cards using the exact copy in the brief. Reuse each card's existing `kicker`, `title` and `body` fields; add `Crew`, `Venues` and `Attendees` as the short kickers, alongside `Organisers`.
- [ ] Render the configured cards rather than the view's hardcoded lifecycle array:

```blade
@foreach (config('product-page.intro.cards') as $card)
    <div class="arc-step">
        <div class="arc-n mono">{{ $card['kicker'] }}</div>
        <div class="arc-t">{{ $card['title'] }}</div>
        <div class="arc-b">{{ $card['body'] }}</div>
    </div>
@endforeach
```

- [ ] Use four/two/one columns at wide/medium/narrow widths. Preserve the existing max-width container, colours and restrained card styling.
- [ ] Verify rendered content, mobile wrapping and absence of stale unused intro copy. Run affected tests and commit.

### Task 4: Add venue operations and enrich supporting coverage

**Modify:** `resources/views/product/landing.blade.php`, `config/product-page.php`

**Tests:** `tests/Feature/Marketing/LandingPageTest.php`, `tests/Feature/Marketing/MarketingClaimsTest.php`, `tests/Feature/Marketing/PlanCatalogueConsistencyTest.php`

**Consumes:** Approved supporting-section copy and Task 1's claim decisions.

**Produces:** The page order in the brief, one new venue section and a grouped feature catalogue. Keep the pricing component and finance workflows untouched.

- [ ] Add this failing coverage test for the new venue section and the less-visible capabilities:

```php
test('the homepage explains facilities, recognition and academic coordination', function (): void {
    $page = $this->get('/')->assertOk();
    foreach ([
        'Keep every space ready for the next gathering.',
        'Academic submissions &amp; peer review',
        'Receive abstracts, assign reviewers, record decisions and export an abstract book.',
        'Sponsor commitments',
        'Certificate verification',
        'miconvener.com/my',
    ] as $copy) {
        $page->assertSee($copy, false);
    }
});
```

For the unverified-notification fallback, add a separate assertion that the response contains `your crew sees the request` and does not contain `phone buzzes`. Verify failure before editing the view/configuration.
- [ ] Add the calendar/readiness venue mockup using existing `.panel`, `.panel-head` and typography patterns. Give it illustrative status labels; do not derive it from live tenant data or expose customer details.
- [ ] Update finance and guest-assistance wording from the brief. Keep genuine ticket/payment pricing information and make no fee or payout guarantee through example numbers.
- [ ] Add a `group` field to each capability item in configuration. Keep existing titles/bodies where accurate, add missing items and render semantic group headings above the existing card components. Use this grouping shape:

```blade
@foreach (collect(config('product-page.capabilities.items'))->groupBy('group') as $group => $items)
    <h3 class="display mt-8">{{ $group }}</h3>
    <div class="cap mt-4">
        @foreach ($items as $item)
            <div>
                <h4>{{ $item['title'] }}</h4>
                <p>{{ $item['body'] }}</p>
            </div>
        @endforeach
    </div>
@endforeach
```

Keep each group's full copy within the brief's constraints; preserve readable heading levels and the existing card appearance.

- [ ] Refine community and FAQ copy without adding long new page sections. Confirm no capability is advertised as universally included when it is plan/add-on gated.
- [ ] If marketplace discovery is approved, add an actual discoverable link using the correct route and retain tests for unbookable listings and honest booking wording. Otherwise keep the homepage's marketplace navigation restriction and facility-only copy.
- [ ] Run the affected marketing tests and plan-catalogue consistency checks. Commit the supporting-section changes separately from the hero.

### Task 5: Browser review, self-review and integration handoff

**Tests:** The three marketing PHP test files above and `resources/js/test/heroShowcase.test.js`.

**Produces:** Full-page desktop/mobile previews, test evidence, a self-review report and a reviewable feature branch.

- [ ] Check all five slides at 320, 375, 768, 1024 and 1440 pixels. Record panel visibility, frame heights and horizontal document overflow. Check narrow phones with long labels and 200% zoom.
- [ ] Test keyboard-only use: tab into the selected tab, arrows/Home/End, then tab to the selected panel and onward to real links. Confirm inactive panels are not announced or focusable.
- [ ] Disable JavaScript and confirm the overview fallback and functional registration/pricing links. Check slow font loading, default selection before enhancement and browser errors.
- [ ] Review the complete page, rather than only a cropped hero. Verify the venue section has not made the page visually overwhelming and that academic/recognition details are discoverable in the catalogue.
- [ ] Check every added navigation/CTA link through Laravel Boost's absolute-URL tool and its actual route. Preserve the existing pricing component and signup plan values.
- [ ] Run the minimum affected suites with the dedicated disposable test database; never use the QA/normal application database with destructive test reset behaviour:

```bash
DB_DATABASE=miconvener_testing_badge_editor DB_HOST=/tmp "/Users/selase/Library/Application Support/Herd/bin/php85" artisan test --compact tests/Feature/Marketing/LandingPageTest.php tests/Feature/Marketing/MarketingClaimsTest.php tests/Feature/Marketing/PlanCatalogueConsistencyTest.php
PATH=/Users/selase/.nvm/versions/node/v24.16.0/bin:$PATH npm test -- resources/js/test/heroShowcase.test.js
"/Users/selase/Library/Application Support/Herd/bin/php85" vendor/bin/pint --dirty
git diff --check
```

Run the new/affected tests against the final state. These Blade/scoped-script changes do not require a frontend rebuild; rebuild only if implementation actually changes bundled inputs.

- [ ] Perform the mandatory self-review: touchpoints, claim accuracy, mobile/a11y gaps, dependencies, dead code and test coverage. Report unresolved readiness gaps honestly.
- [ ] Show the full-page preview for user review before integration. Merge/push only within the user's confirmed integration scope. Preserve the current preview checkout until its changes are safely integrated and no longer needed.

## Worktree cleanup audit

Checked on 10 October 2026. All listed worktrees have clean tracked/untracked status.

| Checkout | Current state | Action |
| --- | --- | --- |
| `.worktrees/artifact-completion` | Repurposed to `codex/homepage-hero-showcase`; contains unmerged commit `b7ba753` and serves the current preview. | Keep. Its old artifact work is merged, but its present content is not. |
| `.worktrees/artifact-design` | Previously protected Claude checkout; its committed history is included in main. | Keep under the existing instruction to leave Claude's worktrees untouched. |
| `/Users/selase/Sites/miconvener.worktrees/audit-work-review` | Previously protected audit/Claude checkout; its committed history is included in main. | Keep under the existing protection instruction. |

No remaining worktree created for this Codex work meets both conditions: safely merged current content and no continuing preview/work need. No worktree was deleted. The inactive, merged `codex/artifact-completion` branch is not a checkout; branch deletion is outside this cleanup request.

## Decisions for review

1. Confirm the proposed page order, five slide headlines and expanded hero supporting copy.
2. Confirm replacing the four lifecycle steps with the four-audience section.
3. Confirm adding one venue/facility section and using the feature catalogue for the remaining breadth.
4. Review marketplace readiness evidence before choosing discovery/storefront promotion; otherwise use the defined facility-only fallback.

**Recommended execution after approval:** Implement inline in the current isolated homepage checkout, task by task, with tests and a final self-review. Do not start product implementation merely because this draft has been written.
