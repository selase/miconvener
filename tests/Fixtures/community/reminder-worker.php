<?php

declare(strict_types=1);

use App\Contracts\SmsGateway;
use App\Models\Tenant;
use App\Services\Dues\DuesReminders;
use App\Services\Sms\OmnichannelSmsGateway;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'file', 'cache.stores.file.path' => sys_get_temp_dir().'/miconvener-reminder-race/'.$argv[1].'/'.$argv[3], 'mail.default' => 'array']);
$tenant = Tenant::query()->findOrFail($argv[1]);
$app['request']->setLaravelSession($app['session']->driver());
app(TenantContext::class)->setTenant($tenant);
setPermissionsTeamId($tenant->id);
Mail::fake();
Http::preventStrayRequests();
Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'race']], 202)]);
app()->instance(SmsGateway::class, new OmnichannelSmsGateway('https://messaging.test', 'fake', 'Test'));
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'Africa/Accra'));
echo "READY\n";
flush();
if ($argv[3] === 'reset') {
    app(App\Services\Tenancy\FeatureMeteringService::class)->resetUsage($tenant, 'sms_credits');
    echo 'RESET';
    exit;
}
$result = $argv[3] === 'manual'
    ? app(DuesReminders::class)->remindArrears($tenant, (int) $argv[2])
    : app(DuesReminders::class)->sendDue($tenant, CarbonImmutable::now()->startOfDay());
echo json_encode($result, JSON_THROW_ON_ERROR);
