<?php

declare(strict_types=1);

use App\Mail\CommunityMessage as MessageMail;
use App\Models\Member;
use App\Services\Community\CommunityMessages;
use App\Services\Community\MessagingPreferences;
use App\Services\Sms\SmsAllowance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require_once dirname(__DIR__, 2).'/Fixtures/community/message-test-setup.php';
beforeEach(fn () => setupCommunityMessagesTest($this));

test('a shared address gets one email and one SMS and retries do not resend', function () {
    Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => $this->member->email, 'phone' => $this->member->phone]);
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, array_replace($this->input, ['channels' => ['email', 'sms']]));
    deliverCommunityTestMessage($message);
    deliverCommunityTestMessage($message);
    Mail::assertSent(MessageMail::class, 1);
    Http::assertSentCount(1);
    expect($message->fresh()->deliveries()->count())->toBe(2)->and($message->fresh()->status)->toBe('completed');
    expect(app(SmsAllowance::class)->remaining($this->tenant))->toBe(99);
    Mail::assertSent(MessageMail::class, fn ($mail) => str_contains($mail->render(), 'Stop announcements'));
});

test('unsubscribe and contact changes after selection suppress untouched deliveries', function (string $change) {
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, $this->input);
    app(CommunityMessages::class)->dispatchDue($message->id);
    if ($change === 'unsubscribe') {
        app(MessagingPreferences::class)->disableMarketing($this->tenant, 'email', $this->member->email);
    } else {
        $this->member->update(['email' => 'changed@example.test']);
    }
    app(CommunityMessages::class)->deliver($message->id, $message->deliveries()->pluck('id')->all(), 'email');
    Mail::assertNothingSent();
    expect($message->fresh()->deliveries()->where('status', 'skipped')->count())->toBe(1);
})->with(['unsubscribe', 'contact']);

test('cancellation stops unsent recipients and exposes honest status', function () {
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, $this->input);
    app(CommunityMessages::class)->dispatchDue($message->id);
    app(CommunityMessages::class)->cancel($this->tenant, $this->owner, $message);
    app(CommunityMessages::class)->deliver($message->id, $message->deliveries()->pluck('id')->all(), 'email');
    Mail::assertNothingSent();
    expect($message->fresh()->status)->toBe('cancelled');
});

test('unknown SMS is held and never automatically replayed', function () {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['messaging.test/*' => Http::response([], 500)]);
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, array_replace($this->input, ['channels' => ['sms']]));
    deliverCommunityTestMessage($message);
    deliverCommunityTestMessage($message);
    Http::assertSentCount(1);
    expect($message->fresh()->deliveries()->first()->status)->toBe('unknown')->and($message->fresh()->status)->toBe('partial')
        ->and(app(SmsAllowance::class)->remaining($this->tenant))->toBe(99);
});

test('confirmed provider refusal refunds once and completion is partial', function () {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['messaging.test/*' => Http::response(['message' => 'Refused'], 422)]);
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, array_replace($this->input, ['channels' => ['sms']]));
    deliverCommunityTestMessage($message);
    expect($message->fresh()->deliveries()->first()->status)->toBe('failed')->and(app(SmsAllowance::class)->remaining($this->tenant))->toBe(100);
});

test('new members after processing starts are excluded from the running send', function () {
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, $this->input);
    app(CommunityMessages::class)->dispatchDue($message->id);
    $this->travel(1)->seconds();
    Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'late@example.test']);
    deliverCommunityTestMessage($message);
    Mail::assertSent(MessageMail::class, 1);
});

test('a delayed duplicate delivery job cannot rewrite completed history after rollout withdrawal', function () {
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, $this->input);
    deliverCommunityTestMessage($message);
    $this->tenant->features()->where('feature_key', 'community_messages')->update(['enabled' => false]);
    app(CommunityMessages::class)->deliver($message->id, $message->deliveries()->pluck('id')->all(), 'email');
    expect($message->fresh()->status)->toBe('completed');
    Mail::assertSent(MessageMail::class, 1);
});

test('a large audience is selected in durable pages of at most one hundred', function () {
    $this->tenant->features()->where('feature_key', 'email_credits')->update(['meta' => ['type' => 'limit', 'value' => 1000]]);
    Member::factory()->count(100)->create(['tenant_id' => $this->tenant->id]);
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, $this->input);
    app(CommunityMessages::class)->dispatchDue($message->id);
    expect($message->fresh()->selection_complete)->toBeFalse()->and($message->deliveries()->count())->toBe(100);
    app(CommunityMessages::class)->dispatchDue($message->id);
    expect($message->fresh()->selection_complete)->toBeTrue()->and($message->deliveries()->count())->toBe(101);
    Mail::assertNothingSent();
});

test('email transport exceptions retain credit and never replay automatically', function () {
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, $this->input);
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Transport timeout'));
    deliverCommunityTestMessage($message);
    deliverCommunityTestMessage($message);
    expect($message->deliveries()->first()->status)->toBe('unknown')
        ->and(app(App\Services\Billing\EmailAllowance::class)->remaining($this->tenant))->toBe(99);
});

test('the recovery dispatcher marks interrupted claims unknown and keeps reservations held', function () {
    $message = app(CommunityMessages::class)->queue($this->tenant, $this->owner, array_replace($this->input, ['channels' => ['sms']]));
    app(CommunityMessages::class)->dispatchDue($message->id);
    $delivery = $message->deliveries()->first();
    $reservation = app(App\Services\Community\CommunitySmsBudget::class)->reserve($this->tenant, 'community_message', $delivery->id, Carbon\CarbonImmutable::now());
    $delivery->update(['status' => 'claimed', 'claimed_at' => now()->subMinutes(3), 'sms_reservation_id' => $reservation->id, 'credit_receipt_id' => $reservation->credit_receipt_id]);
    Illuminate\Support\Facades\Artisan::call('community:messages-dispatch');
    expect($delivery->fresh()->status)->toBe('unknown')->and($reservation->fresh()->reason)->toBe('outcome_unknown')
        ->and(app(SmsAllowance::class)->remaining($this->tenant))->toBe(99);
});
