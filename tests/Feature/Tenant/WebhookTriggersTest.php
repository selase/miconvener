<?php

declare(strict_types=1);

use App\Jobs\SendWebhookJob;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;

/**
 * The console offers eight webhook types. Until now only three were ever
 * sent -- a normal registration, a cancellation, a door check-in, a gift or
 * an uploaded payment slip reached nobody's endpoint.
 */
beforeEach(function (): void {
    Queue::fake([SendWebhookJob::class]);

    $this->tenant = Tenant::factory()->create(['slug' => 'webhook-triggers', 'isolation_mode' => 'shared']);
    $this->event = Event::factory()->published()->create(['tenant_id' => $this->tenant->id]);

    WebhookEndpoint::factory()->create([
        'tenant_id' => $this->tenant->id,
        'events' => [WebhookEndpoint::EVENT_ALL],
        'is_active' => true,
    ]);
});

function sentWebhooks(): array
{
    return Queue::pushed(SendWebhookJob::class)->map(fn (SendWebhookJob $job): string => $job->event)->values()->all();
}

test('a registration reports its creation, confirmation, check-in and cancellation', function (): void {
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'status' => EventRegistration::STATUS_PENDING_PAYMENT,
    ]);
    expect(sentWebhooks())->toBe([WebhookEndpoint::EVENT_REGISTRATION_CREATED]);

    $registration->update(['status' => EventRegistration::STATUS_CONFIRMED]);
    $registration->update(['status' => EventRegistration::STATUS_CHECKED_IN, 'checked_in_at' => now()]);
    $registration->update(['status' => EventRegistration::STATUS_CANCELLED]);

    expect(sentWebhooks())->toBe([
        WebhookEndpoint::EVENT_REGISTRATION_CREATED,
        WebhookEndpoint::EVENT_REGISTRATION_CONFIRMED,
        WebhookEndpoint::EVENT_TICKET_CHECKED_IN,
        WebhookEndpoint::EVENT_REGISTRATION_CANCELLED,
    ]);
});

test('a free registration confirmed on creation reports both', function (): void {
    EventRegistration::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    expect(sentWebhooks())->toBe([
        WebhookEndpoint::EVENT_REGISTRATION_CREATED,
        WebhookEndpoint::EVENT_REGISTRATION_CONFIRMED,
    ]);
});

test('undoing a check-in does not report the ticket as newly confirmed', function (): void {
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'status' => EventRegistration::STATUS_CHECKED_IN,
    ]);

    $registration->update(['status' => EventRegistration::STATUS_CONFIRMED]);

    expect(sentWebhooks())->not->toContain(WebhookEndpoint::EVENT_REGISTRATION_CONFIRMED);
});

test('an uploaded payment slip reports offline payment submitted', function (): void {
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'status' => EventRegistration::STATUS_PENDING_APPROVAL,
    ]);

    $registration->update(['offline_payment_proof_path' => 'offline-proofs/slip.jpg']);

    expect(sentWebhooks())->toContain(WebhookEndpoint::EVENT_OFFLINE_PAYMENT_SUBMITTED);
});

test('a contribution reports once its money arrives', function (): void {
    $contribution = EventContribution::create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'contributor_name' => 'Kofi Mensah',
        'contributor_email' => 'kofi@example.com',
        'amount' => 5000,
        'gateway_fee_amount' => 0,
        'platform_fee_amount' => 0,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => EventContribution::STATUS_PENDING_PAYMENT,
        'payment_reference' => 'CONTRIB-TRIGGER-001',
        'provider' => 'paystack',
        'is_anonymous' => false,
        'is_approved' => true,
    ]);
    expect(sentWebhooks())->toBe([]);

    $contribution->update(['status' => EventContribution::STATUS_COMPLETED, 'paid_at' => now()]);

    expect(sentWebhooks())->toBe([WebhookEndpoint::EVENT_CONTRIBUTION_RECEIVED]);
});

test('a tenant without endpoints sends nothing', function (): void {
    WebhookEndpoint::query()->delete();

    EventRegistration::factory()->create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
    ]);

    expect(sentWebhooks())->toBe([]);
});
