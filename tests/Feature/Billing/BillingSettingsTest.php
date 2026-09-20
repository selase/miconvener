<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * The billing details an invoice is addressed to. Access is covered in
 * PaymentsAndFinanceAccessTest; this covers what the page shows and saves.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $this->tenant = Tenant::factory()->create([
        'slug' => 'billing-details',
        'isolation_mode' => 'shared',
        'email' => 'org@example.com',
        'address' => '12 Independence Ave, Accra',
    ]);
    setPermissionsTeamId($this->tenant->id);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'status' => User::STATUS_ACTIVE]);
    $this->owner->assignRole('Org Superadmin');
    $this->tenant->users()->attach($this->owner->id);
    $this->host = 'billing-details.'.mb_ltrim((string) config('session.domain'), '.');
});

test('the page falls back to the organization email and address until billing details are set', function () {
    $this->actingAs($this->owner)
        ->get("http://{$this->host}/settings/billing", ['HTTP_HOST' => $this->host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Settings/Billing')
            ->where('billingEmail', 'org@example.com')
            ->where('billingAddress', '12 Independence Ave, Accra')
            ->where('taxId', ''));
});

test('saving billing details keeps them, and leaves the rest of the tenant meta alone', function () {
    $this->tenant->update(['meta' => ['primary_color' => '#00897c']]);

    $this->actingAs($this->owner)
        ->post("http://{$this->host}/settings/billing", [
            'billing_email' => 'accounts@example.com',
            'tax_id' => 'C0012345678',
            'billing_address' => 'Finance Office, 12 Independence Ave, Accra',
        ], ['HTTP_HOST' => $this->host])
        ->assertRedirect()
        ->assertSessionHas('success');

    $meta = $this->tenant->fresh()->meta;
    expect($meta['billing_email'])->toBe('accounts@example.com');
    expect($meta['tax_id'])->toBe('C0012345678');
    // Branding lives in the same column: saving an invoice address must not drop it.
    expect($meta['primary_color'])->toBe('#00897c');

    $this->actingAs($this->owner)
        ->get("http://{$this->host}/settings/billing", ['HTTP_HOST' => $this->host])
        ->assertInertia(fn ($page) => $page->where('billingEmail', 'accounts@example.com'));
});

test('an invoice has to be addressed somewhere, so the email is required and checked', function () {
    $this->actingAs($this->owner)
        ->post("http://{$this->host}/settings/billing", [
            'billing_email' => 'not-an-email',
        ], ['HTTP_HOST' => $this->host])
        ->assertSessionHasErrors('billing_email');

    expect($this->tenant->fresh()->meta['billing_email'] ?? null)->toBeNull();
});
