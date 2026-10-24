<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\StoreFulfilmentChoiceRequest;
use App\Models\StoreOrder;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Marketplace\StoreFulfilmentOptions;
use Illuminate\Http\JsonResponse;

final class StoreFulfilmentChoiceController extends Controller
{
    public function store(StoreFulfilmentChoiceRequest $request, string $order, StoreFulfilmentOptions $options): JsonResponse
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($request->session());
        abort_if($email === null, 404);
        $owned = StoreOrder::query()->where('buyer_email', PlatformAttendeeVerification::normalise($email))->findOrFail($order);
        $arrangement = $options->choose($owned, $request->session(), $request->validated('choice'), $request->validated('operation_key'));

        return response()->json(['order_id' => $arrangement->order_id, 'method' => $arrangement->method])->header('Cache-Control', 'private, no-store');
    }
}
