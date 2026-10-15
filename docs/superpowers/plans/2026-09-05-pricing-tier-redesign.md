# Pricing / Package Tier Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the events platform four real subscription tiers (Free/Starter/Growth/Enterprise) that gate usage volume and features, plus a per-tier commission default on paid tickets — built entirely on the app's existing, already-proven `TenantFeature`/`Tenant::canUse()`/`syncFeaturesFromPackage()` package-assignment machinery, with zero new parallel systems.

**Architecture:** A new `EventPackageSeeder` reseeds `Package`/`Feature`/`package_features` with event-tier data, replacing the defunct meeting-product seed. Assigning a tenant to a package (already-existing `SubscriptionProvisioningService`/`syncFeaturesFromPackage()`) writes `TenantFeature` rows exactly as it does today for LLM quota. Boolean gates read via `Facades\Feature::enabled()`; monthly-reset volume gates reuse `Tenant::canUse()`/`recordUsage()`; brand-new concurrent-count gates (events-in-flight, team seats) use a new `Tenant::featureLimitValue()` accessor compared against a live COUNT query at the point of creation.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, existing `TenantFeature`/`Tenant`/`Package`/`Feature`/`SubscriptionProvisioningService` models/services.

**Spec:** `docs/superpowers/specs/2026-09-05-pricing-tier-redesign-design.md`

## Global Constraints

- Reuse `TenantFeature` + `Tenant::canUse()`/`recordUsage()` (System A) for every new gate — never extend `UsageLimit`/`UsageEvent`/`PricingService` (System B) or reactivate `EnsureFeatureEnabled`/`EnsureFeatureLimit` middleware (spec §5).
- Never write a package default into `tenants.platform_fee_percentage` — that column stays the manual-override-only source; the package default is read live via `$event->tenant->package` (spec §7).
- `-1` (or any negative) in a `package_features.value` / `TenantFeature.meta.value` means unlimited — matches the existing convention already used by `Tenant::canUse()` and the old seeders.
- SMS credits, white-label portals, SSO, and API access get their Feature rows seeded (so the data model is complete) but **no enforcement code** — those features don't exist yet (spec §3). Do not write gating logic for them.
- All new PHP files: `declare(strict_types=1);`, explicit return types, constructor property promotion where applicable (per this repo's CLAUDE.md).
- New tests are Pest (`php artisan make:test --pest`), run via `php artisan test --compact --filter=<Name>`.

---

### Task 1: `default_platform_fee_percentage` column on `packages`

**Files:**
- Create: `database/migrations/2026_09_05_000001_add_default_platform_fee_percentage_to_packages_table.php`
- Modify: `app/Models/Package.php`
- Test: `tests/Feature/Billing/PackageDefaultFeeTest.php`

**Interfaces:**
- Produces: `Package.default_platform_fee_percentage` (nullable decimal), read by Task 3.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Package;

test('a package stores a nullable default platform fee percentage', function () {
    $package = Package::factory()->create(['default_platform_fee_percentage' => 3.5]);

    expect($package->fresh()->default_platform_fee_percentage)->toEqual(3.5);

    $custom = Package::factory()->create(['default_platform_fee_percentage' => null]);
    expect($custom->fresh()->default_platform_fee_percentage)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PackageDefaultFeeTest`
Expected: FAIL — "Unknown column 'default_platform_fee_percentage'"

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->decimal('default_platform_fee_percentage', 5, 2)->nullable()->after('markup_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn('default_platform_fee_percentage');
        });
    }
};
```

Run: `php artisan migrate`

- [ ] **Step 4: Add the column to `Package`'s fillable/casts**

In `app/Models/Package.php`, add to `$fillable` (after `'markup_percentage'`):

```php
        'default_platform_fee_percentage',
```

Add to `$casts`:

```php
        'default_platform_fee_percentage' => 'decimal:2',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=PackageDefaultFeeTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_05_000001_add_default_platform_fee_percentage_to_packages_table.php app/Models/Package.php tests/Feature/Billing/PackageDefaultFeeTest.php
git commit -m "feat: add default_platform_fee_percentage column to packages"
```

---

### Task 2: `Tenant::featureLimitValue()` accessor

**Files:**
- Modify: `app/Models/Tenant.php` (add method near `canUse()`/`recordUsage()`, around line 305)
- Test: `tests/Unit/Models/TenantFeatureLimitValueTest.php`

**Interfaces:**
- Consumes: existing `$this->features()` relation (returns `TenantFeature` rows, already used by `canUse()`).
- Produces: `Tenant::featureLimitValue(string $featureSlug): ?int` — returns the configured limit, or `null` when the feature is missing/disabled/not a limit type/negative (unlimited). Used by Task 5 and Task 6.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantFeature;

test('featureLimitValue returns the configured limit for an enabled limit feature', function () {
    $tenant = Tenant::factory()->create();

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'events_in_flight',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 3],
    ]);

    expect($tenant->featureLimitValue('events_in_flight'))->toBe(3);
});

