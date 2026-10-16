<?php

declare(strict_types=1);

use App\Models\DuesCharge;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$this->organisation, $this->admin] = eventHost('acme');
    Artisan::call('community:enable', ['tenant' => 'acme']);
    $this->type = MembershipType::factory()->create(['tenant_id' => $this->organisation->id]);
    $this->actingAs($this->admin)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')]);
    $this->payload = ['name' => 'Ama Mensah', 'phone' => '0244000001', 'membership_type_id' => $this->type->id];
});

test('different people sharing a phone keep separate records pay links and charges', function () {
    $first = $this->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), $this->payload)->assertCreated()->json('id');
    $data = [...$this->payload, 'name' => 'Kofi Mensah'];
    $review = $this->postJson(route('tenant.community.members.candidates', ['subdomain' => 'acme']), $data)->assertOk()->json();
    $second = $this->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), [...$data, 'confirm_distinct' => true, 'duplicate_revision' => $review['revision']])->assertCreated()->json('id');
    $ama = Member::query()->findOrFail($first);
    $kofi = Member::query()->findOrFail($second);
    expect($ama->pay_token)->not->toBe($kofi->pay_token);
    foreach ([$ama, $kofi] as $member) {
        DuesCharge::factory()->create(['tenant_id' => $this->organisation->id, 'member_id' => $member->id, 'membership_type_id' => $this->type->id]);
    }
    foreach ([[$ama, $kofi], [$kofi, $ama]] as [$member, $other]) {
        $this->get('http://'.eventSubdomainHost('acme').'/pay/'.$member->pay_token)
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('member.name', $member->name)->has('charges', 1)
            ->where('charges.0.id', $member->charges()->sole()->id)->etc())
            ->assertDontSee($other->charges()->sole()->id);
    }
});

test('the same person cannot be added twice through spelling whitespace or phone formatting', function (string $name, string $phone) {
    Member::factory()->create(['tenant_id' => $this->organisation->id, 'membership_type_id' => $this->type->id, 'name' => ' Ama   Mensah ', 'phone' => '0244 000 001']);
    $this->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), [...$this->payload, 'name' => $name, 'phone' => $phone])
        ->assertUnprocessable()->assertJsonValidationErrors(['phone']);
})->with([
    ['Ama Mensah', '0244000001'],
    [' ama   MENSAH ', '+233 244 000 001'],
]);

test('editing a shared number ignores self but refuses another members identity', function () {
    $member = Member::factory()->create(['tenant_id' => $this->organisation->id, 'membership_type_id' => $this->type->id, 'name' => 'Ama Mensah', 'phone' => '0244000001']);
    $url = route('tenant.community.members.update', ['subdomain' => 'acme', 'member' => $member->id]);
    $this->patchJson($url, [...$this->payload, 'name' => ' AMA  Mensah '])->assertOk();
    Member::factory()->create(['tenant_id' => $this->organisation->id, 'membership_type_id' => $this->type->id, 'name' => 'Kofi Mensah', 'phone' => '0244000001']);
    $this->patchJson($url, [...$this->payload, 'name' => ' kofi MENSAH '])->assertUnprocessable()->assertJsonValidationErrors(['phone']);
});

test('the name and phone duplicate guard is bounded to the organisation', function () {
    $other = Tenant::factory()->create();
    Member::factory()->create(['tenant_id' => $other->id, 'name' => 'Ama Mensah', 'phone' => '0244000001']);
    $this->postJson(route('tenant.community.members.store', ['subdomain' => 'acme']), $this->payload)->assertCreated();
});

test('rolling back shared phones refuses without changing either member or their charges', function () {
    $first = Member::factory()->create(['tenant_id' => $this->organisation->id, 'membership_type_id' => $this->type->id, 'name' => 'Ama', 'phone' => '0244000001']);
    $second = Member::factory()->create(['tenant_id' => $this->organisation->id, 'membership_type_id' => $this->type->id, 'name' => 'Kofi', 'phone' => '0244000001']);
    $charge = DuesCharge::factory()->create(['tenant_id' => $this->organisation->id, 'member_id' => $first->id, 'membership_type_id' => $this->type->id]);
    $reviewed = require database_path('migrations/landlord/2026_10_19_100600_allow_reviewed_shared_member_identities.php');
    $reviewed->down();
    $migration = require database_path('migrations/landlord/2026_10_16_100300_allow_shared_member_phones.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot restore unique member phones');
    expect(Member::query()->findOrFail($first->id)->pay_token)->toBe($first->pay_token)
        ->and(Member::query()->findOrFail($second->id)->pay_token)->toBe($second->pay_token)
        ->and($charge->fresh()->member_id)->toBe($first->id);
    Member::factory()->create(['tenant_id' => $this->organisation->id, 'membership_type_id' => $this->type->id, 'name' => 'Abena', 'phone' => '0244000001']);
});

test('shared phone migration preserves existing members and safely reverses when numbers are unique', function () {
    $member = Member::factory()->create(['tenant_id' => $this->organisation->id, 'membership_type_id' => $this->type->id, 'name' => 'Ama', 'phone' => '0244000001']);
    $reviewed = require database_path('migrations/landlord/2026_10_19_100600_allow_reviewed_shared_member_identities.php');
    $reviewed->down();
    $migration = require database_path('migrations/landlord/2026_10_16_100300_allow_shared_member_phones.php');
    $migration->down();
    $migration->up();

    expect($member->fresh()->phone)->toBe('0244000001')
        ->and($member->fresh()->pay_token)->toBe($member->pay_token);
    Member::factory()->create(['tenant_id' => $this->organisation->id, 'membership_type_id' => $this->type->id, 'name' => 'Kofi', 'phone' => '0244000001']);
});

test('the legacy database guard is restored on a safe rollback', function (string $phone, string $name = '  AMA   mensah  ') {
    $reviewed = require database_path('migrations/landlord/2026_10_19_100600_allow_reviewed_shared_member_identities.php');
    $reviewed->down();
    Member::factory()->create(['tenant_id' => $this->organisation->id, 'membership_type_id' => $this->type->id, 'name' => 'Ama Mensah', 'phone' => '0244000001']);

    expect(fn () => Member::factory()->create([
        'tenant_id' => $this->organisation->id,
        'membership_type_id' => $this->type->id,
        'name' => $name,
        'phone' => $phone,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
})->with([
    ['+233 244 000 001'],
    ['00233-244-000-001'],
    ['0244 000 001'],
    ['+233244000001', "\u{200B}AMA\u{00A0}\u{3164}mensah\u{FEFF}"],
]);

test('shared phone migration refuses existing canonical duplicates without rewriting contacts', function () {
    $reviewed = require database_path('migrations/landlord/2026_10_19_100600_allow_reviewed_shared_member_identities.php');
    $reviewed->down();
    $migration = require database_path('migrations/landlord/2026_10_16_100300_allow_shared_member_phones.php');
    $migration->down();
    $first = Member::factory()->create(['tenant_id' => $this->organisation->id, 'membership_type_id' => $this->type->id, 'name' => 'Ama Mensah', 'phone' => '0244000001']);
    $second = Member::factory()->create(['tenant_id' => $this->organisation->id, 'membership_type_id' => $this->type->id, 'name' => ' AMA  Mensah ', 'phone' => '+233 244 000 001']);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Canonical duplicate members exist');
    expect($first->fresh()->phone)->toBe('0244000001')
        ->and($second->fresh()->phone)->toBe('+233 244 000 001')
        ->and($second->fresh()->name)->toBe(' AMA  Mensah ');
    $second->delete();
    $migration->up();
});
