<?php

declare(strict_types=1);

use App\Contracts\SmsGateway;
use App\Models\CommunityMessage;
use App\Models\Tenant;
use App\Services\Community\CommunityMessages;
use App\Services\Sms\OmnichannelSmsGateway;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'mail.default' => 'array']);
$app['request']->setLaravelSession($app['session']->driver());
$message = CommunityMessage::withoutGlobalScopes()->findOrFail($argv[1]);
$tenant = Tenant::query()->findOrFail($message->tenant_id);
app(TenantContext::class)->setTenant($tenant);
setPermissionsTeamId($tenant->id);
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'Africa/Accra'));
Mail::fake();
Http::preventStrayRequests();
Http::fake(['messaging.test/*' => Http::response(['data' => ['campaign_id' => 'race']], 202)]);
app()->instance(SmsGateway::class, new OmnichannelSmsGateway('https://messaging.test', 'fake-token', 'Test'));
echo "READY\n";
flush();
app(CommunityMessages::class)->deliver($message->id, [$argv[2]], $argv[3]);
echo 'DONE';
