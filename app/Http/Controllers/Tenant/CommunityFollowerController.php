<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CommunityFollower;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CommunityFollowerController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $this->authorize('manage members');
        $tenant = app(TenantContext::class)->getTenant();
        $rows = CommunityFollower::query()->where('tenant_id', $tenant->id)->whereNull('unfollowed_at')->latest()->paginate(30);

        $followers = $rows->through(fn ($row) => ['id' => $row->id, 'channel' => $row->channel, 'address' => $row->address, 'consented_at' => $row->consented_at->toIso8601String(), 'source' => $row->consent_source]);
        if ($request->wantsJson()) {
            return response()->json(['followers' => $followers]);
        }
        $canSend = $request->user()->can('send community messages');

        return Inertia::render('Tenant/Community/Followers', [
            ...app(\App\Services\Community\CommunityMessages::class)->scheduleOptions($tenant),
            'followers' => $followers, 'canManage' => true, 'canSend' => $canSend, 'communityEnabled' => $tenant->communityEnabled(),
            'messages' => $canSend ? \App\Models\CommunityMessage::query()->where('tenant_id', $tenant->id)->where('purpose', 'following_event')->latest()->paginate(25, ['*'], 'messages_page') : null,
            'canSchedule' => app(\App\Services\Community\MessageEntitlements::class)->canSchedule($tenant),
            'events' => $canSend ? \App\Models\Event::query()->where('tenant_id', $tenant->id)->published()->where('ends_at', '>=', now())->orderBy('starts_at')->limit(50)->get(['id', 'name', 'slug', 'visibility']) : [],
        ]);
    }
}
