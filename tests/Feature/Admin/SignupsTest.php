<?php

declare(strict_types=1);

use App\Enum\TenantStatusEnum;
use App\Mail\Platform\TenantSignupMail;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantFollowUp;
use App\Models\User;
use App\Services\Platform\PlatformStaff;
use App\Services\Platform\SignupAlerts;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;

uses(RefreshDatabase::class);

/**
 * Who has signed up and how far they got, who is told, and who can follow up.
 */
beforeEach(function (): void {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);
});

function staffMember(string $role, array $attributes = []): User
{
    $user = User::factory()->create(['tenant_id' => null, 'password' => bcrypt('password')] + $attributes);
    setPermissionsTeamId(null);
    $user->assignRole(Role::query()->where('name', $role)->whereNull('tenant_id')->firstOrFail());

    return $user;
}

test('everyone whose platform role may view signups is told, and nobody else', function (): void {
    $superadmin = staffMember('Superadmin');
    $finance = staffMember('Platform Finance');
    $marketing = staffMember('Platform Marketing');
    $tenant = Tenant::factory()->create();
    $orgAdmin = User::factory()->create(['tenant_id' => $tenant->id]);
    setActiveTenantForTest($orgAdmin);
    $orgAdmin->assignRole('Org Superadmin');
    $inactive = staffMember('Platform Marketing', ['status' => 'inactive']);

    $emails = app(PlatformStaff::class)->emailsWithPermission('view signups');

    expect($emails)->toContain(mb_strtolower($superadmin->email), mb_strtolower($finance->email), mb_strtolower($marketing->email))
        ->not->toContain(mb_strtolower($orgAdmin->email))
        ->not->toContain(mb_strtolower($inactive->email));
});

test('registering an organisation tells the staff it signed up', function (): void {
    Location::shouldReceive('get')->andReturn(Position::make(['countryName' => 'Testland']));
    refreshTenantDatabases();
    $this->seed(EventPackageSeeder::class);
    Mail::fake();
    $marketing = staffMember('Platform Marketing');

    $this->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)->post('/register', [
        'first_name' => 'Ada', 'last_name' => 'Osei', 'organization_name' => 'Acme Corp', 'email' => 'ada@acmecorp.com',
        'password' => 'Password1!', 'password_confirmation' => 'Password1!', 'plan' => 'free', 'slug' => 'acme-corp',
    ]);

    Mail::assertQueued(TenantSignupMail::class, fn (TenantSignupMail $mail): bool => $mail->hasTo($marketing->email)
        && $mail->stage === TenantSignupMail::STAGE_SIGNED_UP
        && $mail->tenant->slug === 'acme-corp');
});

test('finishing onboarding tells the staff once, however often it is finished', function (): void {
    Mail::fake();
    $finance = staffMember('Platform Finance');
    $owner = User::factory()->create();
    $owner->assignRole('Org Admin');
    $tenant = setActiveTenantForTest($owner, ['status' => TenantStatusEnum::ACTIVE, 'onboarding_completed_at' => null]);
    $host = $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($owner)->post("http://{$host}/onboarding/finish", [], ['HTTP_HOST' => $host]);
    $firstFinish = $tenant->refresh()->onboarding_completed_at;
    $this->actingAs($owner)->post("http://{$host}/onboarding/finish", [], ['HTTP_HOST' => $host]);

    Mail::assertQueued(TenantSignupMail::class, 1);
    Mail::assertQueued(TenantSignupMail::class, fn (TenantSignupMail $mail): bool => $mail->hasTo($finance->email) && $mail->stage === TenantSignupMail::STAGE_ONBOARDED);
    expect($tenant->refresh()->onboarding_completed_at->equalTo($firstFinish))->toBeTrue();
});

test('a failure while alerting never gets in the way of the signup', function (): void {
    staffMember('Platform Marketing');
    Mail::shouldReceive('to')->andThrow(new RuntimeException('mail server down'));

    app(SignupAlerts::class)->signedUp(Tenant::factory()->create());

    expect(true)->toBeTrue();
});

test('with nobody to tell, nothing is sent', function (): void {
    Mail::fake();
    DB::connection('landlord')->table('model_has_roles')->delete();

    app(SignupAlerts::class)->signedUp(Tenant::factory()->create());

    Mail::assertNothingQueued();
});

test('the email says what happened and links to the signup', function (string $stage, string $headline): void {
    $tenant = Tenant::factory()->create(['name' => 'Accra Summit', 'email' => 'owner@accra.test', 'onboarding_completed_at' => now()]);

    (new TenantSignupMail($tenant, $stage))->assertSeeInHtml('Accra Summit')
        ->assertSeeInHtml($headline)
        ->assertSeeInHtml('owner@accra.test')
        ->assertSeeInHtml(route('admin.signups.show', $tenant->uuid));
})->with([
    'signed up' => [TenantSignupMail::STAGE_SIGNED_UP, 'New signup'],
    'onboarded' => [TenantSignupMail::STAGE_ONBOARDED, 'Finished onboarding'],
]);

