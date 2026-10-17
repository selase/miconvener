<?php

declare(strict_types=1);

use App\Models\CommunityFollower;
use App\Models\CommunityInboxItem;
use App\Models\CommunityInboxRead;
use App\Models\CommunityMessage;
use App\Services\Community\InboxQuery;
use App\Services\Community\InboxReads;
use App\Services\Community\MemberAccess;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;

require_once dirname(__DIR__, 2).'/Fixtures/community/portal-test-setup.php';
beforeEach(function (): void {
    setupCommunityPortalTest($this);
    foreach (['community_app', 'community_inbox', 'community_following'] as $key) {
        $this->tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $this->inboxUrl = $this->base.'/communities/'.$this->tenant->slug.'/inbox';
    $this->item = CommunityInboxItem::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id]);
});

it('lists only the current exact email recipient and keeps private readers uncacheable', function (): void {
    CommunityInboxItem::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->relative->id]);
    CommunityInboxItem::factory()->create(['tenant_id' => $this->other->id, 'member_id' => $this->foreign->id]);
    $result = $this->withSession($this->session->all())->getJson($this->inboxUrl)->assertOk()->assertJsonCount(1, 'entries.data')->assertJsonPath('entries.data.0.id', $this->item->id)->assertJsonMissingPath('entries.data.0.reader_key');
    expect($result->headers->get('Cache-Control'))->toContain('no-store');
    $this->getJson($this->base.'/inbox')->assertOk()->assertJsonPath('organisations.0.unread', 1);
    expect(CommunityInboxRead::query()->count())->toBe(0);
});

it('requires independent member credentials after phone verification and blocks a held number', function (): void {
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $this->member->phone, 'expires_at' => now()->addHour()->timestamp]);
    expect(app(InboxQuery::class)->counts($this->session))->toBe([]);
    app(MemberAccess::class)->issue($this->session, $this->member, $this->member->phone, 'self', null, 'independent_review');
    expect(app(InboxQuery::class)->counts($this->session)[0]['unread'])->toBe(1);
    App\Models\CommunityWrongNumberReport::factory()->create(['tenant_id' => $this->tenant->id, 'phone_hash' => PhoneVerification::digest($this->member->phone), 'status' => 'confirmed']);
    expect(app(InboxQuery::class)->counts($this->session))->toBe([]);
});

it('labels representative contexts and never marks the represented persons own read state', function (): void {
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234568', 'expires_at' => now()->addHour()->timestamp]);
    app(MemberAccess::class)->issue($this->session, $this->member, '+233241234568', 'representative', null, 'independent_review');
    $entry = app(InboxQuery::class)->entries($this->session, $this->tenant, [])->items()[0];
    expect($entry['label'])->toContain($this->member->name, 'representative');
    app(InboxReads::class)->mark($this->session, $this->tenant, $this->item->id, $entry['context']);
    expect(app(InboxQuery::class)->counts($this->session)[0]['unread'])->toBe(0);
    $this->session->forget(PhoneVerification::SESSION_KEY);
    $this->session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $this->member->email, 'expires_at' => now()->addHour()->timestamp]);
    expect(app(InboxQuery::class)->counts($this->session)[0]['unread'])->toBe(1);
});

it('shares self read state across independently proved email and rotated phone credentials', function (): void {
    app(InboxReads::class)->mark($this->session, $this->tenant, $this->item->id, 'self');
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $this->member->phone, 'expires_at' => now()->addHour()->timestamp]);
    app(MemberAccess::class)->issue($this->session, $this->member, $this->member->phone, 'self', null, 'verified_member_email');
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    expect(app(InboxQuery::class)->counts($this->session)[0]['unread'])->toBe(0);
    app(MemberAccess::class)->issue($this->session, $this->member, $this->member->phone, 'self', null, 'independent_review');
    expect(app(InboxQuery::class)->counts($this->session)[0]['unread'])->toBe(0);
});

