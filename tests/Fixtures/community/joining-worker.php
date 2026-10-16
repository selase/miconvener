<?php

declare(strict_types=1);
use App\Models\CommunityJoinRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Community\MembershipJoining;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array']);
$tenant = Tenant::findOrFail($argv[1]);
$session = app('session.store');
$session->put(PlatformAttendeeVerification::SESSION_KEY, ['email' => $argv[3], 'expires_at' => now()->addHour()->timestamp]);
echo "READY\n";
flush();
try {
    if ($argv[2] === 'approve') {
        $request = CommunityJoinRequest::withoutGlobalScopes()->findOrFail($argv[4]);
        app(MembershipJoining::class)->approve($request, User::findOrFail($argv[5]), $request->channel === 'sms', 'Independent QA check of the new phone applicant.');
    } else {
        app(MembershipJoining::class)->submit($tenant, $session, ['name' => 'Applicant', 'channel' => 'email'], $argv[4]);
    }
    echo 'DONE';
} catch (DomainException) {
    echo 'CAP_REACHED';
}
