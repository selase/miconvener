<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Store\StoreFulfilmentPolicyRequest;
use App\Services\Marketplace\StoreFulfilmentOptions;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class StoreFulfilmentPolicyController extends Controller
{
    public function store(StoreFulfilmentPolicyRequest $request, StoreFulfilmentOptions $options): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant() ?? abort(403);
        $shop = $tenant->shop ?? abort(422, 'Create your store before offering fulfilment.');
        $policy = $options->offer($shop, $request->user(), $request->validated('policy'), $request->validated('operation_key'));

        return response()->json(['id' => $policy->id, 'version' => $policy->version, 'policy' => $policy->snapshot])->header('Cache-Control', 'private, no-store');
    }
}
