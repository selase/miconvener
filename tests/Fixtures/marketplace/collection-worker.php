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
Http::fake(['*api.paystack.co/customer*' => Http::response(['data' => ['customer_code' => 'CUS_QUOTE_RACE']]), '*api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/quote-race']]), '*api.paystack.co/refund' => Http::response(['data' => ['id' => 'local-refund', 'status' => 'pending']])]);
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$order = StoreOrder::query()->findOrFail($input['order']);
$user = User::query()->findOrFail($input['actor']);
setPermissionsTeamId($order->tenant_id);
app(TenantContext::class)->setTenant($order->tenant);
$order->getConnection()->statement("SET application_name = 'store-collection-worker'");
echo "READY\n";
flush();
try {
    if ($input['action'] === 'quote_pay') {
        $session = new Illuminate\Session\Store('quote-race', new Illuminate\Session\ArraySessionHandler(120));
        $session->put(App\Services\Events\PlatformAttendeeVerification::SESSION_KEY, ['email' => $order->buyer_email, 'expires_at' => now()->addHour()->timestamp]);
        app(App\Services\Marketplace\StorePayments::class)->start($order, $session, $input['key'], $order->terms_hash);
        echo 'PAYMENT';
    } elseif ($input['action'] === 'quote_withdraw') {
        app(App\Services\Marketplace\StoreSellerQuotes::class)->withdraw($order->sellerQuote, $user, 'Local quote-race withdrawal');
        echo 'WITHDRAWN';
    } elseif ($input['action'] === 'collect') {
        $record = app(StoreCollections::class)->confirm($order, $user, $input['credential'], $input['key']);
        echo $record->method === 'delivery' ? 'DELIVERED' : 'COLLECTED';
    } elseif ($input['action'] === 'dispatch') {
        app(App\Services\Marketplace\StoreDeliveries::class)->dispatch($order, $user, $input['key']);
        echo 'DISPATCHED';
    } elseif ($input['action'] === 'return') {
        app(App\Services\Marketplace\StoreDeliveries::class)->returned($order, $user, $input['key'], 'All goods physically back');
        echo 'RETURNED';
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
