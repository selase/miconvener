<?php

declare(strict_types=1);

use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Marketplace\StoreCollections;
use App\Services\Marketplace\StoreRefunds;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'mail.default' => 'array', 'services.settlement.paystack.secret_key' => 'sk_test_store_only']);
Queue::fake();
Http::preventStrayRequests();
Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 'local-refund', 'status' => 'pending']])]);
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$order = StoreOrder::query()->findOrFail($input['order']);
$user = User::query()->findOrFail($input['actor']);
setPermissionsTeamId($order->tenant_id);
app(TenantContext::class)->setTenant($order->tenant);
$order->getConnection()->statement("SET application_name = 'store-collection-worker'");
echo "READY\n";
flush();
try {
    if ($input['action'] === 'collect') {
        app(StoreCollections::class)->confirm($order, $user, $input['credential'], $input['key']);
        echo 'COLLECTED';
    } elseif ($input['action'] === 'cancel') {
        app(StoreRefunds::class)->cancelFree($order, $user, $input['key'], 'Local acceptance cancellation');
        echo 'CANCELLED';
    } else {
        app(StoreRefunds::class)->request($order, $user, $input['key'], 'Local acceptance refund');
        echo 'REFUND';
    }
} catch (ValidationException) {
    echo 'BLOCKED';
}