it('immediately revokes removed email access and refuses guessed tenant or recipient identifiers', function (): void {
    $foreign = CommunityInboxItem::factory()->create(['tenant_id' => $this->other->id, 'member_id' => $this->foreign->id]);
    $this->withSession($this->session->all())->getJson($this->inboxUrl.'/'.$foreign->id)->assertNotFound();
    $this->postJson($this->inboxUrl.'/'.$foreign->id.'/read', ['context' => 'self'])->assertNotFound();
    $this->member->update(['email' => 'replacement@example.test']);
    $this->getJson($this->inboxUrl.'/'.$this->item->id)->assertNotFound();
    $this->postJson($this->inboxUrl.'/'.$this->item->id.'/read', ['context' => 'self'])->assertNotFound();
});

it('merges a member and follower announcement before counting and marks every own reader key', function (): void {
    $this->item->delete();
    $follower = CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'address' => $this->member->email, 'consented_at' => now()->subDay()]);
    $message = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'completed']);
    $fields = ['tenant_id' => $this->tenant->id, 'kind' => 'announcement', 'message_id' => $message->id, 'subject' => null, 'body' => null, 'source_type' => null, 'source_id' => null];
    CommunityInboxItem::factory()->create([...$fields, 'member_id' => $this->member->id]);
    CommunityInboxItem::factory()->create([...$fields, 'recipient_type' => 'follower', 'member_id' => null, 'follower_id' => $follower->id]);
    $response = $this->withSession($this->session->all())->getJson($this->inboxUrl)->assertOk()->assertJsonCount(1, 'entries.data');
    $id = $response->json('entries.data.0.id');
    $this->getJson($this->inboxUrl.'/'.$id.'?context=self')->assertOk();
    expect(CommunityInboxRead::query()->count())->toBe(0);
    $this->postJson($this->inboxUrl.'/'.$id.'/read', ['context' => 'self'])->assertOk();
    expect(CommunityInboxRead::query()->count())->toBe(2);
    $this->getJson($this->base.'/inbox')->assertJsonPath('organisations.0.unread', 0);
});

it('paginates grouped entries stably and mark-all changes only visible authorised entries', function (): void {
    CommunityInboxItem::factory()->count(24)->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id]);
    CommunityInboxItem::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->relative->id]);
    $one = $this->withSession($this->session->all())->getJson($this->inboxUrl)->assertOk()->assertJsonCount(20, 'entries.data')->assertJsonPath('entries.total', 25);
    $two = $this->getJson($this->inboxUrl.'?page=2')->assertOk()->assertJsonCount(5, 'entries.data');
    expect(array_intersect(array_column($one->json('entries.data'), 'id'), array_column($two->json('entries.data'), 'id')))->toBe([]);
    $this->postJson($this->inboxUrl.'/read-all')->assertOk();
    expect(CommunityInboxRead::query()->count())->toBe(25);
});

it('hides unavailable message states without changing their stored read history', function (string $status, bool $visible): void {
    $this->item->delete();
    $message = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id, 'status' => $status]);
    CommunityInboxItem::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'kind' => 'announcement', 'message_id' => $message->id, 'subject' => null, 'body' => null, 'source_type' => null, 'source_id' => null]);
    expect(app(InboxQuery::class)->entries($this->session, $this->tenant, [])->total())->toBe($visible ? 1 : 0);
})->with([['draft', false], ['scheduled', false], ['paused', false], ['cancelled', false], ['sending', true], ['completed', true], ['partial', true]]);

