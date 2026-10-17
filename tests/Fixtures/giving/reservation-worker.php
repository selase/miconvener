<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Finance\CommunityPayouts;
use App\Services\Giving\GivingRefunds;
use App\Services\Giving\GivingSources;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'services.settlement.paystack.secret_key' => 'sk_test_giving']);
$tenant = Tenant::query()->findOrFail($argv[1]);
setPermissionsTeamId($tenant->id);
app(TenantContext::class)->setTenant($tenant);
$user = User::query()->findOrFail($argv[2]);
DB::connection('landlord')->statement("SET application_name = 'giving-reservation-worker'");
Http::preventStrayRequests();
Http::fake(['*api.paystack.co/refund' => function () {
    echo "REFUND_CREATED\n";

    return Http::response(['status' => true, 'data' => ['id' => 501]]);
}]);
echo "READY\n";
flush();
try {
    if ($argv[3] === 'payout') {
        app(CommunityPayouts::class)->reserve($tenant, $user, LedgerCategoryEnum::Giving, ['payout_account_id' => $argv[4], 'amount' => 7000, 'currency' => 'GHS']);
    } else {
        $source = app(GivingSources::class)->find($tenant, 'gift', $argv[4]);
        app(GivingRefunds::class)->request($source, $user, 7000, 'Concurrent refund', $argv[5]);
    }
    echo 'RESERVED';
} catch (ValidationException) {
    echo 'BLOCKED';
}
