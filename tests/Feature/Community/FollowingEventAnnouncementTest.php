<?php

declare(strict_types=1);
use App\Models\CommunityFollower;
use App\Models\CommunityMessage;
use App\Models\Event;
use App\Services\Community\CommunityMessages;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

require_once dirname(__DIR__, 2).'/Fixtures/community/message-test-setup.php';
beforeEach(function (): void {
    setupCommunityMessagesTest($this);
    Artisan::call('community:following-enable', ['tenant' => $this->tenant->slug]);
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug, '--off' => true]);
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id, 'status' => Event::STATUS_PUBLISHED, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'address' => 'follower@example.test', 'channel' => 'email']);
    $this->input['source_event_id'] = $this->event->id;
    unset($this->input['audience']);
    $this->url = 'http://'.eventSubdomainHost('message-qa').'/community/followers/announcements';
});
it('previews sends lists shows and cancels a reviewed event announcement with Community off', function (): void {
    $this->postJson($this->url.'/preview', $this->input)->assertOk()->assertJsonPath('counts.email', 1)->assertJsonStructure(['event' => ['url', 'name']]);
    $this->postJson($this->url, $this->input)->assertCreated();
    $message = CommunityMessage::firstOrFail();
    expect($message->purpose)->toBe('following_event')->and($message->source_event_id)->toBe($this->event->id)->and($message->audience)->toBe(['kind' => 'followers']);
    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'messages.data');
    $this->getJson($this->url.'/'.$message->id)->assertOk();
    $this->postJson($this->url.'/'.$message->id.'/cancel')->assertOk();
    expect($message->fresh()->status)->toBe('cancelled');
    $this->getJson(messageTestUrl())->assertNotFound();
});
it('actually delivers to followers without enabling general Community messaging', function (): void {
    $this->postJson($this->url, $this->input)->assertCreated();
    $message = CommunityMessage::firstOrFail();
    deliverCommunityTestMessage($message);
    Mail::assertSent(App\Mail\CommunityMessage::class, fn ($mail): bool => $mail->hasTo('follower@example.test'));
    Mail::assertSentCount(1);
});
it('refuses foreign unpublished ended sources missing authority or Free scheduling', function (string $case): void {
    if ($case === 'foreign') {
        $this->input['source_event_id'] = Event::factory()->create()->id;
    }
    if ($case === 'draft') {
        $this->event->update(['status' => Event::STATUS_DRAFT]);
    }
    if ($case === 'ended') {
        $this->event->update(['ends_at' => now()->subMinute()]);
    }
    if ($case === 'authority') {
        $this->input['confirm_authority'] = false;
    }
    if ($case === 'schedule') {
        $this->input['scheduled_at'] = now()->addHour()->format('Y-m-d\TH:iP');
    }
    $response = $this->postJson($this->url, $this->input);
    expect(in_array($response->status(), [404, 422], true))->toBeTrue($response->getContent());
    expect(CommunityMessage::count())->toBe(0);
})->with(['foreign', 'draft', 'ended', 'authority', 'schedule']);
it('pauses unsent follower announcements when their event becomes unavailable', function (): void {
    $this->postJson($this->url, $this->input)->assertCreated();
    $message = CommunityMessage::firstOrFail();
    $this->event->update(['status' => Event::STATUS_DRAFT]);
    app(CommunityMessages::class)->dispatchDue($message->id);
    expect($message->fresh()->status)->toBe('paused');
    Mail::assertNothingSent();
});
it('checks send permission and keeps ordinary messages outside Following history', function (): void {
    $ordinary = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->getJson($this->url.'/'.$ordinary->id)->assertNotFound();
    $reader = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($reader->id);
    $this->actingAs($reader)->postJson($this->url, $this->input)->assertForbidden();
});

it('reviews the real event name and published URL', function (): void {
    $this->postJson($this->url.'/preview', $this->input)->assertOk()->assertJsonPath('event.name', $this->event->name);
});

it('rechecks event publication after claiming a delivery before transport', function (): void {
    $this->postJson($this->url, $this->input)->assertCreated();
    $message = CommunityMessage::firstOrFail();
    $service = app(CommunityMessages::class);
    $service->dispatchDue($message->id);
    $event = $this->event;
    App\Models\CommunityMessageDelivery::updated(function ($delivery) use ($event): void {
        if ($delivery->wasChanged('status') && $delivery->status === 'claimed') {
            $event->update(['status' => Event::STATUS_DRAFT]);
        }
    });
    $service->deliver($message->id, [$message->deliveries()->firstOrFail()->id], 'email');
    Mail::assertNothingSent();
    expect($message->deliveries()->firstOrFail()->status)->toBe('skipped');
});

it('does not let the general Community endpoint repurpose or edit follower announcements', function (): void {
    $this->postJson($this->url, $this->input)->assertCreated();
    $message = CommunityMessage::firstOrFail();
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    $general = $this->input;
    $general['audience'] = ['kind' => 'all_members'];
    $this->postJson(messageTestUrl(), $general)->assertUnprocessable();
    unset($general['source_event_id']);
    $general['idempotency_key'] = (string) Illuminate\Support\Str::uuid();
    $this->patchJson(messageTestUrl('/'.$message->id), $general)->assertNotFound();
    $this->postJson(messageTestUrl('/'.$message->id.'/cancel'))->assertNotFound();
});

it('does not schedule a follower invitation after its event has ended', function (): void {
    $this->tenant->features()->create(['feature_key' => 'community_message_scheduling', 'enabled' => true]);
    $this->input['scheduled_at'] = now()->addDays(3)->format('Y-m-d\TH:iP');
    $this->postJson($this->url, $this->input)->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
});

it('retains the announcement and pauses unsent work after its source event is permanently removed', function (): void {
    $this->postJson($this->url, $this->input)->assertCreated();
    $message = CommunityMessage::firstOrFail();
    $this->event->forceDelete();
    expect($message->fresh()->source_event_id)->toBeNull();
    app(CommunityMessages::class)->dispatchDue($message->id);
    expect($message->fresh()->status)->toBe('paused');
    Mail::assertNothingSent();
});

it('searches only current-tenant published non-ended announcement events including private events', function (): void {
    $this->event->update(['name' => 'Search Private', 'visibility' => Event::VISIBILITY_PRIVATE]);
    Event::factory()->published()->create(['name' => 'Search Foreign', 'ends_at' => now()->addDay()]);
    Event::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Search Draft', 'ends_at' => now()->addDay()]);
    $this->getJson($this->url.'/events?search=Search')->assertOk()->assertJsonCount(1, 'events.data')->assertJsonPath('events.data.0.id', $this->event->id);
});

it('pauses soft-deleted source events before dispatch and immediately before transport', function (bool $afterClaim): void {
    $this->postJson($this->url, $this->input)->assertCreated();
    $message = CommunityMessage::firstOrFail();
    $service = app(CommunityMessages::class);
    if ($afterClaim) {
        $service->dispatchDue($message->id);
        $event = $this->event;
        App\Models\CommunityMessageDelivery::updated(function ($delivery) use ($event): void {
            if ($delivery->wasChanged('status') && $delivery->status === 'claimed') {
                $event->delete();
            }
        });
        $service->deliver($message->id, [$message->deliveries()->firstOrFail()->id], 'email');
        expect($message->deliveries()->firstOrFail()->status)->toBe('skipped');
    } else {
        $this->event->delete();
        $service->dispatchDue($message->id);
        expect($message->fresh()->status)->toBe('paused');
    }
    Mail::assertNothingSent();
})->with([false, true]);
