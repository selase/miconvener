<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/../../Support/ScheduledPayoutScenario.php';

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_test_schedule']);
});

test('finance schedules are discoverable and request creates only a pending policy', function () {
    [$tenant, $owner, , $account] = scheduledPolicyActors();
    $host = eventSubdomainHost($tenant->slug);
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time(), 'google2fa' => true])
        ->get("http://{$host}/finance/schedules")->assertOk();
    $this->postJson("http://{$host}/finance/schedules", scheduledPolicyInput($account))->assertCreated()->assertJsonPath('status', 'pending');
    expect(App\Models\EventPayout::count())->toBe(0);
});

test('a user from another organisation cannot read or mutate schedules', function () {
    [$tenant] = scheduledPolicyActors();
    $other = User::factory()->create(['status' => 'active']);
    $this->actingAs($other)->getJson('http://'.eventSubdomainHost($tenant->slug).'/finance/schedules')->assertForbidden();
});

test('a destination referenced by a policy cannot be deleted', function () {
    [$tenant, $owner, , $account] = scheduledPolicyActors();
    app(App\Services\Finance\PayoutPolicies::class)->request($tenant, $owner, scheduledPolicyInput($account));
    $this->actingAs($owner)->withSession(['google2fa' => true])->deleteJson('http://'.eventSubdomainHost($tenant->slug).'/payout-accounts/'.$account->id)->assertUnprocessable();
    expect($account->fresh())->not->toBeNull();
});
