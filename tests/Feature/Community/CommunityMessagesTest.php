<?php

declare(strict_types=1);

use App\Models\CommunityMessage;
use App\Models\User;

require_once dirname(__DIR__, 2).'/Fixtures/community/message-test-setup.php';
beforeEach(fn () => setupCommunityMessagesTest($this));

test('preview counts normalized addresses and queue is idempotent', function () {
    $this->postJson(messageTestUrl('/preview'), $this->input)->assertOk()->assertJsonPath('counts.email', 1);
    $this->postJson(messageTestUrl(), $this->input)->assertCreated();
    $this->postJson(messageTestUrl(), $this->input)->assertOk();
    expect(CommunityMessage::query()->count())->toBe(1);
    $this->getJson(messageTestUrl())->assertOk()->assertJsonCount(1, 'messages.data');
    $message = CommunityMessage::query()->first();
    $this->getJson(messageTestUrl('/'.$message->id))->assertOk()->assertJsonPath('message.subject', 'Community news');
});

test('reusing an idempotency key for changed content is refused', function () {
    $this->postJson(messageTestUrl(), $this->input)->assertCreated();
    $this->postJson(messageTestUrl(), array_replace($this->input, ['body' => 'Different']))->assertStatus(409);
});

test('drafts do not dispatch and zero-recipient sends are refused', function () {
    $this->postJson(messageTestUrl(), $this->input + ['mode' => 'draft'])->assertCreated();
    expect(CommunityMessage::query()->first()->status)->toBe('draft');
    Illuminate\Support\Facades\Queue::assertNothingPushed();
    $this->member->update(['status' => 'exited']);
    $this->postJson(messageTestUrl(), array_replace($this->input, ['idempotency_key' => (string) Illuminate\Support\Str::uuid()]))->assertUnprocessable();
});

test('permissions rollout and finance-sensitive audiences are checked on the server', function () {
    $reader = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($reader->id);
    $this->actingAs($reader)->postJson(messageTestUrl('/preview'), $this->input)->assertForbidden();
    $this->actingAs($reader)->postJson(messageTestUrl(), $this->input)->assertForbidden();
    setPermissionsTeamId($this->tenant->id);
    $reader->givePermissionTo('send community messages');
    $this->actingAs($reader)->postJson(messageTestUrl('/preview'), array_replace($this->input, ['audience' => ['kind' => 'arrears']]))->assertForbidden();
    $this->tenant->features()->where('feature_key', 'community_messages')->update(['enabled' => false]);
    $this->actingAs($this->owner)->getJson(messageTestUrl())->assertNotFound();
});

test('invalid channels selectors SMS length and missing authority are rejected', function () {
    foreach ([['channels' => ['fax']], ['audience' => ['kind' => 'event', 'event_id' => $this->tenant->id]], ['channels' => ['sms'], 'body' => str_repeat('x', 459)], ['confirm_authority' => false]] as $invalid) {
        $this->postJson(messageTestUrl(), array_replace($this->input, $invalid))->assertUnprocessable();
    }
    expect(CommunityMessage::query()->count())->toBe(0);
});

test('foreign messages cannot be read or cancelled', function () {
    $message = CommunityMessage::factory()->create();
    $this->getJson(messageTestUrl('/'.$message->id))->assertNotFound();
    $this->postJson(messageTestUrl('/'.$message->id.'/cancel'))->assertNotFound();
});

test('milestone rollout command changes only the specified organisation', function () {
    $other = App\Models\Tenant::factory()->create();
    Illuminate\Support\Facades\Artisan::call('community:messages-enable', ['tenant' => $this->tenant->slug, '--off' => true]);
    expect($this->tenant->featureEnabled('community_messages'))->toBeFalse();
    Illuminate\Support\Facades\Artisan::call('community:messages-enable', ['tenant' => $this->tenant->slug]);
    expect($this->tenant->featureEnabled('community_messages'))->toBeTrue()->and($other->featureEnabled('community_messages'))->toBeFalse();
});

test('send preflight reports SMS budget credit and availability failures', function (string $failure) {
    if ($failure === 'monthly') {
        App\Models\CommunitySettings::for($this->tenant)->fill(['sms_monthly_limit' => 0])->save();
    } elseif ($failure === 'credit') {
        $this->tenant->features()->where('feature_key', 'sms_credits')->update(['meta' => ['type' => 'limit', 'value' => 0]]);
    } elseif ($failure === 'switch') {
        App\Models\TenantNotificationSetting::forTenant($this->tenant->id)->update(['sms_enabled' => false]);
    } else {
        app()->instance(App\Contracts\SmsGateway::class, new App\Services\Sms\NullSmsGateway);
    }
    $this->postJson(messageTestUrl(), array_replace($this->input, ['channels' => ['sms']]))->assertUnprocessable();
    expect(CommunityMessage::query()->count())->toBe(0);
})->with(['monthly', 'credit', 'switch', 'provider']);
