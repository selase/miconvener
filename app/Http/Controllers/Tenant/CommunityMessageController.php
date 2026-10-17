<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Community\GroupMemberOptionsRequest;
use App\Http\Requests\Tenant\Community\PreviewMessageRequest;
use App\Http\Requests\Tenant\Community\StoreMessageRequest;
use App\Models\CommunityGroup;
use App\Models\CommunityMessage;
use App\Models\CommunitySettings;
use App\Models\Tenant;
use App\Services\Community\CommunityMessages;
use App\Services\Community\MessageEntitlements;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CommunityMessageController extends Controller
{
    public function index(Request $request, CommunityMessages $service): JsonResponse|Response
    {
        $tenant = $this->tenant();
        $service->authorize($tenant, $request->user(), false, true);
        $messages = CommunityMessage::query()->where('tenant_id', $tenant->id)
            ->when(! $request->user()->can('read finance'), fn ($query) => $query->where('audience->kind', '!=', 'arrears'))
            ->withCount(['deliveries', 'deliveries as accepted_count' => fn ($q) => $q->where('status', 'accepted'), 'deliveries as unknown_count' => fn ($q) => $q->where('status', 'unknown')])->latest()->paginate(30);
        if ($request->wantsJson()) {
            return response()->json(['messages' => $messages]);
        }
        $entitlements = app(MessageEntitlements::class);
        $timezone = CommunitySettings::for($tenant)->timezone;
        $zone = new DateTimeZone($timezone);
        $now = CarbonImmutable::now();
        $offsets = collect($zone->getTransitions($now->subYear()->timestamp, $now->addYear()->timestamp) ?: [])
            ->pluck('offset')->push($zone->getOffset($now))->unique()->map(fn (int $seconds): string => sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60)))->values();

        return Inertia::render('Tenant/Community/Messages', [
            'reconciliationUrl' => $request->user()->can('access-superadmin-dashboard') ? mb_rtrim((string) config('app.url'), '/').route('admin.messages.reconciliation.index', ['tenant_id' => $tenant->id], false) : null,
            'messages' => $messages, 'timezone' => $timezone, 'offsets' => $offsets,
            'capabilities' => ['enabled' => $tenant->featureEnabled('community_messages'), 'followers' => $tenant->followingEnabled(), 'schedule' => $entitlements->canSchedule($tenant), 'groups' => $entitlements->canMessageGroups($tenant), 'finance' => $request->user()->can('read finance')],
        ]);
    }

    public function groups(GroupMemberOptionsRequest $request, CommunityMessages $service): JsonResponse
    {
        $tenant = $this->tenant();
        $service->authorize($tenant, $request->user());
        $search = (string) $request->validated('search', '');

        return response()->json(['groups' => CommunityGroup::query()->where('tenant_id', $tenant->id)->whereNull('archived_at')
            ->select(['id', 'name'])->when($search !== '', fn ($query) => $query->where('name', 'ilike', '%'.$search.'%'))->orderBy('name')->paginate(30)->withQueryString()]);
    }

    public function update(StoreMessageRequest $request, string $subdomain, string $message, CommunityMessages $service): JsonResponse
    {
        $tenant = $this->tenant();
        $model = CommunityMessage::query()->where('tenant_id', $tenant->id)
            ->when(! $request->user()->can('read finance'), fn ($query) => $query->where('audience->kind', '!=', 'arrears'))->findOrFail($message);

        return response()->json(['message' => $service->queue($tenant, $request->user(), $request->validated(), $model)]);
    }

    public function show(Request $request, string $subdomain, string $message, CommunityMessages $service): JsonResponse
    {
        $tenant = $this->tenant();
        $service->authorize($tenant, $request->user(), false, true);
        $model = CommunityMessage::query()->where('tenant_id', $tenant->id)
            ->when(! $request->user()->can('read finance'), fn ($query) => $query->where('audience->kind', '!=', 'arrears'))->findOrFail($message);

        return response()->json(['message' => $model, 'sending_enabled' => $tenant->featureEnabled('community_messages'), 'inbox' => app(\App\Services\Community\InboxWriter::class)->statistics($model), 'deliveries' => $model->deliveries()->select(['id', 'channel', 'status', 'reason', 'provider_reference'])->paginate(50)]);
    }

    public function preview(PreviewMessageRequest $request, CommunityMessages $service): JsonResponse
    {
        return response()->json($service->preview($this->tenant(), $request->user(), $request->validated()));
    }

    public function store(StoreMessageRequest $request, CommunityMessages $service): JsonResponse
    {
        $model = $service->queue($this->tenant(), $request->user(), $request->validated());

        return response()->json(['message' => $model], $model->wasRecentlyCreated ? 201 : 200);
    }

    public function cancel(Request $request, string $subdomain, string $message, CommunityMessages $service): JsonResponse
    {
        $tenant = $this->tenant();
        $model = CommunityMessage::query()->where('tenant_id', $tenant->id)
            ->when(! $request->user()->can('read finance'), fn ($query) => $query->where('audience->kind', '!=', 'arrears'))->findOrFail($message);
        $service->cancel($tenant, $request->user(), $model);

        return response()->json(['message' => $model->fresh()]);
    }

    public function withdraw(\App\Http\Requests\Tenant\Community\WithdrawInboxMessageRequest $request, string $subdomain, string $message, CommunityMessages $service): JsonResponse
    {
        $model = CommunityMessage::withoutGlobalScopes()->where('tenant_id', $this->tenant()->id)->findOrFail($message);
        $service->withdraw($this->tenant(), $request->user(), $model, $request->validated('reason'));

        return response()->json(['message' => 'Announcement removed from in-app inboxes. Existing email and SMS are unaffected.']);
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(403);
    }
}
