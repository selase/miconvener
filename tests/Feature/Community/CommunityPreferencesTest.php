<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Community\MessagingPreferences;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'preference-qa']);
    $this->service = app(MessagingPreferences::class);
});

test('preferences are exact tenant and channel scoped and do not affect service notices', function () {
    $other = Tenant::factory()->create();
    $this->service->disableMarketing($this->tenant, 'email', ' AMA@EXAMPLE.TEST ');
    expect($this->service->allowsMarketing($this->tenant, 'email', 'ama@example.test'))->toBeFalse()
        ->and($this->service->allowsMarketing($other, 'email', 'ama@example.test'))->toBeTrue()
        ->and($this->service->allowsMarketing($this->tenant, 'sms', '+233241234567'))->toBeTrue();
});

test('unsubscribe GET is read-only and POST is idempotent without sign-in', function () {
    $token = $this->service->token($this->tenant, 'email', 'ama@example.test');
    $url = 'http://'.eventSubdomainHost('preference-qa').'/community/preferences/'.$token;
    $this->get($url)->assertOk()->assertSee('Stop announcements');
    expect($this->service->allowsMarketing($this->tenant, 'email', 'ama@example.test'))->toBeTrue();
    $this->post($url)->assertOk();
    $this->post($url)->assertOk();
    expect($this->service->allowsMarketing($this->tenant, 'email', 'ama@example.test'))->toBeFalse();
});

test('tokens cannot unsubscribe another tenant or accept tampered values', function () {
    Tenant::factory()->create(['slug' => 'other-preference']);
    $token = $this->service->token($this->tenant, 'sms', '+233241234567');
    $this->get('http://'.eventSubdomainHost('other-preference').'/community/preferences/'.$token)->assertNotFound();
    $this->post('http://'.eventSubdomainHost('preference-qa').'/community/preferences/not-a-token')->assertNotFound();
    expect($this->service->allowsMarketing($this->tenant, 'sms', '+233241234567'))->toBeTrue();
});

test('old unsubscribe tokens remain valid and raw addresses and tokens are not stored', function () {
    $first = $this->service->token($this->tenant, 'email', 'ama@example.test');
    $second = $this->service->token($this->tenant, 'email', 'ama@example.test');
    expect($first)->not->toBe($second);
    $row = $this->service->forToken($this->tenant, $first);
    expect($row->getRawOriginal('address'))->not->toContain('ama@example.test');
    expect($row->tokens()->pluck('token_hash')->all())->not->toContain($first, $second);
});
