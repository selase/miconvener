<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * The tenant-facing invoice routes live on the root domain (no {subdomain}
 * segment), resolving the tenant from session via TenantContext -- the same
 * pattern billing.index uses.
 *
 * A Route::resource() here registered 'index' as well as 'show', but
 * InvoiceController has no index() method: requesting billing.invoices.index
 * fatal-errored (500). Nothing ever linked to it.
 */
beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
});

function invoiceOwner(): array
{
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user, ['package_id' => Package::query()->where('slug', 'growth')->value('id')]);
    $user->assignRole('Org Superadmin');

    return [$user, $tenant];
}

test('the owner can view and download an issued invoice from the root-domain route', function (): void {
    [$user, $tenant] = invoiceOwner();
    $invoice = Invoice::factory()->create(['tenant_id' => $tenant->id, 'status' => Invoice::STATUS_ISSUED]);

    $this->actingAs($user);

    $this->get(route('billing.invoices.show', $invoice->id))
        ->assertOk()
        ->assertViewIs('billing.invoices.show');

    $this->get(route('billing.invoices.download', $invoice->id))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('a draft invoice is never shown to the tenant', function (): void {
    [$user, $tenant] = invoiceOwner();
    $invoice = Invoice::factory()->create(['tenant_id' => $tenant->id, 'status' => Invoice::STATUS_DRAFT]);

    $this->actingAs($user)
        ->get(route('billing.invoices.show', $invoice->id))
        ->assertNotFound();
});

test('billing/invoices with no id -- the removed index action -- 404s instead of 500ing', function (): void {
    [$user] = invoiceOwner();

    $this->actingAs($user)
        ->get('/billing/invoices')
        ->assertNotFound();
});
