<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\MemberController;
use App\Http\Requests\Tenant\Dues\StoreMemberRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Community\MemberImport;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'file', 'cache.stores.file.path' => sys_get_temp_dir().'/miconvener-import-concurrency-cache/'.$argv[1]]);
$tenant = Tenant::query()->findOrFail($argv[1]);
$user = User::query()->findOrFail((int) $argv[2]);
$app['request']->setLaravelSession($app['session']->driver());
auth()->setUser($user);
app(TenantContext::class)->setTenant($tenant);
setPermissionsTeamId($tenant->id);
echo "READY\n";
flush();
try {
    if ($argv[3] === 'confirm') {
        $result = app(MemberImport::class)->confirm($tenant, $argv[4], 'skip', (int) $user->id);
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } else {
        $request = StoreMemberRequest::create('/', 'POST', json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR));
        $request->setContainer($app)->setRedirector($app->make('redirect'));
        $request->setUserResolver(fn () => $user);
        $request->validateResolved();
        echo app(MemberController::class)->store($request)->getContent();
    }
} catch (Throwable $exception) {
    echo json_encode(['exception' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
