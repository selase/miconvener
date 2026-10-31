<?php

declare(strict_types=1);

use App\Models\CommunityGroup;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$this->tenant, $this->owner] = eventHost('group-qa');
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    $this->tenant->features()->create(['feature_key' => 'community_messages', 'enabled' => true]);
    $this->actingAs($this->owner)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('group-qa')]);
});

function groupTestUrl(string $path = ''): string
{
    return 'http://'.eventSubdomainHost('group-qa').'/community/groups'.$path;
}

test('groups create list update and archive inside their organisation', function () {
    $this->postJson(groupTestUrl(), ['name' => 'North chapter', 'description' => 'Accra'])->assertCreated();
    $group = CommunityGroup::query()->firstOrFail();
    $this->getJson(groupTestUrl())->assertOk()->assertJsonPath('groups.0.name', 'North chapter');
    $this->patchJson(groupTestUrl('/'.$group->id), ['name' => 'North branch', 'description' => null])->assertOk();
    $this->postJson(groupTestUrl('/'.$group->id.'/archive'))->assertOk();
    expect($group->fresh()->archived_at)->not->toBeNull();
});

test('same name is rejected within a tenant but accepted in another', function () {
    CommunityGroup::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'North']);
    $this->postJson(groupTestUrl(), ['name' => 'North'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $foreign = Tenant::factory()->create();
    expect(CommunityGroup::factory()->create(['tenant_id' => $foreign->id, 'name' => 'North'])->exists)->toBeTrue();
});

test('membership replacement is idempotent and preserves member history', function () {
    $group = CommunityGroup::factory()->create(['tenant_id' => $this->tenant->id]);
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id]);
    $url = groupTestUrl('/'.$group->id.'/members');
    $this->putJson($url, ['member_ids' => [$member->id]])->assertOk();
    $this->putJson($url, ['member_ids' => [$member->id]])->assertOk();
    expect($group->members()->count())->toBe(1)->and($member->groups()->count())->toBe(1);
    $this->putJson($url, ['member_ids' => []])->assertOk();
    expect($group->members()->count())->toBe(0)->and($member->fresh())->not->toBeNull();
});

test('another tenant member or nonexistent id cannot be attached', function () {
    $group = CommunityGroup::factory()->create(['tenant_id' => $this->tenant->id]);
    $foreign = Member::factory()->create();
    $this->putJson(groupTestUrl('/'.$group->id.'/members'), ['member_ids' => [$foreign->id]])->assertUnprocessable();
    $this->putJson(groupTestUrl('/'.$group->id.'/members'), ['member_ids' => ['11111111-1111-4111-8111-111111111111']])->assertUnprocessable();
    expect($group->members()->count())->toBe(0);
});

test('foreign groups are neither exposed nor mutable', function () {
    $foreign = CommunityGroup::factory()->create();
    $this->getJson(groupTestUrl())->assertOk()->assertJsonCount(0, 'groups');
    $this->patchJson(groupTestUrl('/'.$foreign->id), ['name' => 'Stolen'])->assertNotFound();
    $this->postJson(groupTestUrl('/'.$foreign->id.'/archive'))->assertNotFound();
    $this->putJson(groupTestUrl('/'.$foreign->id.'/members'), ['member_ids' => []])->assertNotFound();
});

test('archived groups retain members but cannot be edited', function () {
    $group = CommunityGroup::factory()->create(['tenant_id' => $this->tenant->id, 'archived_at' => now()]);
    $this->patchJson(groupTestUrl('/'.$group->id), ['name' => 'Edit'])->assertUnprocessable();
    $this->putJson(groupTestUrl('/'.$group->id.'/members'), ['member_ids' => []])->assertUnprocessable();
});

test('group endpoints enforce permission and the community switch', function () {
    $reader = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($reader->id);
    $this->actingAs($reader)->getJson(groupTestUrl())->assertForbidden();
    $this->actingAs($reader)->postJson(groupTestUrl(), ['name' => 'Blocked'])->assertForbidden();
    $this->actingAs($this->owner);
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug, '--off' => true]);
    $this->getJson(groupTestUrl())->assertNotFound();
});