test('marketing sees signups by stage and can search them', function (): void {
    Tenant::factory()->create(['name' => 'Fresh Org', 'email' => 'fresh@x.test', 'onboarding_completed_at' => null]);
    Tenant::factory()->create(['name' => 'Done Org', 'email' => 'done@x.test', 'onboarding_completed_at' => now()]);
    $this->actingAs(staffMember('Platform Marketing'));

    $this->get(route('admin.signups.index'))->assertOk()->assertSee('Fresh Org')->assertSee('Done Org');
    $this->get(route('admin.signups.index', ['stage' => 'signed_up']))->assertSee('Fresh Org')->assertDontSee('Done Org');
    $this->get(route('admin.signups.index', ['stage' => 'onboarded']))->assertSee('Done Org')->assertDontSee('Fresh Org');
    $this->get(route('admin.signups.index', ['q' => 'done@x']))->assertSee('Done Org')->assertDontSee('Fresh Org');
});

test('marketing logs a follow-up and the history and latest status show it', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Fresh Org']);
    $marketing = staffMember('Platform Marketing', ['first_name' => 'Kofi']);

    $this->actingAs($marketing)->post(route('admin.signups.follow-up', $tenant->uuid), ['status' => 'interested', 'note' => 'Wants a demo on Friday'])->assertSessionHas('success');

    $this->get(route('admin.signups.show', $tenant->uuid))->assertOk()->assertSee('Wants a demo on Friday')->assertSee('Kofi');
    $this->get(route('admin.signups.index'))->assertSee('Interested');
    expect(TenantFollowUp::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a follow-up needs a known outcome', function (): void {
    $tenant = Tenant::factory()->create();

    $this->actingAs(staffMember('Platform Marketing'))
        ->post(route('admin.signups.follow-up', $tenant->uuid), ['status' => 'made-up'])
        ->assertSessionHasErrors('status');

    expect(TenantFollowUp::count())->toBe(0);
});

test('finance can read signups but not log follow-ups', function (): void {
    $tenant = Tenant::factory()->create();
    $finance = staffMember('Platform Finance');

    $this->actingAs($finance)->get(route('admin.signups.index'))->assertOk();
    $this->actingAs($finance)->get(route('admin.signups.show', $tenant->uuid))->assertOk()->assertDontSee('Log a follow-up');
    $this->actingAs($finance)->post(route('admin.signups.follow-up', $tenant->uuid), ['status' => 'contacted'])->assertForbidden();
});

test('an organisation user cannot see signups', function (): void {
    $tenant = Tenant::factory()->create();
    $member = User::factory()->create(['tenant_id' => $tenant->id]);
    setActiveTenantForTest($member);
    $member->assignRole('Org Superadmin');

    $this->actingAs($member)->get(route('admin.signups.index'))->assertForbidden();
    $this->actingAs($member)->get(route('admin.signups.show', $tenant->uuid))->assertForbidden();
});

test('marketing staff land on the signups page after signing in and from the generic dashboard', function (): void {
    $marketing = staffMember('Platform Marketing');

    $this->post('/login', ['email' => $marketing->email, 'password' => 'password'])->assertRedirect(route('admin.signups.index'));
    $this->actingAs($marketing)->get(route('dashboard'))->assertRedirect(route('admin.signups.index'));
});

test('marketing sees only signups in the menu, and none of the finance or superadmin pages', function (): void {
    $this->actingAs(staffMember('Platform Marketing'))->get(route('admin.signups.index'))
        ->assertOk()
        ->assertSee('Signups')
        ->assertDontSee('Billing & Finance', false)
        ->assertDontSee('Commission destinations')
        ->assertDontSee('Operations');

    $this->get(route('admin.billing.earnings.index'))->assertForbidden();
});

test('the marketing role holds exactly the signup permissions', function (): void {
    $role = Role::query()->where('name', 'Platform Marketing')->whereNull('tenant_id')->firstOrFail();

    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['follow up signups', 'view signups'])
        ->and($role->isSystemRole())->toBeTrue();
});

test('the list shows the newest follow-up for each organisation, not the first', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Fresh Org']);
    TenantFollowUp::factory()->create(['tenant_id' => $tenant->id, 'status' => 'contacted', 'created_at' => now()->subDays(3)]);
    TenantFollowUp::factory()->create(['tenant_id' => $tenant->id, 'status' => 'interested', 'created_at' => now()->subDay()]);
    $other = Tenant::factory()->create(['name' => 'Quiet Org']);

    $this->actingAs(staffMember('Platform Marketing'))->get(route('admin.signups.index'))
        ->assertOk()
        ->assertSee('Interested')
        ->assertDontSee('Contacted')
        ->assertSee('Not followed up');
});
