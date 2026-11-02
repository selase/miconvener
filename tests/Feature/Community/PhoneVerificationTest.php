<?php

declare(strict_types=1);

use App\Contracts\SmsGateway;
use App\Models\PlatformPhoneAccessCode;
use App\Models\PlatformPhoneSendReservation;
use App\Services\Community\PhoneSignInBudget;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Sms\SmsResult;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['attendee_portal.phone.enabled' => true, 'attendee_portal.phone.daily_cap' => 500]);
    $gateway = Mockery::mock(SmsGateway::class);
    $gateway->shouldReceive('isConfigured')->andReturn(true);
    $gateway->shouldReceive('send')->andReturnUsing(function (array $messages): array {
        $this->sms = $messages;

        return [$messages[0]->reference => SmsResult::accepted('fake-provider')];
    });
    app()->instance(SmsGateway::class, $gateway);
});

it('verifies phone separately with a fixed lifetime and consumes the code once', function (): void {
    $service = app(PhoneVerification::class);
    $result = phoneProofTestSend($service, '024 123 4567', '192.0.2.1', null);
    expect($result['status'])->toBe('allowed');
    $challenge = PlatformPhoneAccessCode::query()->firstOrFail();
    preg_match('/\b([0-9]{6})\b/', $this->sms[0]->text, $matches);
    expect($challenge->getRawOriginal('phone'))->not->toContain('241234567')
        ->and(Hash::check($matches[1], $challenge->code_hash))->toBeTrue();
    $session = app('session.store');
    expect($service->confirm($session, '+233241234567', $matches[1], '192.0.2.1'))->toBeTrue()
        ->and($service->verifiedPhone($session))->toBe('+233241234567')
        ->and(app(PlatformAttendeeVerification::class)->verifiedEmail($session))->toBeNull()
        ->and($service->confirm($session, '+233241234567', $matches[1], '192.0.2.1'))->toBeFalse();
    $this->travel(12)->hours();
    expect($service->verifiedPhone($session))->toBeNull();
});

it('blocks expired codes and guesses even across a resend', function (): void {
    $service = app(PhoneVerification::class);
    phoneProofTestSend($service, '+233241234567', '192.0.2.2', null);
    $session = app('session.store');
    for ($i = 0; $i < 5; $i++) {
        expect($service->confirm($session, '+233241234567', 'wrong', '192.0.2.2'))->toBeFalse();
    }
    $this->travel(61)->seconds();
    expect(phoneProofTestSend($service, '+233241234567', '192.0.2.2', null)['status'])->toBe('rate_limited');
    $other = '+233241234568';
    phoneProofTestSend($service, $other, '192.0.2.3', null);
    preg_match('/\b([0-9]{6})\b/', $this->sms[0]->text, $m);
    $this->travel(6)->minutes();
    expect($service->confirm($session, $other, $m[1], '192.0.2.3'))->toBeFalse();
});

it('limits resends and daily number sends without charging tenant credits', function (): void {
    $service = app(PhoneVerification::class);
    expect(phoneProofTestSend($service, '+233241234567', '192.0.2.4', null)['status'])->toBe('allowed')
        ->and(phoneProofTestSend($service, '+233241234567', '192.0.2.4', null)['status'])->toBe('rate_limited');
    for ($i = 0; $i < 4; $i++) {
        $this->travel(61)->seconds();
        expect(phoneProofTestSend($service, '+233241234567', '192.0.2.4', null)['status'])->toBe('allowed');
    }
    $this->travel(61)->seconds();
    expect(phoneProofTestSend($service, '+233241234567', '192.0.2.4', null)['status'])->toBe('rate_limited')
        ->and(PlatformPhoneSendReservation::query()->where('status', 'submitted')->count())->toBe(5);
    expect(App\Models\CommunitySmsReservation::query()->count())->toBe(0);
});

it('fails closed at the global cap and keeps uncertain submissions counted', function (): void {
    config(['attendee_portal.phone.daily_cap' => 1]);
    $budget = app(PhoneSignInBudget::class);
    $a = (string) Str::uuid();
    $b = (string) Str::uuid();
    expect($budget->reserve($a))->toBeTrue()->and($budget->reserve($a))->toBeFalse();
    $budget->finish($a, 'unknown');
    expect($budget->reserve($b))->toBeFalse();
    $budget->finish($a, 'failed');
    expect($budget->reserve($b))->toBeFalse();
});

it('releases only confirmed pre-submission failures and disables sending at zero cap', function (): void {
    config(['attendee_portal.phone.daily_cap' => 1]);
    $budget = app(PhoneSignInBudget::class);
    $a = (string) Str::uuid();
    expect($budget->reserve($a))->toBeTrue();
    $budget->finish($a, 'failed');
    expect($budget->reserve((string) Str::uuid()))->toBeTrue();
    config(['attendee_portal.phone.daily_cap' => 0]);
    expect(app(PhoneVerification::class)->requestSend('+233241234567', '192.0.2.5', null)['status'])->toBe('unavailable');
});

it('does not dispatch an expired or previously submitted job twice', function (): void {
    Queue::fake();
    $service = app(PhoneVerification::class);
    $service->requestSend('+233241234567', '192.0.2.6', null);
    $code = PlatformPhoneAccessCode::query()->firstOrFail();
    $job = new App\Jobs\Community\SendPhoneAccessCode($code->id);
    $job->handle($service);
    $code->refresh();
    expect($code->submitted_at)->not->toBeNull();
    $this->sms = [];
    $job->handle($service);
    expect($this->sms)->toBe([]);
});

