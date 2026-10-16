<?php

declare(strict_types=1);

use App\Http\Controllers\Public\CommunityPhoneAccessController;
use App\Http\Controllers\Public\PlatformAttendeeAccessController;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

it('destroys persisted proof aliases on sign out and phone removal while retaining staff login', function (bool $phoneOnly): void {
    $handler = new ArraySessionHandler(120);
    $session = new Store('qa', $handler, str_repeat('a', 40));
    $session->start();
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'proof@example.test', 'expires_at' => now()->addHour()->timestamp]);
    $session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    $session->put('community_member_grants', ['member' => ['version' => 1]]);
    $session->put('login_web_qa', 123);
    $oldId = $session->getId();
    $session->save();
    $session->start();
    $request = Request::create('/my', 'POST');
    $request->setLaravelSession($session);
    app($phoneOnly ? CommunityPhoneAccessController::class : PlatformAttendeeAccessController::class)->forget($request);
    $session->save();
    $replay = new Store('qa', $handler, $oldId);
    $replay->start();
    expect($replay->has(PhoneVerification::SESSION_KEY))->toBeFalse()
        ->and($replay->has('community_member_grants'))->toBeFalse()
        ->and($session->get('login_web_qa'))->toBe(123);
    if ($phoneOnly) {
        expect($session->has(PlatformAttendeeVerification::SESSION_KEY))->toBeTrue();
    } else {
        expect($replay->has(PlatformAttendeeVerification::SESSION_KEY))->toBeFalse();
    }
})->with([false, true]);

it('serializes every stateful web request using shared database session locks', function (): void {
    expect(app('session')->shouldBlock())->toBeTrue();
    $store = config('session.block_store');
    expect(config('cache.stores.'.$store.'.driver'))->toBe('database')
        ->and(config('cache.stores.'.$store.'.lock_connection'))->toBe('landlord')
        ->and(config('session.block_lock_seconds'))->toBeGreaterThanOrEqual(600);
});

it('removes every persisted alias across grant issue recovery and sign out', function (): void {
    $handler = new ArraySessionHandler(120);
    $session = new Store('qa', $handler, str_repeat('b', 40));
    $session->start();
    $session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    $ids = [$session->getId()];
    $session->save();
    $session->start();
    $member = App\Models\Member::factory()->create(['phone' => '+233241234567']);
    $access = app(App\Services\Community\MemberAccess::class);
    $credential = $access->issue($session, $member, '+233241234567', 'self', null, 'in_person');
    $ids[] = $session->getId();
    $session->save();
    $session->start();
    expect($access->establish($session, $credential['selector'], $credential['secret']))->toBeTrue();
    $ids[] = $session->getId();
    $session->save();
    $session->start();
    $request = Request::create('/my', 'POST');
    $request->setLaravelSession($session);
    app(PlatformAttendeeAccessController::class)->forget($request);
    $session->save();
    foreach ($ids as $id) {
        $replay = new Store('qa', $handler, $id);
        $replay->start();
        expect($replay->has(PhoneVerification::SESSION_KEY))->toBeFalse()->and($replay->has('community_member_grants'))->toBeFalse();
    }
});

it('destroys the previous persisted record when confirming an independent identifier', function (string $channel): void {
    config(['attendee_portal.phone.enabled' => true]);
    $handler = new ArraySessionHandler(120);
    $session = new Store('qa', $handler, str_repeat('c', 40));
    $session->start();
    $session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'old@example.test', 'expires_at' => now()->addHour()->timestamp]);
    $oldId = $session->getId();
    $session->save();
    $session->start();
    if ($channel === 'phone') {
        App\Models\PlatformPhoneAccessCode::factory()->create(['phone' => '+233241234567', 'phone_hash' => PhoneVerification::digest('+233241234567'), 'code_hash' => Illuminate\Support\Facades\Hash::make('123456')]);
        expect(app(PhoneVerification::class)->confirm($session, '+233241234567', '123456', '192.0.2.1'))->toBeTrue();
    } else {
        App\Models\PlatformAttendeeAccessCode::factory()->create(['email_normalized' => 'new@example.test', 'code_hash' => Illuminate\Support\Facades\Hash::make('123456')]);
        expect(app(PlatformAttendeeVerification::class)->confirm($session, 'new@example.test', '123456', '192.0.2.1'))->toBeTrue();
    }
    $session->save();
    $replay = new Store('qa', $handler, $oldId);
    $replay->start();
    expect($replay->has(PlatformAttendeeVerification::SESSION_KEY))->toBeFalse();
})->with(['phone', 'email']);
