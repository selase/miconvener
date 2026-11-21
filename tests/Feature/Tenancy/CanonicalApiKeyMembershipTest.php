<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateWithApiKey;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Api\ApiKeyService;
use Illuminate\Http\Request;

it('denies a linked key when its identity or membership is no longer active', function (string $membership, string $status): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'status' => 'active']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => $status]);
    if ($membership !== 'absent') {
        $user->allTenants()->attach($tenant->id, ['status' => $membership]);
    }
    $key = app(ApiKeyService::class)->generate($tenant->id, $user->id, 'Linked');
    $request = Request::create('/api/llm/test-quota');
    $request->headers->set('X-Api-Key', $key['key']);

    $response = app(AuthenticateWithApiKey::class)->handle($request, function () {
        throw new RuntimeException('Denied key reached the endpoint');
    });

    expect($response->getStatusCode())->toBe(403);
})->with([['absent', 'active'], ['suspended', 'active'], ['revoked', 'active'], ['active', 'inactive']]);

it('sets the linked secondary member and permission team for the endpoint', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'status' => 'active']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id);
    $key = app(ApiKeyService::class)->generate($tenant->id, $user->id, 'Linked');
    $request = Request::create('/api/llm/test-quota');
    $request->headers->set('X-Api-Key', $key['key']);

    app(AuthenticateWithApiKey::class)->handle($request, function () use ($tenant, $user) {
        expect((string) getPermissionsTeamId())->toBe((string) $tenant->id);
        expect(auth()->id())->toBe($user->id);

        return response()->json(['ok' => true]);
    });
});

it('does not promote a deleted users key into an organisation service credential', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'status' => 'active']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id);
    $key = app(ApiKeyService::class)->generate($tenant->id, $user->id, 'Deleted user');
    $user->delete();
    $request = Request::create('/api/llm/test-quota');
    $request->headers->set('X-Api-Key', $key['key']);

    $response = app(AuthenticateWithApiKey::class)->handle($request, function () {
        throw new RuntimeException('Orphan key reached the endpoint');
    });

    expect($response->getStatusCode())->toBe(403);
});

it('does not override an already resolved host tenant with another tenants key', function (): void {
    $host = Tenant::factory()->create(['isolation_mode' => 'shared', 'status' => 'active']);
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'status' => 'active']);
    $user = User::factory()->create();
    $user->tenants()->attach([$host->id, $tenant->id]);
    app(App\Services\Tenancy\TenantContext::class)->setTenant($host);
    $key = app(ApiKeyService::class)->generate($tenant->id, $user->id, 'Other tenant');
    $request = Request::create('/api/llm/test-quota');
    $request->headers->set('X-Api-Key', $key['key']);

    $response = app(AuthenticateWithApiKey::class)->handle($request, function () {
        throw new RuntimeException('Mismatched key reached the endpoint');
    });

    expect($response->getStatusCode())->toBe(403);
});

it('does not ignore a restricted keys scopes on the actual llm endpoints', function (string $path, string $method): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'status' => 'active']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id);
    $key = app(ApiKeyService::class)->generate($tenant->id, $user->id, 'Restricted', ['unrelated:read']);
    $request = Request::create($path, $method);
    $request->headers->set('X-Api-Key', $key['key']);

    $response = app(AuthenticateWithApiKey::class)->handle($request, function () {
        throw new RuntimeException('Restricted key reached an unsupported endpoint');
    });

    expect($response->getStatusCode())->toBe(403);
})->with([['/api/llm/test-quota', 'GET'], ['/api/llm/test-throttle', 'GET'], ['/api/llm/chat', 'POST']]);
