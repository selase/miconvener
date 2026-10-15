# Attendee Portal Host Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Require every `/my/*` request to be addressed to the same tenant named by its MiConvener subdomain, without changing existing subdomain routing or storing resolver provenance in shared application state.

**Architecture:** Add a small, stateless `TenantHostMatcher` that identifies the canonical `{tenant.slug}.{SESSION_DOMAIN}` host. Global request initialization clears singleton tenant state, and an early `/my/*` host guard rejects invalid host shapes before route matching or tenant-aware middleware; this is also where one terminal DNS dot is normalized. `EnsureTenantFromHost` then checks the resolved tenant against the current host as defense in depth. `TenantResolver` carries no source metadata. All current and future `/my/*` routes remain inside one guarded route group. External custom-domain portal routing is explicitly deferred and returns 404 until it receives a separate routing and ownership-verification design.

**Tech Stack:** PHP 8.5, Laravel 13 with the existing Laravel 10 directory layout, PostgreSQL, Pest 5, Laravel Pint, Larastan/PHPStan.

**Spec:** `docs/superpowers/specs/2026-09-22-attendee-portal-design.md`, especially sections 3, 6.2, 6.3, 11 and 14.

## Global Constraints

- Preserve working tenant subdomains exactly: `{tenant.slug}.{SESSION_DOMAIN}` locally and in production.
- The portal must never accept the apex domain, a reserved platform subdomain, a session-selected tenant, an `X-Tenant` tenant, or a route-selected tenant as a substitute for the tenant subdomain.
- Return 404 for a host-boundary refusal; do not reveal whether the supplied tenant exists.
- Do not add dependencies or change the application's directory structure.
- Do not route `/my` on external custom domains in this plan; that requires separate route registration, validation, and ownership verification.
- Keep attendee verification tenant-scoped and continue reading the verified address from the session through `EnsureAttendeeVerified`.
- Follow TDD: each behavior change begins with a failing Pest test.

## Review Focus

- A valid tenant request followed by an unresolved `www` request in the same application instance must not reuse the first request's tenant.
- Uppercase or trailing-dot host spelling must normalize to the same DNS hostname without accepting a different suffix.
- `www`, the apex domain, nested labels, and suffix-confusion hosts must return 404 even if session or `X-Tenant` selects a real tenant.
- An active external `custom_domain` must not make `/my` routable or pass the host matcher in this plan.
- Every current and newly added `public.my*` route must carry `EnsureTenantFromHost` before any attendee-history middleware or controller.

---

### Task 1: Pin the exact host invariant with focused tests

**Files:**
- Create: `tests/Unit/Tenancy/TenantHostMatcherTest.php`
- Modify: `tests/Feature/Events/AttendeePortalHostTest.php`

**Interfaces:**
- Consumes: `Tenant` with a validated lowercase `slug`; `config('session.domain')` containing the platform base domain, with or without a leading dot.
- Produces: executable behavior requirements for `TenantHostMatcher::matches(Tenant $tenant, string $host): bool` and the `/my/*` route boundary.

- [ ] **Step 1: Write unit tests for the canonical tenant host**

Create `tests/Unit/Tenancy/TenantHostMatcherTest.php` with these cases. The class does not exist yet, so the test must fail first.

```php
<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantHostMatcher;

beforeEach(function (): void {
    config(['session.domain' => '.miconvener.test']);
    $this->matcher = new TenantHostMatcher();
    $this->tenant = new Tenant(['slug' => 'acme']);
});

test('the exact tenant subdomain names the tenant', function (): void {
    expect($this->matcher->matches($this->tenant, 'acme.miconvener.test'))->toBeTrue();
});

test('dns host spelling is normalized', function (): void {
    expect($this->matcher->matches($this->tenant, 'ACME.MICONVENER.TEST.'))->toBeTrue();
});

test('hosts that do not exactly name the tenant are refused', function (string $host): void {
    expect($this->matcher->matches($this->tenant, $host))->toBeFalse();
})->with([
    'apex' => 'miconvener.test',
    'reserved platform host' => 'www.miconvener.test',
    'another tenant' => 'other.miconvener.test',
    'nested label' => 'acme.extra.miconvener.test',
    'suffix confusion' => 'acme.miconvener.test.example.com',
    'external custom domain' => 'events.acme.example',
]);

test('an empty platform domain fails closed', function (): void {
    config(['session.domain' => null]);

    expect($this->matcher->matches($this->tenant, 'acme.miconvener.test'))->toBeFalse();
});
```

