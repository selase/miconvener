<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Enum\TenantStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\StoreWorkspaceRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pricing\VisitorCurrency;
use App\Services\Tenancy\CreateWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }
        abort_unless(in_array($user->status ?? null, [null, User::STATUS_ACTIVE], true), 403);
        $workspaces = $user->tenants()->orderBy('name')->get()
            ->map(fn (Tenant $tenant): array => [
                'id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug,
                'available' => $tenant->status === TenantStatusEnum::ACTIVE,
                'url' => $tenant->status === TenantStatusEnum::ACTIVE ? $tenant->url('/dashboard') : null,
            ])->values();
        $pending = Tenant::query()->where('meta->workspace_creation->user_id', (string) $user->id)
            ->where('meta->workspace_creation->state', '!=', 'ready')->get()
            ->map(fn (Tenant $tenant): array => [
                'name' => $tenant->name, 'state' => $tenant->meta['workspace_creation']['state'],
                'retry_url' => route('workspaces.create', ['request_key' => $tenant->meta['workspace_creation']['request_key']]),
            ]);

        return Inertia::render('Workspaces/Index', ['tenant' => null, 'workspaces' => $workspaces, 'pendingWorkspaces' => $pending, 'canCreateOrganisation' => (bool) config('organisation_access.enabled', false)]);
    }

    public function create(Request $request, CreateWorkspace $creator): Response|RedirectResponse
    {
        abort_unless((bool) config('organisation_access.enabled', false), 503, 'Organisation creation temporarily unavailable');
        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }
        abort_unless(in_array($request->user()->status ?? null, [null, User::STATUS_ACTIVE], true), 403);
        $existing = is_string($request->query('request_key')) ? $creator->existing($request->user(), $request->query('request_key')) : null;

        return Inertia::render('Workspaces/Create', ['tenant' => null, 'requestKey' => $existing?->meta['workspace_creation']['request_key'] ?? (string) Str::uuid(),
            'defaults' => ['organization_name' => $existing->name ?? '', 'slug' => $existing->slug ?? ''], 'recovering' => $existing !== null]);
    }

    public function store(StoreWorkspaceRequest $request, CreateWorkspace $creator, VisitorCurrency $currency): \Symfony\Component\HttpFoundation\Response
    {
        $tenant = $creator->create($request->user(), $request->workspaceInput(), $currency->forVisitor($request));
        $request->session()->put('active_tenant_id', $tenant->id);

        return Inertia::location(route('tenant.onboarding.wizard', ['subdomain' => $tenant->slug]));
    }
}