test('featureLimitValue returns null when the feature is disabled', function () {
    $tenant = Tenant::factory()->create();

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'events_in_flight',
        'enabled' => false,
        'meta' => ['type' => 'limit', 'value' => 3],
    ]);

    expect($tenant->featureLimitValue('events_in_flight'))->toBeNull();
});

test('featureLimitValue returns null when the feature does not exist for the tenant', function () {
    $tenant = Tenant::factory()->create();

    expect($tenant->featureLimitValue('events_in_flight'))->toBeNull();
});

test('featureLimitValue returns null (unlimited) when the stored value is negative', function () {
    $tenant = Tenant::factory()->create();

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'events_in_flight',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => -1],
    ]);

    expect($tenant->featureLimitValue('events_in_flight'))->toBeNull();
});

test('featureLimitValue returns null for a boolean-type feature', function () {
    $tenant = Tenant::factory()->create();

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'custom-domains',
        'enabled' => true,
        'meta' => ['type' => 'boolean', 'value' => true],
    ]);

    expect($tenant->featureLimitValue('custom-domains'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=TenantFeatureLimitValueTest`
Expected: FAIL — "Call to undefined method App\Models\Tenant::featureLimitValue()"

- [ ] **Step 3: Write the implementation**

In `app/Models/Tenant.php`, immediately after the closing brace of `canUse()` (the method ending at line 305), add:

```php
    /**
     * Get the configured numeric limit for a limit-type feature, or null
     * when the feature is missing, disabled, not a limit type, or set to
     * unlimited (a negative stored value). Unlike canUse()/recordUsage(),
     * this does not consult TenantFeatureUsage — it's for gates that
     * compare against a live COUNT query instead of a running counter
     * (e.g. concurrent events-in-flight, current team seat count).
     */
    public function featureLimitValue(string $featureSlug): ?int
    {
        $feature = $this->features()->where('feature_key', $featureSlug)->first();

        if (! $feature || ! $feature->enabled) {
            return null;
        }

        $meta = $feature->meta ?? [];
        if (($meta['type'] ?? null) !== 'limit') {
            return null;
        }

        $limit = (int) ($meta['value'] ?? 0);

        return $limit < 0 ? null : $limit;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=TenantFeatureLimitValueTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/Tenant.php tests/Unit/Models/TenantFeatureLimitValueTest.php
git commit -m "feat: add Tenant::featureLimitValue accessor for concurrent-count gates"
```

---

### Task 3: Extend `Event::effectivePlatformFeePercentage()` with the package-default rung

**Files:**
- Modify: `app/Models/Event.php:175-180`
- Test: `tests/Unit/Models/EventEffectivePlatformFeePercentageTest.php`

**Interfaces:**
- Consumes: `Package.default_platform_fee_percentage` (Task 1), `Tenant::package()` (existing relation).
- Produces: no new public API — `effectivePlatformFeePercentage()`'s existing signature and callers (§7, `EventFinanceController`, checkout flow) are unchanged.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Package;
use App\Models\Tenant;

test('effectivePlatformFeePercentage falls back to the tenant\'s package default', function () {
    $package = Package::factory()->create(['default_platform_fee_percentage' => 2.0]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id, 'platform_fee_percentage' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => null]);

    expect($event->effectivePlatformFeePercentage())->toEqual(2.0);
});

test('a tenant manual override still wins over the package default', function () {
    $package = Package::factory()->create(['default_platform_fee_percentage' => 2.0]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id, 'platform_fee_percentage' => 4.25]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => null]);

    expect($event->effectivePlatformFeePercentage())->toEqual(4.25);
});

test('an event-level override still wins over everything', function () {
    $package = Package::factory()->create(['default_platform_fee_percentage' => 2.0]);
    $tenant = Tenant::factory()->create(['package_id' => $package->id, 'platform_fee_percentage' => 4.25]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => 1.0]);

    expect($event->effectivePlatformFeePercentage())->toEqual(1.0);
});

test('falls back to the global config default when no package default is set', function () {
    config(['services.platform.default_fee_percentage' => 6.5]);
    $tenant = Tenant::factory()->create(['package_id' => null, 'platform_fee_percentage' => null]);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => null]);

    expect($event->effectivePlatformFeePercentage())->toEqual(6.5);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EventEffectivePlatformFeePercentageTest`
Expected: FAIL on the first two tests (package default not consulted; currently falls straight through to global config).

- [ ] **Step 3: Update the implementation**

In `app/Models/Event.php`, replace:

```php
    public function effectivePlatformFeePercentage(): float
    {
        return (float) ($this->platform_fee_percentage
            ?? $this->tenant?->platform_fee_percentage
            ?? config('services.platform.default_fee_percentage'));
    }
```

with:

```php
    public function effectivePlatformFeePercentage(): float
    {
        return (float) ($this->platform_fee_percentage
            ?? $this->tenant?->platform_fee_percentage
            ?? $this->tenant?->package?->default_platform_fee_percentage
            ?? config('services.platform.default_fee_percentage'));
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=EventEffectivePlatformFeePercentageTest`
Expected: PASS (all four)

- [ ] **Step 5: Commit**

```bash
git add app/Models/Event.php tests/Unit/Models/EventEffectivePlatformFeePercentageTest.php
git commit -m "feat: fall back to the tenant's package default platform fee"
```

---

### Task 4: `EventPackageSeeder` — the new Free/Starter/Growth/Enterprise catalog

**Files:**
- Create: `database/seeders/EventPackageSeeder.php`
- Test: `tests/Feature/Billing/EventPackageSeederTest.php`

**Interfaces:**
- Produces: four `Package` rows (slugs `free`, `starter`, `growth`, `enterprise`) each with a synced `features()` pivot, plus the Feature catalog rows listed below. Consumed by Task 5 (`DatabaseSeeder` wiring) and by `syncFeaturesFromPackage()` (existing, unmodified) whenever a tenant is assigned one of these packages.

Feature catalog (slug → type):

| slug | type |
|---|---|
| `events_in_flight` | limit |
| `event_registrations` | limit |
| `team_seats` | limit |
| `email_credits` | limit |
| `sms_credits` | limit (reserved, no enforcement — spec §3) |
| `remove_platform_branding` | boolean |
| `custom-domains` | boolean (reuses the existing live slug) |
| `csv_settlement_export` | boolean |
| `white_label` | boolean (reserved, no enforcement — spec §3) |
| `sso` | boolean (reserved, no enforcement — spec §3) |
| `api_access` | boolean (reserved, no enforcement — spec §3) |

Package feature values (per spec §4; `-1` = unlimited):

| feature | Free | Starter | Growth | Enterprise |
|---|---|---|---|---|
| events_in_flight | 1 | 3 | 15 | -1 |
| event_registrations | 100 | 500 | 3000 | -1 |
| team_seats | 1 | 3 | 10 | -1 |
| email_credits | 200 | 1000 | 10000 | -1 |
| sms_credits | 0 | 100 | 1000 | -1 |
| remove_platform_branding | false | false | true | true |
| custom-domains | false | false | true | true |
| csv_settlement_export | false | true | true | true |
| white_label | false | false | false | true |
| sso | false | false | false | true |
| api_access | false | false | false | true |

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Support\Facades\Artisan;

test('EventPackageSeeder creates exactly four packages with the correct feature values', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EventPackageSeeder']);

    expect(Package::count())->toBe(4);

    $free = Package::where('slug', 'free')->firstOrFail();
    expect($free->is_free)->toBeTrue();
    expect((float) $free->price)->toEqual(0.0);

    $eventsInFlight = $free->features()->where('slug', 'events_in_flight')->first();
    expect($eventsInFlight->pivot->value)->toEqual(1);

    $growth = Package::where('slug', 'growth')->firstOrFail();
    expect((float) $growth->default_platform_fee_percentage)->toEqual(2.0);
    $customDomains = $growth->features()->where('slug', 'custom-domains')->first();
    expect($customDomains->pivot->value)->toBeTrue();

    $enterprise = Package::where('slug', 'enterprise')->firstOrFail();
    $eventsUnlimited = $enterprise->features()->where('slug', 'events_in_flight')->first();
    expect($eventsUnlimited->pivot->value)->toEqual(-1);
    expect($enterprise->default_platform_fee_percentage)->toBeNull();
});

test('EventPackageSeeder creates all eleven catalog features', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EventPackageSeeder']);

    expect(Feature::count())->toBe(11);
    expect(Feature::where('slug', 'events_in_flight')->where('type', 'limit')->exists())->toBeTrue();
    expect(Feature::where('slug', 'custom-domains')->where('type', 'boolean')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EventPackageSeederTest`
Expected: FAIL — "Class 'Database\Seeders\EventPackageSeeder' not found"

- [ ] **Step 3: Write the seeder**

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Database\Seeder;

final class EventPackageSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, type: string, description: string}>
     */
    private array $features = [
        'events_in_flight' => ['name' => 'Concurrent Live Events', 'type' => 'limit', 'description' => 'Maximum non-archived events at a time.'],
        'event_registrations' => ['name' => 'Registrations Per Month', 'type' => 'limit', 'description' => 'Maximum confirmed registrations across all events per month.'],
        'team_seats' => ['name' => 'Team Seats', 'type' => 'limit', 'description' => 'Maximum team members for this organization.'],
        'email_credits' => ['name' => 'Email Credits Per Month', 'type' => 'limit', 'description' => 'Maximum event-related emails sent per month.'],
        'sms_credits' => ['name' => 'SMS Credits Per Month', 'type' => 'limit', 'description' => 'Maximum SMS messages sent per month. Reserved: SMS sending does not exist yet.'],
        'remove_platform_branding' => ['name' => 'Remove Platform Branding', 'type' => 'boolean', 'description' => 'Hide platform branding on public event pages and emails.'],
        'custom-domains' => ['name' => 'Custom Domains', 'type' => 'boolean', 'description' => 'Use your own domain for your workspace.'],
        'csv_settlement_export' => ['name' => 'CSV Settlement Export', 'type' => 'boolean', 'description' => 'Export the settlement statement as CSV.'],
        'white_label' => ['name' => 'White Label', 'type' => 'boolean', 'description' => 'Reserved: full custom branding on speaker/participant portals. Not enforced yet — portals are static previews only.'],
        'sso' => ['name' => 'SSO Integration', 'type' => 'boolean', 'description' => 'Reserved: Single Sign-On. Not enforced yet — no SAML/OIDC integration exists.'],
        'api_access' => ['name' => 'API Access', 'type' => 'boolean', 'description' => 'Reserved: self-service webhook/API management. Not enforced yet — no tenant-facing management UI exists.'],
    ];

    /**
     * @var array<string, array{name: string, description: string, monthly: float, yearly: float, sort: int, is_free: bool, default_fee: float|null, features: array<string, int|bool>}>
     */
    private array $packages = [
        'free' => [
            'name' => 'Free',
            'description' => 'Get started with a single event.',
            'monthly' => 0,
            'yearly' => 0,
            'sort' => 0,
            'is_free' => true,
            'default_fee' => 5.0,
            'features' => [
                'events_in_flight' => 1,
                'event_registrations' => 100,
                'team_seats' => 1,
                'email_credits' => 200,
                'sms_credits' => 0,
                'remove_platform_branding' => false,
                'custom-domains' => false,
                'csv_settlement_export' => false,
                'white_label' => false,
                'sso' => false,
                'api_access' => false,
            ],
        ],
        'starter' => [
            'name' => 'Starter',
            'description' => 'For teams running a few events a month.',
            'monthly' => 29,
            'yearly' => 264,
            'sort' => 1,
            'is_free' => false,
            'default_fee' => 3.5,
            'features' => [
                'events_in_flight' => 3,
                'event_registrations' => 500,
                'team_seats' => 3,
                'email_credits' => 1000,
                'sms_credits' => 100,
                'remove_platform_branding' => false,
                'custom-domains' => false,
                'csv_settlement_export' => true,
                'white_label' => false,
                'sso' => false,
                'api_access' => false,
            ],
        ],
        'growth' => [
            'name' => 'Growth',
            'description' => 'For organizations running events at scale.',
            'monthly' => 99,
            'yearly' => 900,
            'sort' => 2,
            'is_free' => false,
            'default_fee' => 2.0,
            'features' => [
                'events_in_flight' => 15,
                'event_registrations' => 3000,
                'team_seats' => 10,
                'email_credits' => 10000,
                'sms_credits' => 1000,
                'remove_platform_branding' => true,
                'custom-domains' => true,
                'csv_settlement_export' => true,
                'white_label' => false,
                'sso' => false,
                'api_access' => false,
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'Custom limits and negotiated commission for large organizations.',
            'monthly' => 0,
            'yearly' => 0,
            'sort' => 3,
            'is_free' => false,
            'default_fee' => null,
            'features' => [
                'events_in_flight' => -1,
                'event_registrations' => -1,
                'team_seats' => -1,
                'email_credits' => -1,
                'sms_credits' => -1,
                'remove_platform_branding' => true,
                'custom-domains' => true,
                'csv_settlement_export' => true,
                'white_label' => true,
                'sso' => true,
                'api_access' => true,
            ],
        ],
    ];

    public function run(): void
    {
        $this->seedFeatures();
        $this->seedPackages();
    }

    private function seedFeatures(): void
    {
        foreach ($this->features as $slug => $data) {
            Feature::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'description' => $data['description'],
                ]
            );
        }

        $this->command?->info('Seeded '.count($this->features).' event-tier features.');
    }

    private function seedPackages(): void
    {
        foreach ($this->packages as $slug => $config) {
            $package = Package::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $config['name'],
                    'description' => $config['description'],
                    'price' => $config['monthly'],
                    'yearly_price' => $config['yearly'],
                    'interval' => 'month',
                    'billing_model' => Package::BILLING_MODEL_FLAT_RATE,
                    'is_active' => true,
                    'is_free' => $config['is_free'],
                    'sort_order' => $config['sort'],
                    'default_platform_fee_percentage' => $config['default_fee'],
                ]
            );

            $syncData = [];
            foreach ($config['features'] as $featureSlug => $value) {
                $feature = Feature::where('slug', $featureSlug)->first();
                if ($feature) {
                    $syncData[$feature->id] = ['value' => $value];
                }
            }

            $package->features()->sync($syncData);

            $this->command?->info("Seeded package: {$config['name']} ({$slug}) with ".count($syncData).' features.');
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=EventPackageSeederTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/seeders/EventPackageSeeder.php tests/Feature/Billing/EventPackageSeederTest.php
git commit -m "feat: add EventPackageSeeder for the four event pricing tiers"
```

---

### Task 5: Wire `EventPackageSeeder` into `DatabaseSeeder` (replacing `PackageSeeder`)

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php:23-30`
- Test: `tests/Feature/Billing/EventPackageSeederTest.php` (extend from Task 4)

**Interfaces:**
- Consumes: `EventPackageSeeder` (Task 4).

- [ ] **Step 1: Write the failing test (append to the file from Task 4)**

```php
test('running the full DatabaseSeeder produces the event-tier packages, not the SaaS-generic ones', function () {
    Artisan::call('db:seed');

    expect(\App\Models\Package::where('slug', 'free')->exists())->toBeTrue();
    expect(\App\Models\Package::where('slug', 'growth')->exists())->toBeTrue();
    // The old PackageSeeder read config('product-page.plans') and produced
    // packages named after whatever that config held (typically 'starter',
    // 'pro', 'enterprise') — 'pro' must no longer appear once EventPackageSeeder
    // has replaced PackageSeeder's entry.
    expect(\App\Models\Package::where('slug', 'pro')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EventPackageSeederTest`
Expected: FAIL — `DatabaseSeeder` still calls `PackageSeeder`, so `pro` still exists (or `growth` doesn't).

- [ ] **Step 3: Update `DatabaseSeeder.php`**

Read `database/seeders/DatabaseSeeder.php:23-30` first to get the exact current array, then replace the `PackageSeeder::class` entry with `EventPackageSeeder::class` in that same array (preserving the position and every other entry — `RoleSeeder`, `PermissionsSeeder`, `CustomDomainFeatureSeeder`, `TenantSeeder`, `UserSeeder` are unaffected by this batch, per spec §3's "avoid unrelated scope creep").

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=EventPackageSeederTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/seeders/DatabaseSeeder.php tests/Feature/Billing/EventPackageSeederTest.php
git commit -m "feat: seed event-tier packages instead of the SaaS-generic ones"
```

---

### Task 6: Concurrent-count gate — events-in-flight

**Files:**
- Modify: `app/Http/Controllers/Tenant/EventController.php:51-67` (the `store()` method)
- Test: `tests/Feature/Events/EventTierLimitTest.php`

**Interfaces:**
- Consumes: `Tenant::featureLimitValue('events_in_flight')` (Task 2).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('creating an event beyond the tenant\'s events-in-flight limit is rejected', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'events_in_flight',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 1],
    ]);

    Event::factory()->create(['tenant_id' => $tenant->id, 'status' => Event::STATUS_PUBLISHED]);

    $host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'Second Event',
        'description' => 'Should be rejected',
        'status' => 'published',
        'starts_at' => now()->addWeek()->toDateTimeString(),
        'ends_at' => now()->addWeek()->addHours(3)->toDateTimeString(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => '123 Main St',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasErrors();
    expect(Event::where('tenant_id', $tenant->id)->where('name', 'Second Event')->exists())->toBeFalse();
});

test('creating an event succeeds again once an existing one is cancelled', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme2', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'events_in_flight',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 1],
    ]);

    Event::factory()->create(['tenant_id' => $tenant->id, 'status' => Event::STATUS_CANCELLED]);

    $host = 'acme2.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($user)->post("http://{$host}/events", [
        'name' => 'Allowed Event',
        'description' => 'A cancelled event should not count',
        'status' => 'published',
        'starts_at' => now()->addWeek()->toDateTimeString(),
        'ends_at' => now()->addWeek()->addHours(3)->toDateTimeString(),
        'timezone' => 'Africa/Accra',
        'location_type' => 'in_person',
        'address' => '123 Main St',
        'ticket_price' => 0,
        'currency' => 'GHS',
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    expect(Event::where('tenant_id', $tenant->id)->where('name', 'Allowed Event')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EventTierLimitTest`
Expected: FAIL — no limit is enforced today, so the first test's event gets created (no session errors) and the assertion fails.

- [ ] **Step 3: Add the gate to `EventController::store()`**

In `app/Http/Controllers/Tenant/EventController.php`, inside `store()`, immediately after `$tenant = $this->getTenant();` and before `$validated = $this->validateEvent($request);`, add:

```php
        $limit = $tenant->featureLimitValue('events_in_flight');
        if ($limit !== null) {
            $currentCount = Event::where('tenant_id', $tenant->id)
                ->where('status', '!=', Event::STATUS_CANCELLED)
                ->count();

            if ($currentCount >= $limit) {
                $message = "Your plan allows {$limit} concurrent event(s). Archive or cancel an existing event, or upgrade your plan, to create another.";

                if ($request->wantsJson()) {
                    return response()->json(['message' => $message], 422);
                }

                return redirect()->back()->withErrors(['name' => $message]);
            }
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=EventTierLimitTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Tenant/EventController.php tests/Feature/Events/EventTierLimitTest.php
git commit -m "feat: gate event creation by the tenant's events-in-flight limit"
```

---

### Task 7: Concurrent-count gate — team seats

**Files:**
- Modify: `app/Http/Controllers/Tenant/UserController.php:57-85` (the `store()` method)
- Test: `tests/Feature/Users/TeamSeatLimitTest.php`

**Interfaces:**
- Consumes: `Tenant::featureLimitValue('team_seats')` (Task 2).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('inviting a team member beyond the tenant\'s team-seat limit is rejected', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create(['slug' => 'acme3', 'isolation_mode' => 'shared']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $admin->assignRole('Org Superadmin');
    $tenant->users()->attach($admin->id);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'team_seats',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 1],
    ]);

    $host = 'acme3.'.mb_ltrim((string) config('session.domain'), '.');

    $response = $this->actingAs($admin)->post("http://{$host}/users", [
        'first_name' => 'New',
        'last_name' => 'Member',
        'email' => 'newmember@example.com',
        'phone_no' => '+233201234567',
        'status' => 'active',
        'role' => \Spatie\Permission\Models\Role::where('name', 'Org Superadmin')->first()?->id,
    ], ['HTTP_HOST' => $host]);

    $response->assertSessionHasErrors();
    expect(User::where('email', 'newmember@example.com')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=TeamSeatLimitTest`
Expected: FAIL — no limit enforced today, invite succeeds.

- [ ] **Step 3: Add the gate to `UserController::store()`**

Read `app/Http/Controllers/Tenant/UserController.php:57-85` first to confirm the exact current signature and imports, then insert immediately after `$tenant = $this->getTenant();`:

```php
        $limit = $tenant->featureLimitValue('team_seats');
        if ($limit !== null && $tenant->users()->count() >= $limit) {
            $message = "Your plan allows {$limit} team member(s). Remove an existing member, or upgrade your plan, to invite another.";

            return redirect()->back()->withErrors(['email' => $message]);
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=TeamSeatLimitTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Tenant/UserController.php tests/Feature/Users/TeamSeatLimitTest.php
git commit -m "feat: gate team member invites by the tenant's team-seat limit"
```

---

### Task 8: Monthly-reset metering — event registrations

**Files:**
- Modify: `app/Http/Controllers/Public/PublicEventController.php:101-110` (the confirmed-registration branch in `register()`)
- Modify: `app/Http/Controllers/Tenant/Commerce/WebhookController.php` (the async paid-confirmation path — read first to find the exact confirmation line)
- Test: `tests/Feature/Events/EventRegistrationMeteringTest.php`

**Interfaces:**
- Consumes: `App\Services\Tenancy\FeatureMeteringService::recordUsage()` (existing, unmodified).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\TenantFeatureUsage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('a confirmed free registration records event_registrations usage for the tenant', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create(['slug' => 'acme4', 'isolation_mode' => 'shared']);
    $host = 'acme4.'.mb_ltrim((string) config('session.domain'), '.');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    TenantFeature::create([
        'tenant_id' => $tenant->id,
        'feature_key' => 'event_registrations',
        'enabled' => true,
        'meta' => ['type' => 'limit', 'value' => 100],
    ]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], ['HTTP_HOST' => $host]);

    $usage = TenantFeatureUsage::where('tenant_id', $tenant->id)
        ->where('feature_slug', 'event_registrations')
        ->whereNull('period_start')
        ->value('used_count');

    expect($usage)->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EventRegistrationMeteringTest`
Expected: FAIL — `$usage` is `null`, nothing records this metric today.

- [ ] **Step 3: Wire the metering call**

In `app/Http/Controllers/Public/PublicEventController.php`, inside `register()`, in the `if ($status === EventRegistration::STATUS_CONFIRMED)` branch (currently `issueTicket()`, `save()`, then `Mail::to(...)->queue(...)`), add a call to record usage right after `$registration->save();`:

```php
        if ($status === EventRegistration::STATUS_CONFIRMED) {
            $registration->issueTicket();
            $registration->save();
            app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'event_registrations');
            Mail::to($registration->email)->queue(new EventRegistrationConfirmed($registration));
        } elseif ($status === EventRegistration::STATUS_WAITLISTED) {
```

Then read `app/Http/Controllers/Tenant/Commerce/WebhookController.php` in full to find the exact line where a paid registration's status is flipped to `STATUS_CONFIRMED` (the async payment-confirmation path — per the settlement spec, this is the *other* place a `charge` producer fires). Add the identical call — `app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'event_registrations');` — immediately after that status update, using whichever `$tenant` variable is already in scope there (resolve it via `$registration->tenant` if no local variable already holds it).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=EventRegistrationMeteringTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Public/PublicEventController.php app/Http/Controllers/Tenant/Commerce/WebhookController.php tests/Feature/Events/EventRegistrationMeteringTest.php
git commit -m "feat: meter confirmed registrations against the event_registrations limit"
```

---

### Task 9: Monthly-reset metering — email credits

**Files:**
- Modify: `app/Http/Controllers/Public/PublicEventController.php` (all three `Mail::to(...)->queue(...)` call sites in `register()`)
- Test: `tests/Feature/Events/EmailCreditMeteringTest.php`

**Interfaces:**
- Consumes: `FeatureMeteringService::recordUsage()` (same as Task 8, different feature slug).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\TenantFeatureUsage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('sending a registration confirmation email records email_credits usage', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create(['slug' => 'acme5', 'isolation_mode' => 'shared']);
    $host = 'acme5.'.mb_ltrim((string) config('session.domain'), '.');
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $this->post("http://{$host}/e/{$event->slug}/register", [
        'full_name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], ['HTTP_HOST' => $host]);

    $usage = TenantFeatureUsage::where('tenant_id', $tenant->id)
        ->where('feature_slug', 'email_credits')
        ->whereNull('period_start')
        ->value('used_count');

    expect($usage)->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EmailCreditMeteringTest`
Expected: FAIL — no `email_credits` usage recorded.

- [ ] **Step 3: Wire the metering call at each mail-send site**

In `app/Http/Controllers/Public/PublicEventController.php`'s `register()`, add `app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');` immediately before (or after — order doesn't matter, just co-locate it) each of the three `Mail::to(...)->queue(...)` calls:

```php
        if ($status === EventRegistration::STATUS_CONFIRMED) {
            $registration->issueTicket();
            $registration->save();
            app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'event_registrations');
            app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');
            Mail::to($registration->email)->queue(new EventRegistrationConfirmed($registration));
        } elseif ($status === EventRegistration::STATUS_WAITLISTED) {
            app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');
            Mail::to($registration->email)->queue(new EventRegistrationWaitlisted($registration));
        } elseif ($status === EventRegistration::STATUS_PENDING_APPROVAL) {
            app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($tenant, 'email_credits');
            Mail::to($registration->email)->queue(new EventRegistrationPendingApproval($registration));
        }
```

This batch does not add an equivalent call in `Commerce/WebhookController.php` for the paid-confirmation path's email — that controller's confirmation email send site should be checked and, if present, given the identical one-line addition; if no email is sent there today, no change is needed.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=EmailCreditMeteringTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Public/PublicEventController.php tests/Feature/Events/EmailCreditMeteringTest.php
git commit -m "feat: meter registration-related emails against the email_credits limit"
```

---

### Task 10: Monthly reset schedule for the two new metered limits

**Files:**
- Modify: `app/Console/Kernel.php:24-30`
- Test: `tests/Unit/Console/ScheduledUsageResetTest.php`

**Interfaces:**
- Consumes: the existing `tenants:reset-usage {--tenant=} {--feature=}` command, unmodified.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

test('the schedule resets event_registrations and email_credits usage monthly', function () {
    $schedule = app(Schedule::class);
    $descriptions = collect($schedule->events())->map(fn ($event): string => $event->command ?? '')->values();

    expect($descriptions->contains(fn (string $c): bool => str_contains($c, 'tenants:reset-usage') && str_contains($c, '--feature=event_registrations')))->toBeTrue();
    expect($descriptions->contains(fn (string $c): bool => str_contains($c, 'tenants:reset-usage') && str_contains($c, '--feature=email_credits')))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ScheduledUsageResetTest`
Expected: FAIL — neither schedule entry exists yet.

- [ ] **Step 3: Add the schedule entries**

In `app/Console/Kernel.php`, inside `schedule()`, add after the existing `usage:check-alerts` line:

```php
        $schedule->command('tenants:reset-usage --feature=event_registrations')->monthlyOn(1, '00:05');
        $schedule->command('tenants:reset-usage --feature=email_credits')->monthlyOn(1, '00:10');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ScheduledUsageResetTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Kernel.php tests/Unit/Console/ScheduledUsageResetTest.php
git commit -m "feat: schedule monthly resets for registration and email-credit usage"
```

---

### Task 11: Backfill existing tenants onto the Free package

**Files:**
- Create: `app/Console/Commands/Billing/BackfillFreePackageCommand.php`
- Test: `tests/Feature/Billing/BackfillFreePackageCommandTest.php`

**Interfaces:**
- Consumes: `App\Services\Billing\SubscriptionProvisioningService::upgrade()` (existing, unmodified), `Package::where('is_free', true)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantFeature;
use Illuminate\Support\Facades\Artisan;

test('backfilling assigns every tenant without a package to Free and syncs its features', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EventPackageSeeder']);
    $tenant = Tenant::factory()->create(['package_id' => null]);

    Artisan::call('billing:backfill-free-package');

    $tenant->refresh();
    $free = Package::where('slug', 'free')->firstOrFail();
    expect($tenant->package_id)->toBe($free->id);

    $feature = TenantFeature::where('tenant_id', $tenant->id)
        ->where('feature_key', 'events_in_flight')
        ->firstOrFail();
    expect($feature->enabled)->toBeTrue();
    expect($feature->meta['value'])->toEqual(1);
});

test('backfilling does not touch a tenant that already has a package assigned', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EventPackageSeeder']);
    $growth = Package::where('slug', 'growth')->firstOrFail();
    $tenant = Tenant::factory()->create(['package_id' => $growth->id]);

    Artisan::call('billing:backfill-free-package');

    expect($tenant->refresh()->package_id)->toBe($growth->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=BackfillFreePackageCommandTest`
Expected: FAIL — "Command 'billing:backfill-free-package' is not defined"

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Models\Package;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionProvisioningService;
use Illuminate\Console\Command;

final class BackfillFreePackageCommand extends Command
{
    protected $signature = 'billing:backfill-free-package';

    protected $description = 'One-off: assign every tenant without a package to the Free event-tier package.';

    public function handle(SubscriptionProvisioningService $provisioning): int
    {
        $freePackage = Package::where('is_free', true)->first();

        if (! $freePackage) {
            $this->error('No free package found. Run the EventPackageSeeder first.');

            return self::FAILURE;
        }

        $count = 0;
        Tenant::whereNull('package_id')->chunkById(100, function ($tenants) use ($provisioning, $freePackage, &$count): void {
            foreach ($tenants as $tenant) {
                $provisioning->upgrade($tenant, $freePackage);
                $count++;
            }
        });

        $this->info("Backfilled {$count} tenant(s) onto the Free package.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=BackfillFreePackageCommandTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/Billing/BackfillFreePackageCommand.php tests/Feature/Billing/BackfillFreePackageCommandTest.php
git commit -m "feat: add one-off command to backfill existing tenants onto the Free package"
```

---

### Task 12: Regression guard — dead middleware stays dead

**Files:**
- Test: `tests/Feature/Billing/DeadFeatureMiddlewareRegressionTest.php`

**Interfaces:**
- Consumes: nothing new — this is a pure regression guard per spec §5/§10, asserting this batch didn't accidentally reactivate the two dead middleware aliases.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

test('the dead feature and feature_limit middleware aliases are not attached to any route', function () {
    $offendingRoutes = collect(Route::getRoutes())
        ->filter(function ($route): bool {
            $middleware = $route->gatherMiddleware();

            return collect($middleware)->contains(fn (string $m): bool => str_starts_with($m, 'feature:') || str_starts_with($m, 'feature_limit:') || $m === 'feature' || $m === 'feature_limit');
        });

    expect($offendingRoutes)->toHaveCount(0);
});
```

- [ ] **Step 2: Run test to verify it passes immediately**

Run: `php artisan test --compact --filter=DeadFeatureMiddlewareRegressionTest`
Expected: PASS (should already pass, since no earlier task attaches these aliases to any route — this test exists purely to catch a future regression, matching spec §10's explicit ask).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Billing/DeadFeatureMiddlewareRegressionTest.php
git commit -m "test: guard against reactivating the dead feature/feature_limit middleware"
```

---

## Self-Review

**Spec coverage:**
- §4 tier definitions → Task 4 (seeder data).
- §5 architecture (System A reuse, boolean via `Facades\Feature::enabled()`, monotonic via `canUse()`/`recordUsage()`, concurrent via new `featureLimitValue()`) → Tasks 2, 6, 7, 8, 9.
- §6 package reseed / `DatabaseSeeder` wiring → Tasks 4, 5.
- §7 commission-by-tier → Tasks 1, 3.
- §8 add-on credit-pack store → explicitly sketch-only in the spec; no task, correctly excluded.
- §9 migration/rollout → Task 11.
- §10 testing approach → covered by every task's own test plus Task 12's explicit regression guard.
- §3 out-of-scope items (SMS, live portals, badge templates, dashboards, webhook self-service, SSO, support routing) → correctly have no implementation tasks, only reserved Feature rows in Task 4.

**Placeholder scan:** No TBD/TODO markers. Every step has real code. Task 8/9's `WebhookController.php` step asks the implementer to read the file first because its exact current line numbers weren't available during planning — this is a "read then insert at the identified point" instruction with a fully-specified line of code to insert, not a vague "add appropriate handling," so it is not a placeholder violation; the same is true of Task 7's "read UserController.php first" step, which likewise specifies the exact code to insert once the surrounding lines are confirmed.

**Type consistency:** `Tenant::featureLimitValue(string $featureSlug): ?int` (Task 2) is used with the identical signature in Tasks 6 and 7. `FeatureMeteringService::recordUsage(Tenant $tenant, string $featureSlug, int $quantity = 1): void` (existing, unmodified) is called identically in Tasks 8 and 9. `Package::default_platform_fee_percentage` (Task 1) is read via `$this->tenant?->package?->default_platform_fee_percentage` in Task 3, matching the column name and nullability exactly.
