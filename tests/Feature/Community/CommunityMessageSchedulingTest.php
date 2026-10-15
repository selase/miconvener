<?php

declare(strict_types=1);

use App\Models\Package;
use App\Services\Community\CommunityMessages;
use App\Services\Community\MessagingPreferences;
use Illuminate\Support\Facades\Mail;

require_once dirname(__DIR__, 2).'/Fixtures/community/message-test-setup.php';
beforeEach(fn () => setupCommunityMessagesTest($this));

test('Free cannot schedule and Starter can schedule but cannot target groups', function () {
    $input = array_replace($this->input, ['scheduled_at' => '2026-10-16T12:00:00+00:00']);
    $this->postJson(messageTestUrl(), $input)->assertUnprocessable();
    $package = Package::factory()->create(['slug' => 'starter']);
    $this->tenant->update(['package_id' => $package->id]);
    $this->postJson(messageTestUrl(), $input)->assertCreated();
    expect(App\Models\CommunityMessage::query()->first()->status)->toBe('scheduled');
});

test('an opt-out before a scheduled send is honoured when due', function () {
    $this->tenant->features()->create(['feature_key' => 'community_message_scheduling', 'enabled' => true]);
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, array_replace($this->input, ['scheduled_at' => '2026-10-16T12:00:00+00:00']));
    app(CommunityMessages::class)->dispatchDue($message->id);
    expect($message->deliveries()->count())->toBe(0);
    app(MessagingPreferences::class)->disableMarketing($this->tenant, 'email', $this->member->email);
    $this->travelTo($message->scheduled_at->addMinute());
    deliverCommunityTestMessage($message);
    Mail::assertNothingSent();
    expect($message->fresh()->deliveries()->count())->toBe(0);
});

test('downgrade pauses a scheduled message and preserves history', function () {
    $this->tenant->features()->create(['feature_key' => 'community_message_scheduling', 'enabled' => true]);
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, array_replace($this->input, ['scheduled_at' => '2026-10-16T12:00:00+00:00']));
    $this->tenant->features()->where('feature_key', 'community_message_scheduling')->update(['enabled' => false]);
    $this->travelTo($message->scheduled_at->addMinute());
    app(CommunityMessages::class)->dispatchDue($message->id);
    expect($message->fresh()->status)->toBe('paused');
    Mail::assertNothingSent();
});

test('invalid nonexistent local schedule times are rejected and explicit valid DST offset works', function () {
    App\Models\CommunitySettings::for($this->tenant)->fill(['timezone' => 'America/New_York'])->save();
    $this->tenant->features()->create(['feature_key' => 'community_message_scheduling', 'enabled' => true]);
    foreach (['2027-03-14T02:30:00-05:00', '2026-11-01T01:30:00', '2026-10-01T12:00:00-04:00'] as $date) {
        $this->postJson(messageTestUrl(), array_replace($this->input, ['scheduled_at' => $date]))->assertUnprocessable();
    }
    $this->postJson(messageTestUrl(), array_replace($this->input, ['scheduled_at' => '2026-11-01T01:30:00-04:00']))->assertCreated();
});

test('revoking finance access before an arrears send pauses it', function () {
    $actor = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($actor->id);
    setPermissionsTeamId($this->tenant->id);
    $actor->givePermissionTo(['send community messages', 'read finance']);
    App\Models\DuesCharge::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'membership_type_id' => $this->member->membership_type_id, 'due_on' => '2026-10-01']);
    $message = app(CommunityMessages::class)->queue($this->tenant, $actor, array_replace($this->input, ['audience' => ['kind' => 'arrears']]));
    $actor->revokePermissionTo('read finance');
    app(CommunityMessages::class)->dispatchDue($message->id);
    expect($message->fresh()->status)->toBe('paused');
    Mail::assertNothingSent();
});

test('a group archived after queueing pauses selection without losing history', function () {
    $this->tenant->features()->create(['feature_key' => 'community_group_messaging', 'enabled' => true]);
    $group = App\Models\CommunityGroup::factory()->create(['tenant_id' => $this->tenant->id]);
    $group->members()->attach($this->member->id, ['tenant_id' => $this->tenant->id]);
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, array_replace($this->input, ['audience' => ['kind' => 'groups', 'group_ids' => [$group->id]]]));
    $group->update(['archived_at' => now()]);
    app(CommunityMessages::class)->dispatchDue($message->id);
    expect($message->fresh()->status)->toBe('paused');
});
