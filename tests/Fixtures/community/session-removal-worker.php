<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['session.driver' => 'file', 'session.files' => $argv[2]]);
$request = Illuminate\Http\Request::create('/qa/session', 'POST', [], [config('session.cookie') => $argv[1]]);
$request->setRouteResolver(fn () => new Illuminate\Routing\Route(['POST'], 'qa/session', fn () => null));
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
app(Illuminate\Session\Middleware\StartSession::class)->handle($request, function ($request) {
    fwrite(STDOUT, "ENTERED\n");
    fflush(STDOUT);

    return app(App\Http\Controllers\Public\PlatformAttendeeAccessController::class)->forget($request);
});
echo "DONE\n";