- [ ] **Step 2: Run the unit test and verify the missing class failure**

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Unit/Tenancy/TenantHostMatcherTest.php
```

Expected: FAIL because `App\Services\Tenancy\TenantHostMatcher` does not exist.

- [ ] **Step 3: Add a regression for stale cross-request context**

Append this test to `tests/Feature/Events/AttendeePortalHostTest.php`:

```php
test('an unresolved host cannot reuse a tenant from an earlier request', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'first-host', 'isolation_mode' => 'shared']);
    $tenantHost = 'first-host.'.baseDomain();
    $reservedHost = 'www.'.baseDomain();

    $this->get("http://{$tenantHost}/my", ['HTTP_HOST' => $tenantHost])
        ->assertOk();

    $this->get("http://{$reservedHost}/my", ['HTTP_HOST' => $reservedHost])
        ->assertNotFound();
});
```

This must exercise two requests in one test application. Against the interrupted provenance implementation, the second request is expected to expose the stale-context defect.

- [ ] **Step 4: Replace the resolver-only custom-domain assertion with the actual supported contract**

Replace `a verified custom domain counts as coming from the host` in `AttendeePortalHostTest.php` with:

```php
test('an external custom domain does not claim attendee portal support', function (): void {
    Tenant::factory()->create([
        'slug' => 'branded',
        'isolation_mode' => 'shared',
        'custom_domain' => 'events.brand.test',
        'custom_domain_status' => 'active',
        'custom_domain_verified_at' => now(),
    ]);

    $this->get('https://events.brand.test/my', ['HTTP_HOST' => 'events.brand.test'])
        ->assertNotFound();
});
```

Remove the now-unused imports of `TenantResolver` and `Illuminate\Http\Request` from that test file.

- [ ] **Step 5: Run the focused feature test and capture the intended failure**

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Feature/Events/AttendeePortalHostTest.php
```

Expected before implementation: the stale-context regression fails because the singleton `TenantContext` retains the earlier subdomain source; the existing header/session refusal tests continue to pass.

### Task 2: Replace resolver provenance with direct host matching

**Files:**
- Create: `app/Services/Tenancy/TenantHostMatcher.php`
- Modify: `app/Http/Middleware/EnsureTenantFromHost.php`
- Modify: `app/Http/Kernel.php`
- Modify: `app/Services/Tenancy/TenantContext.php`
- Modify: `app/Services/Tenancy/TenantResolver.php`
- Modify: `app/Http/Middleware/ResolveTenant.php`
- Test: `tests/Unit/Tenancy/TenantHostMatcherTest.php`
- Test: `tests/Feature/Events/AttendeePortalHostTest.php`

**Interfaces:**
- Consumes: `TenantContext::getTenant(): ?Tenant`, `Request::getHost(): string`, `config('session.domain')`.
- Produces: `TenantHostMatcher::matches(Tenant $tenant, string $host): bool`; `EnsureTenantFromHost` that fails closed unless that method returns true.

- [ ] **Step 1: Create the pure matcher**

Create `app/Services/Tenancy/TenantHostMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;

final class TenantHostMatcher
{
    public function matches(Tenant $tenant, string $host): bool
    {
        $baseDomain = $this->normalize((string) config('session.domain'));

        if ($baseDomain === '') {
            return false;
        }

        $expectedHost = $this->normalize("{$tenant->slug}.{$baseDomain}");

        return $this->normalize($host) === $expectedHost;
    }

    private function normalize(string $host): string
    {
        return mb_strtolower(mb_trim($host, " \n\r\t\v\0."));
    }
}
```

The comparison is exact after DNS-safe normalization. It deliberately does not inspect `custom_domain`.

