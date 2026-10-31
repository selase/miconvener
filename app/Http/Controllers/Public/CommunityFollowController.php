<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\FollowCommunityRequest;
use App\Models\Event;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\Community\Following;
use Illuminate\Http\JsonResponse;

final class CommunityFollowController extends Controller
{
    public function store(FollowCommunityRequest $request, string $tenantSlug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
        $source = $request->filled('source_event_id') ? Event::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->published()->findOrFail($request->validated('source_event_id')) : null;
        app(Following::class)->follow($tenant, $request->session(), $request->string('channel')->toString(), $request->boolean('resume_marketing'), $source);

        return response()->json(['following' => true, 'message' => 'You are following this organisation at your verified address.'], 201);
    }

    public function destroy(FollowCommunityRequest $request, string $tenantSlug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
        app(Following::class)->unfollow($tenant, $request->session(), $request->string('channel')->toString());

        return response()->json(['following' => false, 'message' => 'Announcements to this address have stopped. Receipts and service notices are unaffected.']);
    }
}
