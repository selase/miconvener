<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Contracts\SmsGateway;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Services\Sms\NullSmsGateway;
use App\Services\Sms\OmnichannelSmsGateway;
use App\Services\Sms\SmsAllowance;
use App\Services\Sms\SmsMessage;
use App\Support\PhoneNumber;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * SMS goes out through a provider behind one interface -- today the
 * Omnichannel platform's v1 API, later a tenant's own Twilio or mNotify --
 * and is paid for from the plan's monthly credits first, then from SMS packs,
 * which are spent once and never refill.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    config()->set('services.omnichannel', [
        'url' => 'https://messaging.test',
        'token' => 'secret-token',
        'sender_id' => 'MiConvener',
    ]);
});

test('phone numbers are normalised to international digits', function (string $input, ?string $expected): void {
    expect(PhoneNumber::toInternationalDigits($input))->toBe($expected);
})->with([
    ['0241234567', '233241234567'],
    ['+233 24 123 4567', '233241234567'],
    ['233241234567', '233241234567'],
    ['+44 7700 900123', '447700900123'],
    ['not a number', null],
    ['', null],
]);

test('the omnichannel driver queues a campaign on the v1 API', function (): void {
    Http::fake(['messaging.test/*' => Http::response(['success' => true, 'code' => 202, 'data' => ['campaign_id' => 'camp-1']], 202)]);

    $results = app(OmnichannelSmsGateway::class)->send([new SmsMessage('0241234567', 'Your ticket is ready', 'log-1')]);

    expect($results['log-1']->accepted)->toBeTrue()
        ->and($results['log-1']->providerReference)->toBe('camp-1');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://messaging.test/api/v1/campaign/send'
            && $request->hasHeader('Authorization', 'Bearer secret-token')
            && $body[0]['sender_id'] === 'MiConvener'
            && $body[0]['message'] === 'Your ticket is ready'
            && $body[0]['is_scheduled'] === false
            && $body[0]['users'] === [['phone_number' => '233241234567', 'intended_delivery_channel' => 'sms']];
    });
});

test('messages with the same text share one campaign; different texts do not', function (): void {
    Http::fake(['messaging.test/*' => Http::sequence()
        ->push(['data' => ['campaign_id' => 'same']], 202)
        ->push(['data' => ['campaign_id' => 'other']], 202)]);

    $results = app(OmnichannelSmsGateway::class)->send([
        new SmsMessage('0241111111', 'Doors open at 8', 'a'),
        new SmsMessage('0242222222', 'Doors open at 8', 'b'),
        new SmsMessage('0243333333', 'Your seat is C-14', 'c'),
    ]);

    Http::assertSentCount(2);
    expect($results['a']->providerReference)->toBe('same')
        ->and($results['b']->providerReference)->toBe('same')
        ->and($results['c']->providerReference)->toBe('other');
});

test('an unusable number is refused without calling the provider', function (): void {
    Http::fake();

    $results = app(OmnichannelSmsGateway::class)->send([new SmsMessage('abc', 'Hello', 'x')]);

    expect($results['x']->accepted)->toBeFalse();
    Http::assertNothingSent();
});

test('a provider refusal is reported, not treated as sent', function (): void {
    Http::fake(['messaging.test/*' => Http::response(['success' => false, 'message' => 'Insufficient credit'], 417)]);

    $result = app(OmnichannelSmsGateway::class)->send([new SmsMessage('0241234567', 'Hi', 'r')])['r'];

    expect($result->accepted)->toBeFalse()
        ->and($result->error)->toContain('Insufficient credit');
});

test('without a token, SMS is switched off rather than broken', function (): void {
    config()->set('services.omnichannel.token', null);
    app()->forgetInstance(SmsGateway::class);

    $gateway = app(SmsGateway::class);
    $result = $gateway->send([new SmsMessage('0241234567', 'Hi', 'n')])['n'];

    expect($gateway)->toBeInstanceOf(NullSmsGateway::class)
        ->and($gateway->isConfigured())->toBeFalse()
        ->and($result->accepted)->toBeFalse()
        ->and(app(OmnichannelSmsGateway::class)->isConfigured())->toBeFalse();
    config()->set('services.omnichannel.token', 'secret-token');
    expect(app(OmnichannelSmsGateway::class)->isConfigured())->toBeTrue();
});

test('SMS is paid from plan credits first, then from packs, which never refill', function (): void {
    $this->seed(EventPackageSeeder::class);
    $tenant = Tenant::factory()->create([
        'isolation_mode' => 'shared',
        'package_id' => Package::where('slug', 'starter')->firstOrFail()->id, // 150 a month
    ]);
    $tenant->syncFeaturesFromPackage();
    TenantAddon::factory()->create([
        'tenant_id' => $tenant->id,
        'addon_type' => TenantAddon::TYPE_SMS_PACK,
        'quantity' => 10,
        'billing_interval' => TenantAddon::INTERVAL_ONE_OFF,
        'status' => TenantAddon::STATUS_ACTIVE,
    ]);
    $allowance = app(SmsAllowance::class);

    expect($allowance->remaining($tenant))->toBe(160);

    $allowance->consume($tenant, 155);
    expect($allowance->remaining($tenant))->toBe(5)
        ->and($allowance->packRemaining($tenant))->toBe(5);

    // A new month refills the plan, not the pack.
    app(\App\Services\Tenancy\FeatureMeteringService::class)->resetUsage($tenant, 'sms_credits');
    expect($allowance->remaining($tenant))->toBe(155);

    $allowance->consume($tenant, 155);
    expect($allowance->canSend($tenant))->toBeFalse();
});
