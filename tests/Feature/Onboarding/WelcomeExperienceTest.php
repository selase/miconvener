<?php

declare(strict_types=1);

use App\Enum\TenantStatusEnum;
use App\Models\User;

/*
 * The onboarding wizard lives on the tenant's own domain. A second copy on
 * the root domain, a Metronic page, has been removed: it skipped the
 * Enterprise-only logo gate and was reachable by URL alone.
 */
beforeEach(function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('Org Admin');

    $this->tenant = setActiveTenantForTest($this->user, [
        'status' => TenantStatusEnum::ACTIVE,
        'onboarding_completed_at' => null,
    ]);

    $this->host = $this->tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');
});

test('it can visit the welcome page', function () {
    $this->actingAs($this->user)
        ->get("http://{$this->host}/onboarding/wizard", ['HTTP_HOST' => $this->host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Onboarding/Wizard')
            ->where('org.name', $this->tenant->name));
});

test('it can update branding and redirect to dashboard', function () {
    $this->actingAs($this->user)
        ->post("http://{$this->host}/onboarding/branding", ['name' => 'New Org Name'], ['HTTP_HOST' => $this->host])
        ->assertRedirect(route('tenant.dashboard', ['subdomain' => $this->tenant->slug]));

    expect($this->tenant->refresh()->name)->toBe('New Org Name');
});

test('it can skip to dashboard and mark as finished', function () {
    $this->actingAs($this->user)
        ->post("http://{$this->host}/onboarding/finish", [], ['HTTP_HOST' => $this->host])
        ->assertRedirect(route('tenant.dashboard', ['subdomain' => $this->tenant->slug]));

    expect($this->tenant->refresh()->onboarding_completed_at)->not->toBeNull();
});

test('the removed root-domain wizard no longer exists', function () {
    $this->actingAs($this->user);

    $this->get('/onboarding/wizard')->assertNotFound();
    $this->post('/onboarding/branding', ['name' => 'Hijacked'])->assertNotFound();
    $this->post('/onboarding/finish')->assertNotFound();

    expect($this->tenant->refresh()->name)->not->toBe('Hijacked');
});
