<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Community\CommunitySmsReconciler;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array']);
$tenant = Tenant::query()->findOrFail($argv[1]);
$operator = User::query()->findOrFail($argv[2]);
echo "READY\n";
flush();
app(CommunitySmsReconciler::class)->resolve($operator, $tenant, $argv[3], 'confirmed_not_submitted', 'Provider support confirms submission did not occur.', 'Reconciled against the provider support reference.');
echo 'DONE';
