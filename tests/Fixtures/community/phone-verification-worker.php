<?php

declare(strict_types=1);

use App\Services\Community\PhoneSignInBudget;
use App\Services\Community\PhoneVerification;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'mail.default' => 'array', 'attendee_portal.phone.enabled' => true, 'attendee_portal.phone.daily_cap' => 1]);
echo "READY\n";
flush();
$accepted = $argv[1] === 'budget'
    ? app(PhoneSignInBudget::class)->reserve($argv[2])
    : app(PhoneVerification::class)->confirm(app('session.store'), $argv[2], '123456', '192.0.2.90');
echo $accepted ? 'SUCCESS' : 'DENIED';
