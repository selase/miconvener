<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateEventHostsRequest;
use App\Models\Event;
use App\Services\Events\EventHosts;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class EventHostController extends Controller
{
    public function update(UpdateEventHostsRequest $request, string $subdomain, string $event, EventHosts $hosts): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        $eventModel = Event::query()->where('tenant_id', $tenant?->id)->findOrFail($event);
        $data = $request->validated();
        $saved = $hosts->replace($eventModel, (int) $data['version'], $data['hosts']);

        return response()->json(['version' => (int) $saved->hosts_version, 'hosts' => $hosts->payload($saved)])->header('Cache-Control', 'private, no-store');
    }
}
