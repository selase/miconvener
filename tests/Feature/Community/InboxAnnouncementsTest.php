<?php

declare(strict_types=1);

use App\Models\CommunityInboxItem;
use App\Models\CommunityInboxRead;
use App\Models\CommunityMessage;
use App\Models\Member;
use App\Services\Community\CommunityMessages;
use App\Services\Community\InboxWriter;
use App\Services\Community\MessagingPreferences;

require_once dirname(__DIR__, 2).'/Fixtures/community/message-test-setup.php';
beforeEach(function (): void {
    setupCommunityMessagesTest($this);
    foreach (['community_app', 'community_inbox'] as $key) {
        $this->tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
});

it('creates one recipient item independently of channel outcomes and does not require dues', function (): void {
    app(MessagingPreferences::class)->disableMarketing($this->tenant, 'sms', $this->member->phone);
    $service = app(CommunityMessages::class);
    $message = $service->queue($this->tenant, $this->owner, $this->input);
    $service->dispatchDue($message->id);
    $service->dispatchDue($message->id);
    expect(CommunityInboxItem::query()->where('member_id', $this->member->id)->count())->toBe(1)
        ->and($this->member->charges()->count())->toBe(0);
    expect(CommunityInboxItem::query()->firstOrFail()->subject)->toBeNull();
});

it('excludes fully opted-out recipients but includes an exited status audience', function (): void {
    $prefs = app(MessagingPreferences::class);
    $prefs->disableMarketing($this->tenant, 'email', $this->member->email);
    $prefs->disableMarketing($this->tenant, 'sms', $this->member->phone);
    $other = Member::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'exited']);
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, [...$this->input, 'audience' => ['kind' => 'status', 'status' => 'exited']]);
    app(CommunityMessages::class)->dispatchDue($message->id);
    expect(CommunityInboxItem::query()->pluck('member_id')->all())->toBe([$other->id]);
});

it('materialises every paged recipient once and skips creation when either app switch is off', function (): void {
    $this->tenant->features()->where('feature_key', 'email_credits')->firstOrFail()->update(['meta' => ['type' => 'limit', 'value' => 500]]);
    Member::factory()->count(101)->create(['tenant_id' => $this->tenant->id]);
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, $this->input);
    do {
        app(CommunityMessages::class)->dispatchDue($message->id);
        $message->refresh();
    } while (! $message->selection_complete);
    expect(CommunityInboxItem::query()->count())->toBe(102);
    app(CommunityMessages::class)->dispatchDue($message->id);
    expect(CommunityInboxItem::query()->count())->toBe(102);
    $this->tenant->features()->where('feature_key', 'community_app')->update(['enabled' => false]);
    $next = app(CommunityMessages::class)->queue($this->tenant, $this->owner, [...$this->input, 'idempotency_key' => (string) Illuminate\Support\Str::uuid()]);
    app(CommunityMessages::class)->dispatchDue($next->id);
    expect(CommunityInboxItem::query()->where('message_id', $next->id)->count())->toBe(0);
});

it('withdraws cancelled items and permanent withdrawal survives retries with messaging off', function (): void {
    $service = app(CommunityMessages::class);
    $message = $service->queue($this->tenant, $this->owner, $this->input);
    $service->dispatchDue($message->id);
    $service->cancel($this->tenant, $this->owner, $message->fresh());
    expect(CommunityInboxItem::query()->whereNull('withdrawn_at')->count())->toBe(0);
    $message->update(['status' => 'completed']);
    $this->tenant->features()->where('feature_key', 'community_messages')->update(['enabled' => false]);
    $service->withdraw($this->tenant, $this->owner, $message->fresh());
    expect($message->fresh()->inbox_withdrawn_at)->not->toBeNull();
});

it('suppresses small-group reads and counts recipient records rather than reader identities', function (): void {
    $message = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'completed']);
    $item = CommunityInboxItem::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'kind' => 'announcement', 'message_id' => $message->id, 'subject' => null, 'body' => null, 'source_type' => null, 'source_id' => null]);
    CommunityInboxRead::factory()->count(2)->create(['item_id' => $item->id, 'entry_key' => $message->id]);
    expect(app(InboxWriter::class)->statistics($message))->toBe(['inbox_count' => 1, 'inbox_read_count' => null]);
    CommunityInboxItem::factory()->count(9)->create(['tenant_id' => $this->tenant->id, 'kind' => 'announcement', 'message_id' => $message->id, 'subject' => null, 'body' => null, 'source_type' => null, 'source_id' => null]);
    expect(app(InboxWriter::class)->statistics($message))->toBe(['inbox_count' => 10, 'inbox_read_count' => 1]);
});

