<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Store\ConfirmStoreCollectionRequest;
use App\Http\Requests\Tenant\Store\StoreCollectionIndexRequest;
use App\Http\Requests\Tenant\Store\StoreDeliveryFailureRequest;
use App\Http\Requests\Tenant\Store\StoreDeliveryReturnRequest;
use App\Http\Requests\Tenant\Store\StoreDispatchRequest;
use App\Http\Requests\Tenant\Store\StorePreparationRequest;
use App\Http\Requests\Tenant\Store\StoreReadyRequest;
use App\Http\Requests\Tenant\Store\ValidateStoreCollectionRequest;
use App\Models\StoreOrder;
use App\Models\Tenant;
use App\Services\Marketplace\StoreCollectionPasses;
use App\Services\Marketplace\StoreCollectionPayload;
use App\Services\Marketplace\StoreCollections;
use App\Services\Marketplace\StoreDeliveries;
use App\Services\Marketplace\StoreFulfilment;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class StoreCollectionController extends Controller
{
    public function index(StoreCollectionIndexRequest $request, StoreCollectionPayload $payload): Response
    {
        $tenant = $this->tenant($request);
        $query = StoreOrder::query()->where('tenant_id', $tenant->id)->whereNotIn('state', ['quoted', 'expired'])->with(['lines', 'fulfilmentArrangement:id,order_id,method']);
        $states = ['preparing' => ['awaiting_preparation'], 'ready' => ['ready'], 'dispatched' => ['dispatched'], 'completed' => ['collected', 'delivered']];
        $state = $request->validated('state', 'all');
        if (isset($states[$state])) {
            $query->whereIn('fulfilment_state', $states[$state]);
        }
        if (in_array($request->validated('method'), ['collection', 'delivery'], true)) {
            $query->whereHas('fulfilmentArrangement', fn ($arrangement) => $arrangement->where('method', $request->validated('method')));
        }
        $orders = $query->latest()->paginate(20)->through(fn (StoreOrder $order): array => $payload->summary($order));
        $enabled = $tenant->status === \App\Enum\TenantStatusEnum::ACTIVE && $tenant->features()->where('feature_key', 'commerce_collection')->where('enabled', true)->exists();

        return $this->respond($request, ['orders' => $orders, 'enabled' => $enabled]);
    }

    public function show(Request $request, string $subdomain, string $order, StoreCollectionPayload $payload): Response
    {
        return $this->result($request, $this->order($request, $order), $payload);
    }

    public function ready(StoreReadyRequest $request, string $subdomain, string $order): Response
    {
        $model = $this->order($request, $order);
        app(StoreFulfilment::class)->ready($model, $request->user(), $request->validated('operation_key'));

        return $this->result($request, $model->fresh(), app(StoreCollectionPayload::class));
    }

    public function prepare(StorePreparationRequest $request, string $subdomain, string $order): Response
    {
        $model = $this->order($request, $order);
        app(StoreFulfilment::class)->prepare($model, $request->user(), $request->validated('operation_key'), $request->validated('reason'));

        return $this->result($request, $model->fresh(), app(StoreCollectionPayload::class));
    }

    public function dispatchOrder(StoreDispatchRequest $request, string $subdomain, string $order): Response
    {
        $model = $this->order($request, $order);
        app(StoreDeliveries::class)->dispatch($model, $request->user(), $request->validated('operation_key'));

        return $this->result($request, $model->fresh(), app(StoreCollectionPayload::class));
    }

    public function deliveryFailed(StoreDeliveryFailureRequest $request, string $subdomain, string $order): Response
    {
        $model = $this->order($request, $order);
        app(StoreDeliveries::class)->attemptFailed($model, $request->user(), $request->validated('operation_key'), $request->validated('reason'));

        return $this->result($request, $model->fresh(), app(StoreCollectionPayload::class));
    }

    public function returned(StoreDeliveryReturnRequest $request, string $subdomain, string $order): Response
    {
        $model = $this->order($request, $order);
        app(StoreDeliveries::class)->returned($model, $request->user(), $request->validated('operation_key'), $request->validated('reason'));

        return $this->result($request, $model->fresh(), app(StoreCollectionPayload::class));
    }

    public function validatePass(ValidateStoreCollectionRequest $request, string $subdomain, string $order): Response
    {
        $model = $this->order($request, $order);
        app(StoreCollectionPasses::class)->validate($model, $request->user(), $request->validated('credential'));

        return $this->result($request, $model->fresh(), app(StoreCollectionPayload::class));
    }

    public function confirm(ConfirmStoreCollectionRequest $request, string $subdomain, string $order): Response
    {
        $model = $this->order($request, $order);
        app(StoreCollections::class)->confirm($model, $request->user(), $request->validated('credential'), $request->validated('operation_key'));

        return $this->result($request, $model->fresh(), app(StoreCollectionPayload::class));
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant() ?? abort(403);
        $actor = $request->user() ?? abort(403);
        abort_unless((string) $actor->tenant_id === (string) $tenant->id || $actor->tenants()->whereKey($tenant->id)->exists(), 403);
        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);
            abort_unless(Gate::forUser($actor->fresh())->allows('manage store collection'), 403);
        } finally {
            setPermissionsTeamId($team);
        }

        return $tenant;
    }

    private function order(Request $request, string $id): StoreOrder
    {
        $tenant = $this->tenant($request);
        $order = StoreOrder::query()->where('tenant_id', $tenant->id)->findOrFail($id);
        app(StoreFulfilment::class)->authorize($order, $request->user(), false);

        return $order;
    }

    private function result(Request $request, StoreOrder $order, StoreCollectionPayload $payload): Response
    {
        return $this->respond($request, ['order' => $payload->detail($order), 'enabled' => app(StoreFulfilment::class)->enabled($order)]);
    }

    /** @param array<string, mixed> $data */
    private function respond(Request $request, array $data): Response
    {
        $response = $request->expectsJson() ? response()->json($data) : Inertia::render('Tenant/Store/Collections/Index', $data)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
