<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventPayout;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function financeHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('finance stats reflect confirmed registrations and paid payouts only', function () {
    [$tenant, $user] = financeHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED, 'amount' => 10_000, 'platform_fee_amount' => 500]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED, 'amount' => 20_000, 'platform_fee_amount' => 1_000]);
    EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'amount' => 99_000, 'platform_fee_amount' => 9_900]);

    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    EventPayout::factory()->paid()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'payout_account_id' => $account->id, 'amount' => 12_000]);
    EventPayout::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'payout_account_id' => $account->id, 'amount' => 8_000]); // scheduled, not paid

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/finance", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonPath('stats.collected', 30_000);
    $response->assertJsonPath('stats.fees', 1_500);
    $response->assertJsonPath('stats.net', 28_500);
    $response->assertJsonPath('stats.paid_out', 12_000);
    expect($response->json('payouts'))->toHaveCount(2);
});

test('host can add a payout account and the account number is stored encrypted, never returned raw', function () {
    \Illuminate\Support\Facades\Http::fake([
        'api.paystack.co/bank/resolve*' => \Illuminate\Support\Facades\Http::response([
            'status' => true,
            'data' => ['account_name' => 'Purpledot Limited'],
        ]),
    ]);

    [$tenant, $user] = financeHost();

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/payout-accounts", [
        'type' => 'bank',
        'label' => 'Absa Bank Ghana — current',
        'account_name' => 'Purpledot Limited',
        'account_number' => '1234567894417',
        'bank_code' => '030',
    ], ['HTTP_HOST' => $host]);

    $response->assertCreated();
    expect($response->json('masked_account_number'))->toBe('•••• 4417');
    expect($response->json())->not->toHaveKey('account_number_encrypted');
    expect($response->json())->not->toHaveKey('account_number');

    $account = TenantPayoutAccount::where('tenant_id', $tenant->id)->firstOrFail();
    expect($account->getRawOriginal('account_number_encrypted'))->not->toContain('1234567894417');
    expect($account->account_number_encrypted)->toBe('1234567894417');
});

test('host can record a payout and mark it paid', function () {
    [$tenant, $user] = financeHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $store = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/finance/payouts", [
        'payout_account_id' => $account->id,
        'amount' => 15_000,
    ], ['HTTP_HOST' => $host]);
    $store->assertCreated();

    $payout = EventPayout::where('event_id', $event->id)->firstOrFail();
    expect($payout->status)->toBe(EventPayout::STATUS_SCHEDULED);

    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}", [
        'status' => 'paid',
    ], ['HTTP_HOST' => $host])->assertOk();

    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PAID);
    expect($payout->fresh()->paid_at)->not->toBeNull();
});

test('the settlement statement export only includes confirmed registrations', function () {
    [$tenant, $user] = financeHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventRegistration::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'status' => EventRegistration::STATUS_CONFIRMED, 'full_name' => 'Kwame Asante']);
    EventRegistration::factory()->pendingPayment()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'full_name' => 'Should Not Appear']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/finance/settlement-statement", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $content = $response->streamedContent();
    expect($content)->toContain('Kwame Asante');
    expect($content)->not->toContain('Should Not Appear');
});

test('a host without manage organization settings permission cannot add a payout account', function () {
    [$tenant] = financeHost();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $tenant->users()->attach($user->id); // no role assigned

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->postJson("http://{$host}/payout-accounts", [
        'type' => 'bank',
        'label' => 'Test',
        'account_name' => 'Test',
        'account_number' => '1234',
    ], ['HTTP_HOST' => $host])->assertForbidden();
});
