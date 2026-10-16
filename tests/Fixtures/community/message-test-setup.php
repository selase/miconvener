<?php

declare(strict_types=1);

function setupCommunityMessagesTest(object $test): void
{
    $test->travelTo(Carbon\CarbonImmutable::parse('2026-10-15 12:00:00', 'Africa/Accra'));

    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    [$test->tenant, $test->owner] = eventHost('message-qa');
    Illuminate\Support\Facades\Artisan::call('community:enable', ['tenant' => $test->tenant->slug]);
    foreach (['community_messages', 'sms_credits', 'email_credits'] as $key) {
        $test->tenant->features()->create(['feature_key' => $key, 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 100]]);
    }
    $test->member = App\Models\Member::factory()->create(['tenant_id' => $test->tenant->id, 'email' => 'ama@example.test', 'phone' => '+233241234567']);
    App\Models\TenantNotificationSetting::forTenant($test->tenant->id)->update(['sms_enabled' => true]);
    $test->input = ['subject' => 'Community news', 'body' => 'Our next gathering is on Friday.', 'channels' => ['email'], 'audience' => ['kind' => 'all_members'], 'idempotency_key' => (string) Illuminate\Support\Str::uuid(), 'confirm_authority' => true];
    Illuminate\Support\Facades\Queue::fake();
    Illuminate\Support\Facades\Mail::fake();
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(['messaging.test/*' => Illuminate\Support\Facades\Http::response(['data' => ['campaign_id' => 'message']], 202)]);
    app()->instance(App\Contracts\SmsGateway::class, new App\Services\Sms\OmnichannelSmsGateway('https://messaging.test', 'fake-token', 'MiConvener'));
    $test->actingAs($test->owner)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('message-qa')]);
}

function messageTestUrl(string $path = ''): string
{
    return 'http://'.eventSubdomainHost('message-qa').'/community/messages'.$path;
}

function deliverCommunityTestMessage(App\Models\CommunityMessage $message): void
{
    $service = app(App\Services\Community\CommunityMessages::class);
    do {
        $service->dispatchDue($message->id);
        $message->refresh();
    } while (! $message->selection_complete && $message->status === 'sending');
    foreach (['email', 'sms'] as $channel) {
        foreach ($message->deliveries()->where('channel', $channel)->pluck('id')->chunk($channel === 'email' ? 1 : 50) as $ids) {
            $service->deliver($message->id, $ids->all(), $channel);
        }
    }
}
