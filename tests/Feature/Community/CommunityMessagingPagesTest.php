<?php

declare(strict_types=1);

use App\Models\CommunityGroup;
use App\Models\CommunityMessage;
use App\Models\Member;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once dirname(__DIR__, 2).'/Fixtures/community/message-test-setup.php';
beforeEach(fn () => setupCommunityMessagesTest($this));

test('messages has a gated console page with explicit capabilities and history', function () {
    $this->get(messageTestUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Tenant/Community/Messages')->has('messages.data', 0)
        ->where('capabilities.schedule', false)->where('capabilities.groups', false)
        ->where('auth.can.send_community_messages', true)->where('tenant.features.community_messages', true));
});

test('drafts can be edited and submitted once without creating another history record', function () {
    $this->postJson(messageTestUrl(), $this->input + ['mode' => 'draft'])->assertCreated();
    $message = CommunityMessage::query()->firstOrFail();
    $input = array_replace($this->input, ['subject' => 'Updated news', 'idempotency_key' => (string) Str::uuid(), 'mode' => 'send']);
    $this->patchJson(messageTestUrl('/'.$message->id), $input)->assertOk()->assertJsonPath('message.status', 'queued');
    $this->patchJson(messageTestUrl('/'.$message->id), $input)->assertOk();
    expect(CommunityMessage::query()->count())->toBe(1)->and($message->fresh()->subject)->toBe('Updated news');
    $this->patchJson(messageTestUrl('/'.$message->id), array_replace($input, ['idempotency_key' => (string) Str::uuid()]))->assertStatus(409);
    $foreign = CommunityMessage::factory()->create(['status' => 'draft']);
    $this->patchJson(messageTestUrl('/'.$foreign->id), $input)->assertNotFound();
});

test('groups support bounded member selection and incremental changes without replacing other members', function () {
    $group = CommunityGroup::factory()->create(['tenant_id' => $this->tenant->id]);
    $second = Member::factory()->create(['tenant_id' => $this->tenant->id]);
    $group->members()->attach($this->member->id, ['tenant_id' => $this->tenant->id]);
    $url = 'http://'.eventSubdomainHost('message-qa').'/community/groups/'.$group->id.'/members';
    $this->getJson($url)->assertOk()->assertJsonPath('members.total', 2);
    $this->patchJson($url, ['action' => 'attach', 'member_ids' => [$second->id]])->assertOk()->assertJsonPath('members_count', 2);
    $this->patchJson($url, ['action' => 'detach', 'member_ids' => [$this->member->id]])->assertOk()->assertJsonPath('members_count', 1);
    $this->patchJson($url, ['action' => 'attach', 'member_ids' => [Member::factory()->create()->id]])->assertUnprocessable();
    $group->update(['archived_at' => now()]);
    $this->patchJson($url, ['action' => 'detach', 'member_ids' => [$second->id]])->assertUnprocessable();
    expect($group->members()->count())->toBe(1);
});

test('message group choices are available to senders without member-management permission', function () {
    CommunityGroup::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Choir']);
    CommunityGroup::factory()->create(['name' => 'Other private group']);
    $this->getJson(messageTestUrl('/groups'))->assertOk()->assertJsonCount(1, 'groups.data')->assertJsonPath('groups.data.0.name', 'Choir');
    expect(App\Models\Permission::TENANT_SAFE)->toContain('send community messages');
});

test('custom senders can use message choices without access to member administration and finance', function () {
    $sender = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($sender->id);
    setPermissionsTeamId($this->tenant->id);
    $sender->givePermissionTo('send community messages');
    $this->actingAs($sender)->getJson(messageTestUrl('/groups'))->assertOk();
    $this->get(messageTestUrl())->assertInertia(fn (Assert $page) => $page->component('Tenant/Community/Messages')->where('capabilities.finance', false));
    $this->getJson('http://'.eventSubdomainHost('message-qa').'/community/groups')->assertForbidden();
    $this->postJson(messageTestUrl('/preview'), array_replace($this->input, ['audience' => ['kind' => 'arrears']]))->assertForbidden();
});

test('history stays visible when paid message capabilities are withdrawn', function () {
    $message = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'completed', 'audience' => ['kind' => 'groups', 'group_ids' => []]]);
    $this->get(messageTestUrl())->assertInertia(fn (Assert $page) => $page->component('Tenant/Community/Messages')->where('messages.data.0.id', $message->id)->where('capabilities.groups', false));
    $this->getJson(messageTestUrl('/'.$message->id))->assertOk();
});

test('saving a draft does not require send authority confirmation', function () {
    $input = $this->input;
    unset($input['confirm_authority']);
    $this->postJson(messageTestUrl(), $input + ['mode' => 'draft'])->assertCreated()->assertJsonPath('message.status', 'draft');
});

test('arrears history obeys finance access for JSON and console after revocation', function () {
    $sender = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($sender->id);
    setPermissionsTeamId($this->tenant->id);
    $sender->givePermissionTo(['send community messages', 'read finance']);
    $message = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id, 'sent_by' => $sender->id, 'audience' => ['kind' => 'arrears'], 'estimate' => ['email' => 12]]);
    $this->actingAs($sender)->getJson(messageTestUrl('/'.$message->id))->assertOk();
    $sender->revokePermissionTo('read finance');
    $this->actingAs($sender->fresh())->getJson(messageTestUrl())->assertJsonCount(0, 'messages.data');
    $this->getJson(messageTestUrl('/'.$message->id))->assertNotFound();
    $this->postJson(messageTestUrl('/'.$message->id.'/cancel'))->assertNotFound();
    $sender->givePermissionTo('manage members');
    $this->actingAs($sender->fresh())->get('http://'.eventSubdomainHost('message-qa').'/community')->assertInertia(fn (Assert $page) => $page->component('Tenant/Community/Overview')->has('recentMessages', 0));
    $this->get(messageTestUrl())->assertInertia(fn (Assert $page) => $page->component('Tenant/Community/Messages')->has('messages.data', 0));
});
