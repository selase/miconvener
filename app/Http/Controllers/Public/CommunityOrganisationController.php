<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enum\TenantStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Tenant;
use App\Services\Community\CommunityPublicContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class CommunityOrganisationController extends Controller
{
    public function show(Request $request, string $subdomain): Response
    {
        $tenant = Tenant::where('slug', $subdomain)->where('status', TenantStatusEnum::ACTIVE)->firstOrFail();
        abort_unless($tenant->followingEnabled() || $tenant->communityEnabled(), 404);
        $context = app(CommunityPublicContext::class)->for($tenant, $request->session());
        $events = Event::query()->where('tenant_id', $tenant->id)->published()->where('visibility', Event::VISIBILITY_PUBLIC)->where('ends_at', '>=', now())->orderBy('starts_at')->limit(12)->get()
            ->map(fn (Event $event): array => ['id' => $event->id, 'name' => $event->name, 'starts_at' => $event->starts_at->toIso8601String(), 'timezone' => $event->timezone, 'url' => route('public.events.show', ['subdomain' => $tenant->slug, 'event' => $event->slug])])->values();
        $response = Inertia::render('Public/Community/Organisation', [...$context, 'storeUrl' => app(\App\Services\Marketplace\CommerceCatalogRollout::class)->enabled($tenant) ? route('marketplace.storefront.show', ['merchant_slug' => $tenant->shop->slug]) : null, 'givingUrl' => app(\App\Services\Giving\GivingRollout::class)->enabled($tenant) ? route('public.giving.index', ['subdomain' => $tenant->slug]) : null, 'events' => $events, 'joinRequests' => $context['identity'] === null ? [] : app(\App\Services\Community\CommunityHistory::class)->requests($tenant, $request->session())['joins']])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
