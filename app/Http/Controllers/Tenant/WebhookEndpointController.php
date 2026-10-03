<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreWebhookEndpointRequest;
use App\Http\Requests\Tenant\UpdateWebhookEndpointRequest;
use App\Models\WebhookCall;
use App\Models\WebhookEndpoint;
use App\Services\Tenancy\TenantContext;
use App\Services\Webhooks\WebhookDispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WebhookEndpointController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WebhookDispatcherService $dispatcher
    ) {}

    /**
     * Display the outgoing webhooks configuration screen.
     */
    public function index(string $subdomain): Response
    {
        $this->authorize('manage organization settings');

        $tenant = $this->tenantContext->getTenant();

        $endpoints = WebhookEndpoint::where('tenant_id', $tenant->id)
            ->withCount('calls')
            ->with(['calls' => fn ($q) => $q->latest('created_at')->limit(3)])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (WebhookEndpoint $ep): array => [
                'id' => $ep->id,
                'name' => $ep->name,
                'description' => $ep->description,
                'url' => $ep->url,
                'events' => $ep->events ?? [],
                'is_active' => (bool) $ep->is_active,
                'created_at' => $ep->created_at?->format('M j, Y H:i'),
                'calls_count' => $ep->calls_count,
                'recent_calls' => $ep->calls->map(fn (WebhookCall $call): array => [
                    'id' => $call->id,
                    'event_name' => $call->event_name,
                    'status' => $call->status,
                    'duration_ms' => $call->duration_ms,
                    'is_successful' => $call->isSuccessful(),
                    'created_at' => $call->created_at?->format('H:i:s'),
                    'formatted_time' => $call->created_at?->diffForHumans(),
                ])->values()->all(),
            ]);

        $totalCalls = WebhookCall::where('tenant_id', $tenant->id)->count();
        $successfulCalls = WebhookCall::where('tenant_id', $tenant->id)
            ->whereBetween('status', [200, 299])
            ->count();
        $failedCalls = WebhookCall::where('tenant_id', $tenant->id)
            ->where(function ($q): void {
                $q->whereNull('status')
                    ->orWhere('status', '>=', 400)
                    ->orWhereNotNull('exception');
            })
            ->count();
        $avgDuration = (int) round((float) (WebhookCall::where('tenant_id', $tenant->id)->whereNotNull('duration_ms')->avg('duration_ms') ?? 0));

        return Inertia::render('Tenant/Settings/Webhooks/Index', [
            'endpoints' => $endpoints,
            'availableEvents' => WebhookEndpoint::AVAILABLE_EVENTS,
            'stats' => [
                'total_endpoints' => $endpoints->count(),
                'active_endpoints' => $endpoints->where('is_active', true)->count(),
                'total_deliveries' => $totalCalls,
                'success_rate' => $totalCalls > 0 ? (int) round(($successfulCalls / $totalCalls) * 100) : 100,
                'failed_deliveries' => $failedCalls,
                'avg_duration_ms' => $avgDuration,
            ],
            'flash' => [
                'newSecret' => session('new_secret'),
                'endpointId' => session('endpoint_id'),
            ],
        ]);
    }

    /**
     * Store a newly created webhook endpoint.
     */
    public function store(StoreWebhookEndpointRequest $request, string $subdomain): RedirectResponse
    {
        $this->authorize('manage organization settings');

        $tenant = $this->tenantContext->getTenant();
        $secret = WebhookEndpoint::generateSecret();

        $endpoint = WebhookEndpoint::create([
            'tenant_id' => $tenant->id,
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'url' => $request->validated('url'),
            'secret' => $secret,
            'events' => $request->validated('events'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->back()
            ->with('success', "Webhook endpoint '{$endpoint->name}' created successfully.")
            ->with('new_secret', $secret)
            ->with('endpoint_id', $endpoint->id);
    }

    /**
     * Update an existing webhook endpoint.
     */
    public function update(UpdateWebhookEndpointRequest $request, string $subdomain, WebhookEndpoint $endpoint): RedirectResponse
    {
        $this->authorize('manage organization settings');

        $tenant = $this->tenantContext->getTenant();
        if ($endpoint->tenant_id !== $tenant->id) {
            abort(404);
        }

        $endpoint->update([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'url' => $request->validated('url'),
            'events' => $request->validated('events'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', "Webhook endpoint '{$endpoint->name}' updated.");
    }

    /**
     * Delete a webhook endpoint and its calls.
     */
    public function destroy(string $subdomain, WebhookEndpoint $endpoint): RedirectResponse
    {
        $this->authorize('manage organization settings');

        $tenant = $this->tenantContext->getTenant();
        if ($endpoint->tenant_id !== $tenant->id) {
            abort(404);
        }

        $endpoint->calls()->delete();
        $endpoint->delete();

        return redirect()->back()->with('success', 'Webhook endpoint removed.');
    }

    /**
     * Rotate the signing secret for an endpoint.
     */
    public function rotateSecret(string $subdomain, WebhookEndpoint $endpoint): RedirectResponse
    {
        $this->authorize('manage organization settings');

        $tenant = $this->tenantContext->getTenant();
        if ($endpoint->tenant_id !== $tenant->id) {
            abort(404);
        }

        $newSecret = $endpoint->rotateSecret();

        return redirect()->back()
            ->with('success', 'Signing secret rotated successfully. Update your destination verification service immediately.')
            ->with('new_secret', $newSecret)
            ->with('endpoint_id', $endpoint->id);
    }

    /**
     * Dispatch a test ping event to the endpoint.
     */
    public function test(string $subdomain, WebhookEndpoint $endpoint): RedirectResponse
    {
        $this->authorize('manage organization settings');

        $tenant = $this->tenantContext->getTenant();
        if ($endpoint->tenant_id !== $tenant->id) {
            abort(404);
        }

        $call = $this->dispatcher->sendTestPing($endpoint);

        $status = $call->status;
        $msg = $call->isSuccessful()
            ? "Test ping successful! Destination returned HTTP {$status} in {$call->duration_ms}ms."
            : "Test ping completed with HTTP {$status} (".($call->exception ?? 'Non-2xx response').'). Check delivery logs.';

        return redirect()->back()->with('success', $msg);
    }

    /**
     * Fetch paginated delivery calls for an endpoint.
     */
    public function calls(Request $request, string $subdomain, WebhookEndpoint $endpoint): JsonResponse
    {
        $this->authorize('manage organization settings');

        $tenant = $this->tenantContext->getTenant();
        if ($endpoint->tenant_id !== $tenant->id) {
            abort(404);
        }

        $calls = $endpoint->calls()
            ->orderByDesc('created_at')
            ->paginate(15)
            ->through(fn (WebhookCall $call): array => [
                'id' => $call->id,
                'event_name' => $call->event_name,
                'status' => $call->status,
                'duration_ms' => $call->duration_ms,
                'payload' => $call->payload,
                'response' => $call->response,
                'exception' => $call->exception,
                'is_successful' => $call->isSuccessful(),
                'is_failed' => $call->isFailed(),
                'created_at' => $call->created_at?->format('M j, Y H:i:s'),
                'formatted_time' => $call->created_at?->diffForHumans(),
            ]);

        return response()->json($calls);
    }

    /**
     * Retry a previous webhook delivery call.
     */
    public function retryCall(string $subdomain, WebhookCall $call): RedirectResponse
    {
        $this->authorize('manage organization settings');

        $tenant = $this->tenantContext->getTenant();
        if ($call->tenant_id !== $tenant->id) {
            abort(404);
        }

        $this->dispatcher->retryCall($call);

        return redirect()->back()->with('success', 'Webhook delivery queued for replay.');
    }
}
