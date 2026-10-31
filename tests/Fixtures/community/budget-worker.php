<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Community\CommunitySmsBudget;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'mail.default' => 'array']);
$tenant = Tenant::query()->findOrFail($argv[1]);
echo "READY\n";
flush();
$row = app(CommunitySmsBudget::class)->reserve($tenant, $argv[2], $argv[3], CarbonImmutable::parse('2026-10-15 12:00:00', 'Africa/Accra'));
echo $row === null ? 'BLOCKED' : 'RESERVED';
