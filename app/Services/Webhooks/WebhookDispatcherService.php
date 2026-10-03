<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\SendWebhookJob;
use App\Models\EventContribution;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\WebhookCall;
use App\Models\WebhookEndpoint;
use RuntimeException;

final class WebhookDispatcherService
{
    /**
     * Dispatch an event to all active endpoints subscribed to it for a given tenant.
     *
     * @param  string|Tenant  $tenant  Tenant instance or tenant UUID
     * @param  string  $eventName  Event type (e.g. 'registration.confirmed')
     * @param  array<string, mixed>  $payload  Event payload
     * @return int Number of webhooks dispatched
     */
    public function dispatch(string|Tenant $tenant, string $eventName, array $payload): int
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        $endpoints = WebhookEndpoint::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        $dispatched = 0;

        foreach ($endpoints as $endpoint) {
            $subscribedEvents = (array) ($endpoint->events ?? []);

            if (empty($subscribedEvents) || in_array(WebhookEndpoint::EVENT_ALL, $subscribedEvents, true) || in_array($eventName, $subscribedEvents, true)) {
                SendWebhookJob::dispatch($endpoint, $eventName, $payload);
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * Send a test ping event to an endpoint immediately.
     */
    public function sendTestPing(WebhookEndpoint $endpoint): WebhookCall
    {
        $payload = [
            'type' => WebhookEndpoint::EVENT_PING,
            'timestamp' => now()->toIso8601String(),
            'endpoint_id' => $endpoint->id,
            'message' => 'MiConvener outgoing webhook connectivity test successful.',
            'sample_data' => [
                'event_name' => 'Sample Annual Summit',
                'event_slug' => 'sample-summit',
                'attendee_name' => 'Kofi Mensah',
                'ticket_code' => 'EVT-TEST-1234',
            ],
        ];

        $job = new SendWebhookJob($endpoint, WebhookEndpoint::EVENT_PING, $payload);

        return $job->handle();
    }

    /**
     * Retry a previous webhook delivery call.
     */
    public function retryCall(WebhookCall $call): void
    {
        $endpoint = $call->endpoint;
        if (! $endpoint) {
            throw new RuntimeException('Cannot retry call: parent endpoint no longer exists.');
        }

        SendWebhookJob::dispatch($endpoint, (string) $call->event_name, (array) $call->payload, $call->id);
    }

    /**
     * Dispatch registration confirmed event.
     */
    public function dispatchRegistrationConfirmed(EventRegistration $registration): int
    {
        $event = $registration->event;
        if (! $event) {
            return 0;
        }

        return $this->dispatch($event->tenant_id, WebhookEndpoint::EVENT_REGISTRATION_CONFIRMED, [
            'event_id' => $event->id,
            'event_name' => $event->title,
            'registration_id' => $registration->id,
            'ticket_code' => $registration->ticket_code,
            'attendee_name' => $registration->name,
            'attendee_email' => $registration->email,
            'attendee_phone' => $registration->phone,
            'status' => $registration->status,
            'ticket_type_id' => $registration->ticket_type_id,
            'confirmed_at' => $registration->confirmed_at?->toIso8601String() ?? now()->toIso8601String(),
        ]);
    }

    /**
     * Dispatch ticket checked in event.
     */
    public function dispatchTicketCheckedIn(EventRegistration $registration): int
    {
        $event = $registration->event;
        if (! $event) {
            return 0;
        }

        return $this->dispatch($event->tenant_id, WebhookEndpoint::EVENT_TICKET_CHECKED_IN, [
            'event_id' => $event->id,
            'event_name' => $event->title,
            'registration_id' => $registration->id,
            'ticket_code' => $registration->ticket_code,
            'attendee_name' => $registration->name,
            'attendee_email' => $registration->email,
            'checked_in_at' => $registration->checked_in_at?->toIso8601String() ?? now()->toIso8601String(),
        ]);
    }

    /**
     * Dispatch contribution received event.
     */
    public function dispatchContributionReceived(EventContribution $contribution): int
    {
        return $this->dispatch($contribution->tenant_id, WebhookEndpoint::EVENT_CONTRIBUTION_RECEIVED, [
            'contribution_id' => $contribution->id,
            'event_id' => $contribution->event_id,
            'contributor_name' => $contribution->is_anonymous ? 'Anonymous' : $contribution->contributor_name,
            'contributor_email' => $contribution->contributor_email,
            'amount_pesewas' => $contribution->amount_pesewas,
            'currency' => $contribution->currency,
            'payment_reference' => $contribution->payment_reference,
            'status' => $contribution->status,
            'tribute_message' => $contribution->tribute_message,
            'completed_at' => $contribution->completed_at?->toIso8601String() ?? now()->toIso8601String(),
        ]);
    }

    /**
     * Dispatch offline payment approved event.
     */
    public function dispatchOfflinePaymentApproved(EventRegistration $registration): int
    {
        $event = $registration->event;
        if (! $event) {
            return 0;
        }

        return $this->dispatch($event->tenant_id, WebhookEndpoint::EVENT_OFFLINE_PAYMENT_APPROVED, [
            'event_id' => $event->id,
            'registration_id' => $registration->id,
            'ticket_code' => $registration->ticket_code,
            'attendee_name' => $registration->name,
            'attendee_email' => $registration->email,
            'amount_pesewas' => $registration->amount_pesewas,
            'approved_at' => now()->toIso8601String(),
        ]);
    }
}
