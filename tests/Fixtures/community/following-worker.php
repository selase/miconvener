<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Community\Following;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array']);
$session = app('session.store');
$session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => 'follow@example.test', 'expires_at' => now()->addHour()->timestamp]);
$tenant = Tenant::query()->findOrFail($argv[1]);
echo "READY\n";
flush();
if ($argv[2] === 'unfollow') {
    app(Following::class)->unfollow($tenant, $session, 'email');
} else {
    app(Following::class)->follow($tenant, $session, 'email', false, null);
}
echo 'DONE';