it('requires a rolled out organisation context and keeps phone proof separate on HTTP', function (): void {
    $tenant = App\Models\Tenant::factory()->create();
    $this->withServerVariables(['HTTP_HOST' => mb_ltrim((string) config('session.domain'), '.')]);
    $this->postJson('/my/verify/phone/send', ['organiser' => $tenant->slug, 'phone' => '+233241234567'])->assertNotFound();
    $tenant->features()->create(['feature_key' => 'community_following', 'enabled' => true]);
    $this->postJson('/my/verify/phone/send', ['organiser' => $tenant->slug, 'phone' => '+233241234567'])->assertOk()->assertJsonPath('status', 'allowed');
    app(PhoneVerification::class)->dispatchCode(PlatformPhoneAccessCode::query()->firstOrFail()->id);
    preg_match('/\b([0-9]{6})\b/', $this->sms[0]->text, $m);
    $this->postJson('/my/verify/phone/confirm', ['organiser' => $tenant->slug, 'phone' => '+233241234567', 'code' => $m[1]])->assertOk();
    $this->getJson('/my/events')->assertUnauthorized();
    $this->postJson('/my/verify/phone/forget')->assertOk();
    expect(app(PhoneVerification::class)->verifiedPhone(app('session.store')))->toBeNull();
});

it('handles unavailable and invalid numbers without creating challenges', function (): void {
    config(['attendee_portal.phone.enabled' => false]);
    expect(app(PhoneVerification::class)->requestSend('+233241234567', '192.0.2.7', null)['status'])->toBe('unavailable');
    config(['attendee_portal.phone.enabled' => true]);
    expect(app(PhoneVerification::class)->requestSend('bad', '192.0.2.7', null)['status'])->toBe('invalid');
    expect(PlatformPhoneAccessCode::query()->count())->toBe(0);
});

/** Exercise the actual queued worker after the request transaction. */
function phoneProofTestSend(PhoneVerification $service, string $phone, string $ip, ?string $challenge): array
{
    $result = $service->requestSend($phone, $ip, $challenge);
    if ($result['status'] === 'allowed') {
        $service->dispatchCode($result['request_id']);
    }

    return $result;
}

it('does not impose registration throttles on a shared venue IP', function (): void {
    config(['attendee_portal.rate_limits.ip_burst' => 100, 'attendee_portal.rate_limits.ip_daily' => 2000]);
    $tenant = App\Models\Tenant::factory()->create();
    $tenant->features()->create(['feature_key' => 'community_following', 'enabled' => true]);
    $this->withServerVariables(['HTTP_HOST' => mb_ltrim((string) config('session.domain'), '.')]);
    for ($i = 0; $i < 15; $i++) {
        $this->postJson('/my/verify/phone/send', ['organiser' => $tenant->slug, 'phone' => '+2332412345'.mb_str_pad((string) $i, 2, '0', STR_PAD_LEFT)])
            ->assertOk();
    }
});

it('retains unresolved budget evidence while pruning old final outcomes', function (): void {
    foreach (['reserved', 'unknown', 'submitted', 'failed'] as $status) {
        PlatformPhoneSendReservation::query()->create(['day' => now()->subDays(40)->toDateString(), 'status' => $status, 'created_at' => now()->subDays(40)]);
    }
    expect((new PlatformPhoneSendReservation)->prunable()->pluck('status')->sort()->values()->all())->toBe(['failed', 'submitted']);
});

it('rejects a code exactly at its expiry boundary', function (): void {
    $this->freezeTime();
    PlatformPhoneAccessCode::factory()->create(['expires_at' => now(), 'submitted_at' => now()]);
    expect(app(PhoneVerification::class)->confirm(app('session.store'), '+233241234567', '123456', '192.0.2.8'))->toBeFalse();
});

it('does not record sign-in SMS plaintext in diagnostic outgoing-request entries', function (): void {
    $filters = Laravel\Telescope\Telescope::$filterUsing;
    try {
        Laravel\Telescope\Telescope::$filterUsing = [];
        Laravel\Telescope\Telescope::flushEntries();
        Laravel\Telescope\Telescope::startRecording(false);
        $gateway = Mockery::mock(SmsGateway::class);
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('send')->once()->andReturnUsing(function (array $messages): array {
            Laravel\Telescope\Telescope::recordClientRequest(Laravel\Telescope\IncomingEntry::make(['payload' => $messages[0]->text]));

            return [$messages[0]->reference => SmsResult::accepted('fake-provider')];
        });
        app()->instance(SmsGateway::class, $gateway);
        phoneProofTestSend(app(PhoneVerification::class), '+233241234567', '192.0.2.88', null);
        expect(Laravel\Telescope\Telescope::$entriesQueue)->toBe([]);
        Laravel\Telescope\Telescope::recordClientRequest(Laravel\Telescope\IncomingEntry::make(['payload' => 'ordinary nonsensitive request']));
        expect(Laravel\Telescope\Telescope::$entriesQueue)->toHaveCount(1);
    } finally {
        Laravel\Telescope\Telescope::stopRecording();
        Laravel\Telescope\Telescope::flushEntries();
        Laravel\Telescope\Telescope::$filterUsing = $filters;
    }
})->skip(fn (): bool => ! class_exists(Laravel\Telescope\Telescope::class), 'Requires the optional development recorder; phone delivery is tested without it separately.');
