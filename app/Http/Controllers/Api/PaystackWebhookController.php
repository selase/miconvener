<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class PaystackWebhookController extends Controller
{
    /**
     * Handle incoming webhooks from Paystack.
     */
    public function handle(Request $request)
    {
        // The signature is already verified by VerifyPaystackSignature middleware.
        $payload = $request->all();

        // Dispatch the job immediately so we can respond 200 OK fast.
        \App\Jobs\Billing\ProcessPaystackWebhookJob::dispatch($payload);

        return response()->json(['status' => 'success']);
    }
}
