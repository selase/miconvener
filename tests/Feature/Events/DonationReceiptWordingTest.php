<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventContribution;
use App\Models\Tenant;

/**
 * A donation receipt is a record of a payment. It must not claim checks that
 * nobody performs (a "verified" beneficiary or record) or a tax status it
 * does not have.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
});

function renderedReceipt(array $contribution = []): string
{
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'name' => 'Grace Chapel']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Harvest Thanksgiving']);
    $model = EventContribution::withoutGlobalScopes()->create(array_merge([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Ama Mensah',
        'contributor_email' => 'ama@example.com',
        'amount' => 5000,
        'net_amount' => 4750,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'CONTRIB-TEST-1',
        'paid_at' => now(),
    ], $contribution));

    return view('pdf.donation-receipt', [
        'contribution' => $model,
        'event' => $event,
        'tenant' => $tenant,
        'brandDataUri' => null,
    ])->render();
}

test('the receipt states the payment plainly, without verification or tax claims', function (): void {
    $html = renderedReceipt();

    expect($html)->toContain('Harvest Thanksgiving')
        ->toContain('Grace Chapel')
        ->toContain('CONTRIB-TEST-1')
        ->toContain('not a tax receipt')
        ->not->toContain('Verified')
        ->not->toContain('Official')
        ->not->toContain('official')
        ->not->toContain('Taxes')
        ->not->toContain('No commercial goods')
        ->not->toContain('MiConvener Payments');
});

test('an anonymous donor still sees their own name, with how it shows publicly', function (): void {
    $html = renderedReceipt(['is_anonymous' => true]);

    expect($html)->toContain('Ama Mensah')
        ->toContain('anonymous on the event page')
        ->not->toContain('Record Verified');
});
