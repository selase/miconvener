<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Community\FollowingEventAnnouncementRequest;
use App\Models\CommunityMessage;
use App\Models\Event;
use App\Models\Tenant;
use App\Services\Community\CommunityMessages;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FollowingEventAnnouncementController extends Controller
{
    public function index(Request $request, CommunityMessages $service): JsonResponse
    {
        $tenant = $this->tenant();
        $service->authorize($tenant, $request->user(), true);

        return response()->json(['messages' => CommunityMessage::query()->where('tenant_id', $tenant->id)->where('purpose', 'following_event')->latest()->paginate(25)]);
    }

    public function preview(FollowingEventAnnouncementRequest $request, CommunityMessages $service): JsonResponse
    {
        $tenant = $this->tenant();
        $event = $this->event($request, $service);
        $input = $this->input($request, $event);

        return response()->json([...$service->preview($tenant, $request->user(), $input, $event), 'event' => ['name' => $event->name, 'url' => $this->eventUrl($event)], 'subject' => $input['subject'], 'body' => $input['body']]);
    }

    public function store(FollowingEventAnnouncementRequest $request, CommunityMessages $service): JsonResponse
    {
        $event = $this->event($request, $service);
        $message = $service->queue($this->tenant(), $request->user(), $this->input($request, $event), null, $event);

        return response()->json(['message' => $message], $message->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $subdomain, string $message, CommunityMessages $service): JsonResponse
    {
        $service->authorize($this->tenant(), $request->user(), true);
        $model = $this->find($message);

        return response()->json(['message' => $model, 'deliveries' => $model->deliveries()->select(['id', 'channel', 'status', 'reason', 'provider_reference'])->paginate(50)]);
    }

    public function cancel(Request $request, string $subdomain, string $message, CommunityMessages $service): JsonResponse
    {
        $model = $this->find($message);
        $service->cancel($this->tenant(), $request->user(), $model, true);

        return response()->json(['message' => $model->fresh()]);
    }

    private function event(Request $request, CommunityMessages $service): Event
    {
        $event = Event::query()->where('tenant_id', $this->tenant()->id)->findOrFail($request->input('source_event_id'));
        $service->assertFollowingEvent($this->tenant(), $event);

        return $event;
    }

    private function find(string $id): CommunityMessage
    {
        return CommunityMessage::query()->where('tenant_id', $this->tenant()->id)->where('purpose', 'following_event')->findOrFail($id);
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(403);
    }

    private function eventUrl(Event $event): string
    {
        return route('public.events.show', ['subdomain' => $this->tenant()->slug, 'event' => $event->slug]);
    }

    private function input(FollowingEventAnnouncementRequest $request, Event $event): array
    {
        return [...$request->validated(), 'audience' => ['kind' => 'followers'], 'body' => $request->string('body')->toString()."\n\n".$this->eventUrl($event)];
    }
}