it('hides and restores existing items when an app switch changes and blocks withdrawn direct links', function (): void {
    $this->withSession($this->session->all())->getJson($this->inboxUrl.'/'.$this->item->id)->assertOk();
    $this->tenant->features()->where('feature_key', 'community_app')->update(['enabled' => false]);
    $this->getJson($this->inboxUrl.'/'.$this->item->id)->assertNotFound();
    $this->tenant->features()->where('feature_key', 'community_app')->update(['enabled' => true]);
    $this->getJson($this->inboxUrl.'/'.$this->item->id)->assertOk();
    $this->item->update(['withdrawn_at' => now()]);
    $this->getJson($this->inboxUrl.'/'.$this->item->id)->assertNotFound();
    $this->postJson($this->inboxUrl.'/'.$this->item->id.'/read', ['context' => 'self'])->assertNotFound();
});

it('never gives a follower pre-consent history and unfollow hides it without phone-only recovery', function (): void {
    $this->member->update(['email' => 'other@example.test']);
    $follower = CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'address' => 'a@example.test', 'consented_at' => now()->subHour()]);
    $message = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'completed']);
    $item = CommunityInboxItem::factory()->create(['tenant_id' => $this->tenant->id, 'recipient_type' => 'follower', 'member_id' => null, 'follower_id' => $follower->id, 'kind' => 'announcement', 'message_id' => $message->id, 'subject' => null, 'body' => null, 'source_type' => null, 'source_id' => null, 'available_at' => now()->subHours(2)]);
    expect(app(InboxQuery::class)->entries($this->session, $this->tenant, [])->total())->toBe(0);
    $item->update(['available_at' => now()]);
    expect(app(InboxQuery::class)->entries($this->session, $this->tenant, [])->total())->toBe(1);
    $follower->update(['unfollowed_at' => now()]);
    expect(app(InboxQuery::class)->counts($this->session))->toBe([]);
});

it('requires POST with a current context and throttles repeated read mutations', function (): void {
    $url = $this->inboxUrl.'/'.$this->item->id.'/read';
    $this->withSession($this->session->all())->getJson($url)->assertStatus(405);
    $this->postJson($url, ['context' => 'other-person', 'reader_key' => 'forged'])->assertUnprocessable();
    for ($i = 0; $i < 31; $i++) {
        $response = $this->postJson($url, ['context' => 'self']);
    }
    $response->assertTooManyRequests();
    expect(CommunityInboxRead::query()->count())->toBe(1);
});

it('groups duplicate member and follower items before the twenty-entry page boundary', function (): void {
    $this->item->delete();
    $follower = CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'address' => $this->member->email, 'consented_at' => now()->subDay()]);
    foreach (range(1, 25) as $number) {
        $message = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'completed', 'subject' => 'Announcement '.$number]);
        $fields = ['tenant_id' => $this->tenant->id, 'kind' => 'announcement', 'message_id' => $message->id, 'subject' => null, 'body' => null, 'source_type' => null, 'source_id' => null];
        CommunityInboxItem::factory()->create([...$fields, 'member_id' => $this->member->id]);
        CommunityInboxItem::factory()->create([...$fields, 'recipient_type' => 'follower', 'member_id' => null, 'follower_id' => $follower->id]);
    }
    $this->withSession($this->session->all())->getJson($this->inboxUrl)->assertOk()->assertJsonCount(20, 'entries.data')->assertJsonPath('entries.total', 25);
    $this->getJson($this->inboxUrl.'?page=2')->assertJsonCount(5, 'entries.data');
});

it('suspends representative access immediately after the member contact changes', function (): void {
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234568', 'expires_at' => now()->addHour()->timestamp]);
    app(MemberAccess::class)->issue($this->session, $this->member, '+233241234568', 'representative', null, 'independent_review');
    expect(app(InboxQuery::class)->counts($this->session))->not->toBeEmpty();
    $this->member->update(['email' => 'new-contact@example.test']);
    expect(app(InboxQuery::class)->counts($this->session))->toBe([]);
});

