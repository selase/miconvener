<?php

declare(strict_types=1);

use App\Models\GivingFund;
use App\Models\User;
use App\Services\Giving\GivingCash;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array']);
$fund = GivingFund::withoutGlobalScopes()->findOrFail($argv[1]);
setPermissionsTeamId($fund->tenant_id);
app(TenantContext::class)->setTenant($fund->tenant);
$user = User::query()->findOrFail($argv[2]);
DB::connection('landlord')->statement("SET application_name = 'giving-cash-worker'");
echo "READY\n";
flush();
try {
    app(GivingCash::class)->record($fund, $user, ['source' => 'bulk_cash', 'amount' => 100000, 'currency' => 'GHS'], $argv[3]);
    echo 'DONE';
} catch (ValidationException $exception) {
    if (! isset($exception->errors()['fund'])) {
        throw $exception;
    }
    echo 'FUND_CLOSED';
}
