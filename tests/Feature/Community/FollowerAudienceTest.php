<?php

declare(strict_types=1);
use App\Models\CommunityFollower;
use App\Services\Community\AudienceResolver;
use App\Services\Community\CommunityMessages;
use App\Services\Community\MessagingPreferences;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

require_once dirname(__DIR__, 2).'/Fixtures/community/message-test-setup.php';
beforeEach(function (): void {
    setupCommunityMessagesTest($this);
    Artisan::call('community:following-enable', ['tenant' => $this->tenant->slug]);
});
it('keeps follower-only audiences separate and normalises overlap per channel', function (): void {
    $resolver = app(AudienceResolver::class);
    expect(iterator_count($resolver->resolve($this->tenant, ['kind' => 'followers'], 'email')))->toBe(0);
    CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'address' => ' AMA@example.test ', 'channel' => 'email']);
    CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'address' => 'other@example.test', 'channel' => 'email']);
    CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'address' => '0241234567', 'channel' => 'sms']);
    expect(iterator_count($resolver->resolve($this->tenant, ['kind' => 'followers'], 'email')))->toBe(2)
        ->and(iterator_count($resolver->resolve($this->tenant, ['kind' => 'members_and_followers'], 'email')))->toBe(2)
        ->and(iterator_count($resolver->resolve($this->tenant, ['kind' => 'members_and_followers'], 'sms')))->toBe(1);
    expect(app(App\Services\Community\MemberAudienceSource::class)->contains($this->tenant, ['kind' => 'followers'], 'email', $this->member->email))->toBeFalse();
});
it('rechecks withdrawal and Following rollout at actual delivery and excludes later follows', function (string $change): void {
    $follower = CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'address' => 'follower@example.test', 'channel' => 'email', 'consented_at' => now()->subHour()]);
    $service = app(CommunityMessages::class);
    $message = $service->queue($this->tenant, $this->owner, array_replace($this->input, ['audience' => ['kind' => 'followers']]));
    $service->dispatchDue($message->id);
    expect($message->deliveries()->count())->toBe(1);
    $this->travel(2)->seconds();
    CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'address' => 'later@example.test', 'channel' => 'email']);
    if ($change === 'withdraw') {
        app(MessagingPreferences::class)->disableMarketing($this->tenant, 'email', $follower->address);
    }
    if ($change === 'off') {
        Artisan::call('community:following-enable', ['tenant' => $this->tenant->slug, '--off' => true]);
    }
    foreach ($message->deliveries()->pluck('id') as $id) {
        $service->deliver($message->id, [$id], 'email');
    }
    if ($change === 'none') {
        Mail::assertSent(App\Mail\CommunityMessage::class, 1);
    } else {
        Mail::assertNothingSent();
    }
    expect($message->deliveries()->count())->toBe(1);
})->with(['none', 'withdraw', 'off']);
it('keeps new follows out of a multi-page send snapshot', function (): void {
    CommunityFollower::factory()->count(101)->create(['tenant_id' => $this->tenant->id, 'channel' => 'email', 'consented_at' => now()->subHour()]);
    $this->tenant->features()->where('feature_key', 'email_credits')->update(['meta' => ['type' => 'limit', 'value' => 1000]]);
    $service = app(CommunityMessages::class);
    $message = $service->queue($this->tenant, $this->owner, array_replace($this->input, ['audience' => ['kind' => 'followers']]));
    $service->dispatchDue($message->id);
    expect($message->fresh()->selection_complete)->toBeFalse();
    $this->travel(2)->seconds();
    CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'address' => 'new@example.test', 'channel' => 'email']);
    do {
        $service->dispatchDue($message->id);
    } while (! $message->fresh()->selection_complete);
    expect($message->deliveries()->count())->toBe(101)->and($message->deliveries()->where('address_hash', app(MessagingPreferences::class)->hash('email', 'new@example.test'))->exists())->toBeFalse();
});

it('excludes a follow made later in the same second from a paged send', function (): void {
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-10-15 12:00:00.100000'));
    CommunityFollower::factory()->count(101)->create(['tenant_id' => $this->tenant->id, 'channel' => 'email', 'consented_at' => now()->subHour()]);
    $this->tenant->features()->where('feature_key', 'email_credits')->update(['meta' => ['type' => 'limit', 'value' => 1000]]);
    $service = app(CommunityMessages::class);
    $message = $service->queue($this->tenant, $this->owner, array_replace($this->input, ['audience' => ['kind' => 'followers']]));
    $service->dispatchDue($message->id);
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-10-15 12:00:00.500000'));
    CommunityFollower::factory()->create(['tenant_id' => $this->tenant->id, 'id' => 'ffffffff-ffff-4fff-bfff-ffffffffffff', 'address' => 'same-second@example.test', 'channel' => 'email']);
    do {
        $service->dispatchDue($message->id);
    } while (! $message->fresh()->selection_complete);
    expect($message->deliveries()->count())->toBe(101);
});

it('delivers to a legacy normalised member contact when included alongside followers', function (): void {
    $this->member->update(['email' => ' AMA@example.test ']);
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, array_replace($this->input, ['audience' => ['kind' => 'members_and_followers']]));
    deliverCommunityTestMessage($message);
    Mail::assertSent(App\Mail\CommunityMessage::class, fn ($mail): bool => $mail->hasTo('ama@example.test'));
});
