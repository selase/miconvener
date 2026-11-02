<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\IssueStoreCollectionPassRequest;
use App\Models\StoreOrder;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Marketplace\StoreCollectionPasses;
use Illuminate\Http\JsonResponse;

final class StoreCollectionPassController extends Controller
{
    public function store(IssueStoreCollectionPassRequest $request, string $order, StoreCollectionPasses $passes): JsonResponse
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($request->session());
        abort_if($email === null, 404);
        $owned = StoreOrder::query()->where('offering_type', 'purchase')->where('buyer_email', PlatformAttendeeVerification::normalise($email))->findOrFail($order);

        return response()->json($passes->issue($owned, $request->session(), $request->validated('operation_key')))->header('Cache-Control', 'private, no-store');
    }
}
