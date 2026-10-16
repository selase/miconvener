<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CommunityFollower;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class CommunityFollowerController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('manage members');
        $tenant = app(TenantContext::class)->getTenant();
        $rows = CommunityFollower::query()->where('tenant_id', $tenant->id)->whereNull('unfollowed_at')->latest()->paginate(30);

        return response()->json(['followers' => $rows->through(fn ($row) => ['id' => $row->id, 'channel' => $row->channel, 'address' => $row->address, 'consented_at' => $row->consented_at->toIso8601String(), 'source' => $row->consent_source])]);
    }
}
