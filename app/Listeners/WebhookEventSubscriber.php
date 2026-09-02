<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\SendWebhookJob;
use App\Models\WebhookEndpoint;
use Illuminate\Events\Dispatcher;

final class WebhookEventSubscriber
{
    /**
     * Handle Invoice Issued events.
     */
    public function handleInvoiceIssued($event): void
    {
        if (! isset($event->invoice)) {
            return;
        }

        $invoice = $event->invoice;
        $tenantId = $invoice->tenant_id;

        $this->dispatchWebhooks($tenantId, 'invoice.issued', [
            'invoice_id' => $invoice->number,
            'amount' => $invoice->total,
            'status' => $invoice->status,
        ]);
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(
            \App\Events\InvoiceIssued::class,
            self::handleInvoiceIssued(...)
        );
    }

    /**
     * Generic dispatcher — finds active endpoints for a tenant that subscribe to the event.
     */
    private function dispatchWebhooks(string $tenantId, string $eventName, array $payload): void
    {
        $endpoints = WebhookEndpoint::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        foreach ($endpoints as $endpoint) {
            $subscribedEvents = $endpoint->events ?? [];

            if (empty($subscribedEvents) || in_array('*', $subscribedEvents) || in_array($eventName, $subscribedEvents)) {
                SendWebhookJob::dispatch($endpoint, $eventName, $payload);
            }
        }
    }
}
