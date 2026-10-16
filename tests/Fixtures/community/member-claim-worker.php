<?php

declare(strict_types=1);

use App\Services\Community\MemberClaims;
use App\Services\Community\PhoneVerification;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array']);
$session = app('session.store');
$session->put(PhoneVerification::SESSION_KEY, ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
echo "READY\n";
flush();
try {
    app(MemberClaims::class)->complete($session, $argv[1], str_repeat('a', 64));
    echo 'SUCCESS';
} catch (HttpException $exception) {
    if ($exception->getStatusCode() !== 410) {
        throw $exception;
    }
    echo 'DENIED';
}