it('keeps read-all away from paused or future items and hides a suspended organisation', function (): void {
    $message = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'paused']);
    CommunityInboxItem::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'kind' => 'announcement', 'message_id' => $message->id, 'subject' => null, 'body' => null, 'source_type' => null, 'source_id' => null]);
    CommunityInboxItem::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'available_at' => now()->addDay()]);
    $this->withSession($this->session->all())->postJson($this->inboxUrl.'/read-all')->assertOk();
    expect(CommunityInboxRead::query()->count())->toBe(1);
    $this->tenant->update(['status' => App\Enum\TenantStatusEnum::BANNED]);
    $this->getJson($this->inboxUrl.'/'.$this->item->id)->assertNotFound();
    $this->postJson($this->inboxUrl.'/'.$this->item->id.'/read', ['context' => 'self'])->assertNotFound();
});

it('uses the full consent instant and never restores pre-refollow messages', function (): void {
    $this->item->delete();
    $this->member->update(['email' => 'another@example.test']);
    $now = Carbon\CarbonImmutable::parse('2026-10-17 09:00:00.200000 UTC');
    $this->travelTo($now->addSecond());
    $follower = CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'address' => 'a@example.test', 'consented_at' => $now]);
    $message = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'completed']);
    $item = CommunityInboxItem::factory()->create(['tenant_id' => $this->tenant->id, 'recipient_type' => 'follower', 'member_id' => null, 'follower_id' => $follower->id, 'kind' => 'announcement', 'message_id' => $message->id, 'subject' => null, 'body' => null, 'source_type' => null, 'source_id' => null, 'available_at' => $now->subMicroseconds(100000)]);
    expect(app(InboxQuery::class)->entries($this->session, $this->tenant, [])->total())->toBe(0);
    $item->update(['available_at' => $now->addMicroseconds(100000)]);
    expect($item->fresh()->available_at->format('u'))->toBe('300000');
    expect(app(InboxQuery::class)->entries($this->session, $this->tenant, [])->total())->toBe(1);
    $follower->update(['consented_at' => $now->addSeconds(2)]);
    expect(app(InboxQuery::class)->entries($this->session, $this->tenant, [])->total())->toBe(0);
});

it('keeps an enabled organisation discoverable when its inbox is empty', function (): void {
    $this->item->delete();
    $this->withSession($this->session->all())->getJson($this->base.'/inbox')->assertOk()->assertJsonPath('organisations.0.slug', $this->tenant->slug)->assertJsonPath('organisations.0.unread', 0);
});

it('prefers a valid self credential to a representative grant regardless of issuance order', function (array $roles): void {
    app(InboxReads::class)->mark($this->session, $this->tenant, $this->item->id, 'self');
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $this->member->phone, 'expires_at' => now()->addHour()->timestamp]);
    foreach ($roles as $role) {
        app(MemberAccess::class)->issue($this->session, $this->member, $this->member->phone, $role, null, 'independent_review');
    }
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    $entry = app(InboxQuery::class)->entries($this->session, $this->tenant, [])->items()[0];
    expect($entry['context'])->toBe('self')->and($entry['read'])->toBeTrue();
    App\Models\CommunityAccessCredential::withoutGlobalScopes()->where('member_id', $this->member->id)->where('role', 'self')->update(['revoked_at' => now()]);
    $fallback = app(InboxQuery::class)->entries($this->session, $this->tenant, [])->items()[0];
    expect($fallback['context'])->toBe('representative:'.$this->member->id)->and($fallback['read'])->toBeFalse();
})->with([
    'representative then self' => [['representative', 'self']],
    'self then representative' => [['self', 'representative']],
]);

it('compares visibility using the full current instant without showing a future same-second item', function (): void {
    $instant = Carbon\CarbonImmutable::parse('2026-10-17 09:00:00.500000 UTC');
    $this->travelTo($instant);
    $this->item->update(['available_at' => $instant]);
    CommunityInboxItem::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'available_at' => $instant->addMicroseconds(100000)]);
    expect(app(InboxQuery::class)->entries($this->session, $this->tenant, [])->total())->toBe(1);
});
