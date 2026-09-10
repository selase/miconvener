<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;

final class RefundController extends Controller
{
    public function store(Request $request, Transaction $transaction, PaymentGateway $gateway)
    {
        abort(403, 'Subscription refunds are not currently supported.');
    }
}
