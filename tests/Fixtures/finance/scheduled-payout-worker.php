<?php

declare(strict_types=1);

use App\Models\EventPayout;
use App\Models\OrganisationPayoutPolicy;
use App\Models\ScheduledPayoutProposal;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Finance\CommunityPayouts;
use App\Services\Finance\PayoutPolicies;
use App\Services\Finance\ProviderLiquidity;
use App\Services\Finance\ScheduledPayoutApproval;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (! app()->environment('testing') || ! (str_contains((string) config('database.connections.landlord.database'), 'scheduled_payouts_test') || (getenv('CI') === 'true' && str_contains((string) config('database.connections.landlord.database'), 'testing')))) {
    throw new RuntimeException('Isolated scheduled payout test database required.');
}
Carbon\Carbon::setTestNow($input['now']);
Carbon\CarbonImmutable::setTestNow($input['now']);
config(['services.settlement.paystack.secret_key' => $input['commerce'] ?? false ? 'sk_test_store_only' : 'sk_test_schedule',
    'services.payout_schedules.liquidity_controls' => true, 'services.payout_schedules.cash_buffer' => 100,
    'services.payout_schedules.daily_total_limit' => 100000, 'mail.default' => 'array']);
Queue::fake();
Http::preventStrayRequests();
Http::fake(['*api.paystack.co/balance' => Http::response(['status' => true, 'data' => [['currency' => 'GHS', 'balance' => 100000]]]),
    '*api.paystack.co/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_race']]),
    '*api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['status' => 'otp', 'transfer_code' => 'TRF_race']]),
    '*api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 'refund-race', 'status' => 'pending']])]);
$actor = isset($input['actor']) ? User::query()->findOrFail($input['actor']) : null;
$tenant = Tenant::query()->findOrFail($input['tenant']);
app(App\Services\Tenancy\TenantContext::class)->setTenant($tenant);
setPermissionsTeamId($tenant->id);
while (microtime(true) < $input['start_at']) {
    usleep(10000);
}
try {
    $proposal = isset($input['proposal']) ? ScheduledPayoutProposal::query()->findOrFail($input['proposal']) : null;
    $result = match ($input['action']) {
        'reserve' => app(ScheduledPayoutApproval::class)->reserve($proposal, $actor, $input['key'])->id,
        'manual' => app(CommunityPayouts::class)->reserve($tenant, $actor, App\Enum\LedgerCategoryEnum::Dues,
            ['amount' => 10000, 'currency' => 'GHS', 'payout_account_id' => $input['account']])->id,
        'cancel' => app(ScheduledPayoutApproval::class)->cancel($proposal, $actor),
        'submit' => app(ScheduledPayoutApproval::class)->submit($proposal, $actor),
        'pause' => app(PayoutPolicies::class)->pause(OrganisationPayoutPolicy::query()->findOrFail($input['policy']), $actor),
        'liquidity' => app(ProviderLiquidity::class)->claim(EventPayout::withoutGlobalScopes()->findOrFail($input['payout'])),
        'store_send' => app(App\Services\Marketplace\StorePayouts::class)->send(EventPayout::withoutGlobalScopes()->findOrFail($input['payout'])),
        'refund' => app(App\Services\Marketplace\StoreRefunds::class)->request(App\Models\StoreOrder::query()->findOrFail($input['order']), $actor, $input['key'], 'Local scheduled-payout refund race')->id,
    };
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Illuminate\Validation\ValidationException|Symfony\Component\HttpKernel\Exception\HttpException|Illuminate\Auth\Access\AuthorizationException $exception) {
    echo json_encode(['ok' => false, 'refused' => true], JSON_THROW_ON_ERROR);
}