- [ ] **Step 2: Run the matcher unit tests**

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Unit/Tenancy/TenantHostMatcherTest.php
```

Expected: PASS.

- [ ] **Step 3: Rewrite the guard to check the current request directly**

Change `EnsureTenantFromHost` to inject `TenantHostMatcher` and replace the source-list check:

```php
final readonly class EnsureTenantFromHost
{
    public function __construct(
        private TenantContext $context,
        private TenantHostMatcher $hostMatcher,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->getTenant();

        if ($tenant === null || ! $this->hostMatcher->matches($tenant, $request->getHost())) {
            abort(404);
        }

        return $next($request);
    }
}
```

Update its class-level PHPDoc to state that Plan B accepts the existing MiConvener tenant subdomain and intentionally does not claim external custom-domain routing.

- [ ] **Step 4: Remove source metadata from `TenantContext`**

Keep the middleware alias in `app/Http/Kernel.php`:

```php
'tenant_from_host' => Middleware\EnsureTenantFromHost::class,
```

Then restore the existing `TenantContext` public interface:

```php
public function setTenant(Tenant $tenant): void
{
    $this->tenant = $tenant;
    $this->activeTenantId = (string) $tenant->id;
}
```

Delete `$source`, the optional `$source` argument, and `tenantSource()`.

- [ ] **Step 5: Remove the mutable `lastSource()` side channel from `TenantResolver`**

Delete the five `SOURCE_*` constants, `$lastSource`, `lastSource()`, the reset at the beginning of `findTenant()`, and every assignment before returning a tenant. Do not alter the existing resolution precedence or tenant queries.

- [ ] **Step 6: Restore the normal context assignment in `ResolveTenant`**

Replace the provenance-aware call and its comment with:

```php
$this->context->setTenant($tenant);
```

- [ ] **Step 7: Run the focused boundary and tenancy suites**

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact \
  tests/Unit/Tenancy/TenantHostMatcherTest.php \
  tests/Feature/Events/AttendeePortalHostTest.php \
  tests/Feature/Tenancy
```

Expected: all tests pass, including the sequential-request regression, without changing normal subdomain resolution.

- [ ] **Step 8: Commit the exact host boundary**

```bash
git add app/Services/Tenancy/TenantHostMatcher.php \
  app/Http/Middleware/EnsureTenantFromHost.php \
  app/Http/Kernel.php \
  app/Services/Tenancy/TenantContext.php \
  app/Services/Tenancy/TenantResolver.php \
  app/Http/Middleware/ResolveTenant.php \
  tests/Unit/Tenancy/TenantHostMatcherTest.php \
  tests/Feature/Events/AttendeePortalHostTest.php
git commit -m "fix(portal): require the tenant subdomain for attendee history"
```

### Task 3: Make the `/my` route boundary structural

**Files:**
- Modify: `routes/subdomain.php`
- Create: `tests/Feature/Events/AttendeePortalRouteSecurityTest.php`

**Interfaces:**
- Consumes: middleware alias `tenant_from_host`; current route names `public.my`, `public.my.verify.send`, `public.my.verify.confirm`, `public.my.verify.forget`, and `public.my.session`.
- Produces: one `/my` prefix group whose routes all carry `EnsureTenantFromHost`; a regression test that fails when any `public.my*` route escapes the guard.

- [ ] **Step 1: Write the route middleware regression**

Create `tests/Feature/Events/AttendeePortalRouteSecurityTest.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAttendeeVerified;
use App\Http\Middleware\EnsureTenantFromHost;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

test('every attendee identity route enforces the tenant host boundary', function (): void {
    $portalRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), 'public.my'));

    expect($portalRoutes)->not->toBeEmpty();

    $portalRoutes->each(function (LaravelRoute $route): void {
        $middleware = app('router')->gatherRouteMiddleware($route);

        expect($middleware)->toContain(EnsureTenantFromHost::class);

        if (in_array(EnsureAttendeeVerified::class, $middleware, true)) {
            $hostGuardPosition = array_search(EnsureTenantFromHost::class, $middleware, true);
            $verificationPosition = array_search(EnsureAttendeeVerified::class, $middleware, true);

            expect($hostGuardPosition)->toBeInt()
                ->and($verificationPosition)->toBeInt();
            expect($hostGuardPosition)->toBeLessThan($verificationPosition);
        }
    });
});
```

