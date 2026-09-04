<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\User;

test('tenant dashboard renders the Inertia component with tenant and checklist data', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user, ['onboarding_completed_at' => null]);

    $this->actingAs($user)
        ->get(route('tenant.dashboard', ['subdomain' => $tenant->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Dashboard')
            ->where('tenant.name', $tenant->name)
            ->where('checklist.onboarding', false)
            ->where('checklist.team', false)
            ->has('links.branding')
            ->has('links.team')
            ->has('links.finishOnboarding')
        );
});

test('tenant dashboard checklist reflects a completed onboarding state', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user, ['onboarding_completed_at' => now()]);

    $this->actingAs($user)
        ->get(route('tenant.dashboard', ['subdomain' => $tenant->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Dashboard')
            ->where('checklist.onboarding', true)
        );
});

test('guests are redirected away from the tenant dashboard', function () {
    $tenant = setActiveTenantForTest();

    $this->get(route('tenant.dashboard', ['subdomain' => $tenant->slug]))
        ->assertRedirect(route('login'));
});
