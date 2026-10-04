<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventContribution;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Webhooks\WebhookDispatcherService;

/**
 * Every webhook payload reads real attributes.
 *
 * The models run strict, so reading a column that does not exist throws -- and
 * these payloads read event->title, registration->name, ->amount_pesewas and
 * ->confirmed_at, none of which exist. Approving an offline payment failed with
 * a 500 every time. Two of the four webhooks are not wired to anything yet, so
 * they are built here directly rather than waiting for the day they are.
 */
function webhookSubjects(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'webhook-payloads', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'name' => 'Payload Summit']);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Ama Owusu',
        'email' => 'ama@example.com',
        'status' => EventRegistration::STATUS_CHECKED_IN,
        'checked_in_at' => now(),
        'amount' => 20000,
    ]);
    $contribution = EventContribution::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'contributor_name' => 'Kofi Mensah',
        'contributor_email' => 'kofi@example.com',
        'amount' => 5000,
        'gateway_fee_amount' => 100,
        'platform_fee_amount' => 150,
        'net_amount' => 4750,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_COMPLETED,
        'payment_reference' => 'CONTRIB-WEBHOOK-001',
        'provider' => 'paystack',
        'is_anonymous' => false,
        'is_approved' => true,
        'paid_at' => now()->subHour(),
    ]);

    return [EventRegistration::query()->findOrFail($registration->id), EventContribution::query()->findOrFail($contribution->id)];
}

test('every webhook builds its payload from attributes that exist', function (): void {
    [$registration, $contribution] = webhookSubjects();
    $webhooks = app(WebhookDispatcherService::class);

    // Each returns how many endpoints it reached -- none are configured, so 0.
    // What matters is that none of them throws building the payload.
    expect($webhooks->dispatchRegistrationConfirmed($registration))->toBe(0)
        ->and($webhooks->dispatchTicketCheckedIn($registration))->toBe(0)
        ->and($webhooks->dispatchOfflinePaymentApproved($registration))->toBe(0)
        ->and($webhooks->dispatchContributionReceived($contribution))->toBe(0);
});
