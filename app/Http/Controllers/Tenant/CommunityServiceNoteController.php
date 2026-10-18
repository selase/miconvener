<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Community\ServiceNoteOptionsRequest;
use App\Http\Requests\Tenant\Community\StoreServiceNoteRequest;
use App\Models\Event;
use App\Services\Community\CommunityLibrary;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class CommunityServiceNoteController extends Controller
{
    public function store(StoreServiceNoteRequest $request, string $subdomain, CommunityLibrary $library): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant() ?? abort(404);
        $item = $library->save($tenant, $request->validated(), $request->file('file'), null, (int) $request->user()->id);

        return response()->json(['item' => $library->payload($item, true)], 201);
    }

    public function options(ServiceNoteOptionsRequest $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant() ?? abort(404);
        $events = Event::query()->where('tenant_id', $tenant->id)->where('is_recurring', true)
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%'.$request->validated('search').'%'))->orderBy('name')->paginate(25, ['id', 'name'])->through(fn ($event) => ['id' => $event->id, 'title' => $event->name]);
        $occurrences = null;
        $selectedOccurrence = null;
        if ($request->filled('event')) {
            $event = Event::query()->where('tenant_id', $tenant->id)->where('is_recurring', true)->findOrFail($request->validated('event'));
            if ($request->filled('session')) {
                $selectedOccurrence = $event->sessions()->where('tenant_id', $tenant->id)->where('is_occurrence', true)->findOrFail($request->validated('session'), ['id', 'title', 'starts_at', 'occurrence_status']);
            }
            $occurrences = $event->sessions()->where('tenant_id', $tenant->id)->where('is_occurrence', true)->orderByDesc('starts_at')->orderByDesc('id')->paginate(50, ['id', 'title', 'starts_at', 'occurrence_status'], 'occurrence_page');
        }

        return response()->json(['events' => $events, 'occurrences' => $occurrences, 'selected_occurrence' => $selectedOccurrence, 'selected_event' => isset($event) ? ['id' => $event->id, 'title' => $event->title] : null, 'timezone' => \App\Models\CommunitySettings::for($tenant)->timezone]);
    }
}