- [ ] **Step 2: Run the route-security test against the current aliases**

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact tests/Feature/Events/AttendeePortalRouteSecurityTest.php
```

Expected: PASS for the five current routes. This characterizes the boundary before restructuring it.

- [ ] **Step 3: Consolidate the URI prefix without renaming routes**

In `routes/subdomain.php`, replace the repeated `/my` URI strings with:

```php
Route::prefix('my')->middleware('tenant_from_host')->group(function (): void {
    Route::get('/', [App\Http\Controllers\Public\AttendeeAccessController::class, 'page'])->name('public.my');
    Route::post('/verify/send', [App\Http\Controllers\Public\AttendeeAccessController::class, 'send'])->middleware('throttle:public-registration')->name('public.my.verify.send');
    Route::post('/verify/confirm', [App\Http\Controllers\Public\AttendeeAccessController::class, 'confirm'])->middleware('throttle:public-registration')->name('public.my.verify.confirm');
    Route::post('/verify/forget', [App\Http\Controllers\Public\AttendeeAccessController::class, 'forget'])->name('public.my.verify.forget');
    Route::get('/session', [App\Http\Controllers\Public\AttendeeAccessController::class, 'session'])->middleware('attendee_verified')->name('public.my.session');
});
```

Future Plan B endpoints (`events`, `certificates`, `abstracts`, `attendance`) must be added inside this group.

- [ ] **Step 4: Verify route names, domains, and middleware**

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan route:list --path=my --json
```

Expected: the five names are unchanged, their domain remains `{subdomain}.<SESSION_DOMAIN>`, and each carries `EnsureTenantFromHost`. `public.my.session` must also carry `EnsureAttendeeVerified` after the host guard.

- [ ] **Step 5: Run the route and portal suites**

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact \
  tests/Feature/Events/AttendeePortalRouteSecurityTest.php \
  tests/Feature/Events/AttendeePortalHostTest.php \
  tests/Feature/Events/AttendeeVerificationTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit the structural route guard**

```bash
git add routes/subdomain.php tests/Feature/Events/AttendeePortalRouteSecurityTest.php
git commit -m "test(portal): keep attendee identity routes behind the host guard"
```

### Task 4: Verify the harmonized boundary before Plan B

**Files:**
- Modify if formatting requires it: files changed in Tasks 1–3
- Verify: `docs/superpowers/specs/2026-09-22-attendee-portal-design.md`

**Interfaces:**
- Consumes: completed exact-host guard and structural route group.
- Produces: a clean, reviewed baseline on which Plan B can safely add attendee-history endpoints.

- [ ] **Step 1: Run Pint on changed PHP files**

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/pint --dirty
```

Expected: formatter completes successfully; inspect any modifications.

- [ ] **Step 2: Run static analysis**

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" vendor/bin/phpstan analyse --no-progress --memory-limit=2G
```

Expected: zero errors.

- [ ] **Step 3: Run the full test suite once on the final tree**

Run:

```bash
"/Users/selase/Library/Application Support/Herd/bin/php84" artisan test --compact
```

Expected: all tests pass. Do not rely only on the interrupted Claude session's earlier 1,133-test result because this plan deliberately changes that implementation.

- [ ] **Step 4: Perform the mandatory self-review**

Ask: “Is there anything I have overlooked? Is the solution optimal? Let me take a step back.” Confirm:

- No resolver-source constants, `lastSource()`, or `tenantSource()` remain.
- Standard subdomain routing and all existing route names are unchanged.
- `/my` on `www`, the apex host, session/header-only selection, and an external custom domain returns 404.
- The second-request regression proves shared state cannot authorize a host.
- Every `public.my*` route is structurally guarded.
- No custom-domain support is claimed in code comments, tests, or the attendee-portal spec.

- [ ] **Step 5: Review the final diff and preserve the local planning artifacts**

Run:

```bash
git diff --check
git status --short
git diff --stat
```

The repository intentionally ignores `/docs`, so the harmonized spec and this plan are local
execution artifacts rather than commit candidates. Do not force-add them unless the owner
separately decides that project documentation should become versioned.

After these tasks pass, write the separate Plan B implementation plan for `/my/events`, `/my/certificates`, `/my/abstracts`, `/my/attendance`, and the material-release policy. That plan must add each endpoint inside the guarded `/my` prefix group established here.

### Final review amendment

The fresh-context review found that route middleware runs after the global `web` group, so an invalid
portal host could receive tenant status, membership, or usage responses before `EnsureTenantFromHost`.
It also found that Laravel domain matching rejected a trailing-dot host before the matcher ran. The
final fix adds global `ResetTenantContext` and `GuardAttendeePortalHost` middleware immediately after
trusted-proxy processing, extends `TenantHostMatcher` with canonical label extraction, and retains the
route guard as defense in depth. HTTP regressions pin neutral 404s before status/membership/usage,
no stale context or metering side effects, and successful uppercase/trailing-dot routing.
