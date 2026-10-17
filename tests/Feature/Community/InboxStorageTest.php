<?php

declare(strict_types=1);

use App\Models\CommunityInboxItem;
use App\Models\CommunityInboxRead;
use App\Models\CommunityMessage;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Community\InboxReaderKey;
use App\Services\Community\InboxRetention;
use App\Services\Community\InboxRollout;
use Illuminate\Database\QueryException;

it('requires both explicit rollout flags and an active organisation', function (): void {
    $tenant = Tenant::factory()->create();
    $gate = app(InboxRollout::class);
    expect($gate->enabled($tenant))->toBeFalse();
    $this->artisan('community:enable-inbox '.$tenant->slug)->assertSuccessful();
    expect($gate->enabled($tenant->fresh()))->toBeFalse();
    $this->artisan('community:enable-app '.$tenant->slug)->assertSuccessful();
    expect($gate->enabled($tenant->fresh()))->toBeTrue();
    $this->artisan('community:enable-inbox '.$tenant->slug)->assertSuccessful();
    expect($tenant->features()->where('feature_key', 'community_inbox')->count())->toBe(1);
    $this->artisan('community:enable-inbox '.$tenant->slug.' --off')->assertSuccessful();
    expect($gate->enabled($tenant->fresh()))->toBeFalse();
});

it('rejects a cross-tenant recipient at the database boundary', function (): void {
    $item = CommunityInboxItem::factory()->make();
    $member = Member::factory()->create();
    expect(fn () => $item->forceFill(['member_id' => $member->id])->save())->toThrow(QueryException::class);
});

it('rejects malformed recipient and content combinations', function (array $changes): void {
    $item = CommunityInboxItem::factory()->make();
    expect(fn () => $item->forceFill($changes)->save())->toThrow(QueryException::class);
})->with([
    'missing recipient' => [['member_id' => null]],
    'wrong recipient type' => [['recipient_type' => 'follower']],
    'notice without snapshot' => [['subject' => null]],
    'notice with message' => [['kind' => 'announcement']],
]);

it('keeps notice sources unique for a member without merging their currencies or types', function (): void {
    $item = CommunityInboxItem::factory()->create();
    expect(fn () => CommunityInboxItem::factory()->create($item->only(['tenant_id', 'member_id', 'kind', 'source_type', 'source_id'])))->toThrow(QueryException::class);
});

it('derives stable private reader keys without storing raw identifiers', function (): void {
    $keys = app(InboxReaderKey::class);
    expect($keys->forSubject('member:123:self'))->toHaveLength(64)
        ->toBe($keys->forSubject('member:123:self'))
        ->not->toBe($keys->forSubject('member:123:rep:phone-digest'));
});

it('prunes expired items and their reads but preserves a surviving recipient read', function (): void {
    $expired = CommunityInboxItem::factory()->create(['available_at' => now()->subMonths(25)]);
    $current = CommunityInboxItem::factory()->create();
    foreach ([$expired, $current] as $item) {
        CommunityInboxRead::factory()->create(['item_id' => $item->id, 'entry_key' => $item->id]);
    }
    expect(app(InboxRetention::class)->prune(now()->toImmutable()))->toBe(1)
        ->and(CommunityInboxItem::query()->count())->toBe(1)
        ->and(CommunityInboxRead::query()->count())->toBe(1);
    $current->member()->delete();
    expect(CommunityInboxItem::query()->count())->toBe(0)
        ->and(CommunityInboxRead::query()->count())->toBe(0);
});

it('never enables inbox for a suspended organisation or another tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    foreach (['community_app', 'community_inbox'] as $key) {
        $tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $tenant->update(['status' => App\Enum\TenantStatusEnum::BANNED]);
    expect(app(InboxRollout::class)->enabled($tenant))->toBeFalse()
        ->and(app(InboxRollout::class)->enabled($other))->toBeFalse();
});

it('rejects cross-tenant announcement sources', function (): void {
    $item = CommunityInboxItem::factory()->make();
    $message = CommunityMessage::factory()->create();
    expect(fn () => $item->forceFill(['kind' => 'announcement', 'message_id' => $message->id, 'source_type' => null, 'source_id' => null, 'subject' => null, 'body' => null])->save())->toThrow(QueryException::class);
});

it('retains read state for another item of the same announcement after erasure', function (): void {
    $message = CommunityMessage::factory()->create();
    $items = collect([1, 2])->map(fn () => CommunityInboxItem::factory()->create(['tenant_id' => $message->tenant_id, 'kind' => 'announcement', 'message_id' => $message->id, 'source_type' => null, 'source_id' => null, 'subject' => null, 'body' => null]));
    foreach ($items as $item) {
        CommunityInboxRead::factory()->create(['item_id' => $item->id, 'entry_key' => $message->id]);
    }
    $items->first()->member()->delete();
    expect(CommunityInboxRead::query()->count())->toBe(1);
    expect(app(InboxRetention::class)->prune(now()->toImmutable()))->toBe(0);
});
