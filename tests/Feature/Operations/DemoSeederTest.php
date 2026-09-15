<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Finance\LedgerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config(['app.demo' => true, 'app.demo_owner_email' => 'demo@miconvener.test', 'app.demo_owner_password' => 'a-long-demo-password']);
});

test('the demo organization is seeded with events, attendees and books that balance', function (): void {
    Artisan::call('db:seed', ['--class' => DemoSeeder::class]);

    $tenant = Tenant::query()->where('slug', DemoSeeder::TENANT_SLUG)->firstOrFail();
    $owner = User::query()->where('email', 'demo@miconvener.test')->firstOrFail();
    setPermissionsTeamId($tenant->id);

    expect($tenant->billing_complimentary)->toBeTrue()
        ->and(Hash::check('a-long-demo-password', $owner->password))->toBeTrue()
        ->and($owner->can('manage billing'))->toBeTrue()
        ->and(Event::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(3)
        ->and(EventRegistration::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(118 + 64 + 11 + 9 + 46 + 87)
        ->and(EventLedgerEntry::query()->where('tenant_id', $tenant->id)->where('type', 'charge')->count())->toBe(118 + 64 + 11 + 87);

    $past = Event::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('slug', 'kumasi-founders-forum-2026')->firstOrFail();
    $checkedIn = EventRegistration::query()->withoutGlobalScopes()->where('event_id', $past->id)->whereNotNull('checked_in_at')->count();
    $balance = app(LedgerService::class)->getTrialBalance($past);

    $organizerPayable = collect($balance['accounts'])->firstWhere('code', App\Models\LedgerAccount::CODE_ORGANIZER_PAYABLE);

    expect($checkedIn)->toBeGreaterThan(60)->toBeLessThan(87)
        ->and($balance['is_balanced'])->toBeTrue()
        ->and($organizerPayable['balance'])->toBe(0);
});

test('seeding twice changes nothing', function (): void {
    Artisan::call('db:seed', ['--class' => DemoSeeder::class]);
    $registrations = EventRegistration::query()->withoutGlobalScopes()->count();

    Artisan::call('db:seed', ['--class' => DemoSeeder::class]);

    expect(EventRegistration::query()->withoutGlobalScopes()->count())->toBe($registrations)
        ->and(Tenant::query()->where('slug', DemoSeeder::TENANT_SLUG)->count())->toBe(1);
});

test('the demo seeder refuses to run on an environment that is not the demo', function (): void {
    config(['app.demo' => false]);
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class, 'DEMO_MODE=true');

    app()->detectEnvironment(fn (): string => 'testing');
});

test('the demo seeder needs a password for the demo owner', function (): void {
    config(['app.demo_owner_password' => null]);

    expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class, 'DEMO_OWNER_PASSWORD');
});