it('keeps staff archive withdrawal reachable after sending is switched off and validates confirmation', function (): void {
    $service = app(CommunityMessages::class);
    $message = $service->queue($this->tenant, $this->owner, $this->input);
    $service->dispatchDue($message->id);
    $this->tenant->features()->where('feature_key', 'community_messages')->update(['enabled' => false]);
    $this->getJson(messageTestUrl('/'.$message->id))->assertOk()->assertJsonPath('sending_enabled', false)->assertJsonPath('inbox.inbox_count', 1)->assertJsonMissingPath('inbox.reader_key');
    $this->postJson(messageTestUrl('/'.$message->id.'/withdraw'), ['reason' => 'Outdated information', 'confirmed' => false])->assertUnprocessable();
    $this->postJson(messageTestUrl('/'.$message->id.'/withdraw'), ['reason' => 'Outdated information', 'confirmed' => true])->assertOk();
    expect($message->fresh()->inbox_withdrawn_at)->not->toBeNull();
    $service->dispatchDue($message->id);
    expect(CommunityInboxItem::query()->whereNull('withdrawn_at')->count())->toBe(0);
});

it('exposes takedown only to the global superadmin and keeps transport diagnostics in staff search', function (): void {
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, $this->input);
    app(CommunityMessages::class)->dispatchDue($message->id);
    $results = app(App\Services\Notifications\MessageDeliverySearch::class)->find($this->member->email);
    expect(collect($results)->contains(fn ($row) => ($row['inbox_message_id'] ?? null) === $message->id))->toBeTrue();
    $host = mb_ltrim((string) config('session.domain'), '.');
    $url = 'http://'.$host.'/messages/'.$message->id.'/inbox-takedown';
    $this->withServerVariables(['HTTP_HOST' => $host])->postJson($url, ['reason' => 'Privacy removal', 'confirmed' => true])->assertForbidden();
    $admin = App\Models\User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $admin->assignRole(Spatie\Permission\Models\Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());
    $this->actingAs($admin)->post($url, ['reason' => 'Privacy removal', 'confirmed' => true])->assertRedirect();
    expect($message->fresh()->inbox_withdrawn_at)->not->toBeNull();
});

it('retains the announcement when SMS credits disappear after queueing', function (): void {
    $service = app(CommunityMessages::class);
    $message = $service->queue($this->tenant, $this->owner, [...$this->input, 'channels' => ['sms']]);
    $this->tenant->features()->where('feature_key', 'sms_credits')->update(['meta' => ['type' => 'limit', 'value' => 0]]);
    deliverCommunityTestMessage($message);
    expect(CommunityInboxItem::query()->where('message_id', $message->id)->whereNull('withdrawn_at')->count())->toBe(1)
        ->and($message->fresh()->deliveries()->where('status', 'accepted')->count())->toBe(0);
    Illuminate\Support\Facades\Http::assertNothingSent();
});

it('cancels between selection pages without materialising or delivering the remaining audience', function (): void {
    $this->tenant->features()->where('feature_key', 'email_credits')->update(['meta' => ['type' => 'limit', 'value' => 500]]);
    Member::factory()->count(101)->create(['tenant_id' => $this->tenant->id]);
    $service = app(CommunityMessages::class);
    $message = $service->queue($this->tenant, $this->owner, $this->input);
    $service->dispatchDue($message->id);
    expect($message->fresh()->selection_complete)->toBeFalse()
        ->and(CommunityInboxItem::query()->where('message_id', $message->id)->count())->toBe(100);
    $service->cancel($this->tenant, $this->owner, $message->fresh());
    deliverCommunityTestMessage($message);
    expect(CommunityInboxItem::query()->where('message_id', $message->id)->count())->toBe(100)
        ->and(CommunityInboxItem::query()->where('message_id', $message->id)->whereNull('withdrawn_at')->count())->toBe(0);
    Illuminate\Support\Facades\Mail::assertNothingSent();
});
