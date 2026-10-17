<?php

declare(strict_types=1);

use App\Models\GivingFund;
use App\Models\GivingPaymentAttempt;
use App\Services\Giving\GivingCheckout;
use App\Services\Giving\GivingPaymentConfirmer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\GivingScenario;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'services.settlement.paystack.secret_key' => 'sk_test_giving']);
DB::connection('landlord')->statement("SET application_name = 'giving-payment-worker'");
$fund = GivingFund::withoutGlobalScopes()->findOrFail($argv[1]);
echo "READY\n";
flush();
if ($argv[2] === 'confirm') {
    $attempt = GivingPaymentAttempt::withoutGlobalScopes()->findOrFail($argv[3]);
    app(GivingPaymentConfirmer::class)->confirm($attempt->reference, GivingScenario::verified($attempt));
    echo 'CONFIRMED';
} else {
    Http::preventStrayRequests();
    Http::fake([
        '*api.paystack.co/customer*' => Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_GIVING', 'email' => 'giver@example.test']]),
        '*api.paystack.co/transaction/initialize' => function () {
            echo "INITIALIZED\n";

            return Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/giving-test']]);
        },
    ]);
    try {
        app(GivingCheckout::class)->start($fund, ['amount_minor' => 10000, 'currency' => 'GHS', 'provider' => 'paystack'], app('session.store'), $argv[3]);
        echo 'STARTED';
    } catch (ValidationException $exception) {
        if (! isset($exception->errors()['submission_key']) && ! isset($exception->errors()['payment'])) {
            throw $exception;
        }
        echo 'ALREADY_STARTED';
    }
}
